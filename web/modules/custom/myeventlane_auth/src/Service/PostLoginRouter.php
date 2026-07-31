<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_auth\PostLoginDecision;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_core\Service\RouteHelper;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Drupal\user\UserDataInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decides the next internal route after login using existing MEL onboarding data.
 *
 * Controllers must not contain branching; all post-login routing lives here.
 */
final class PostLoginRouter {

  public function __construct(
    private readonly OnboardingManager $onboardingManager,
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
    private readonly IdentityIntentResolver $identityIntentResolver,
    private readonly PostLoginRoutePolicy $routePolicy,
    private readonly LoggerChannelInterface $logger,
    private readonly RouteHelper $routeHelper,
    private readonly UserDataInterface $userData,
  ) {}

  /**
   * Resolves the next destination for an authenticated account.
   */
  public function resolveDestination(AccountInterface $account, Request $request): Url {
    $uid = (int) $account->id();
    $intent = $this->identityIntentResolver->resolveFromRequest($request);

    if (myeventlane_auth_is_password_reset_flow($request)) {
      if ($uid > 0 && !$account->isAnonymous()) {
        return $this->safeUrlPasswordResetContinue($account, $request, $uid, $intent);
      }
    }

    if ($uid <= 0 || $account->isAnonymous()) {
      $this->logger->warning(
        'PostLoginRouter: invalid session uid=@uid intent=@intent decision=@decision route=@route',
        $this->routerLogContext(
          $uid,
          $intent,
          PostLoginDecision::ANONYMOUS_OR_INVALID_UID,
          '<front>',
        ),
      );
      return $this->safeUrlFromRoute(
        '<front>',
        [],
        $uid,
        $intent,
        PostLoginDecision::FALLBACK_FRONT_INVALID_ACCOUNT,
      );
    }

    if ($intent === IdentityIntentResolver::INTENT_BROWSE) {
      $intent = $this->applyPendingCreateEventIntentFromUserData($uid, $intent);
    }

    $intent_route = $this->routePolicy->routeForIntent($intent);
    if ($intent_route !== NULL) {
      $this->logger->notice(
        'PostLoginRouter: create_event intent → create-event gateway uid=@uid',
        [
          '@uid' => (string) $uid,
        ],
      );
      return $this->safeUrlFromRoute(
        $intent_route,
        [],
        $uid,
        $intent,
        PostLoginDecision::INTENT_CREATE_EVENT,
      );
    }

    $explicit_destination = $this->routePolicy->safeExplicitDestination($request, $uid);
    if ($explicit_destination !== NULL) {
      $this->logger->notice(
        'PostLoginRouter: preserved internal destination uid=@uid intent=@intent decision=@decision route=@route',
        $this->routerLogContext(
          $uid,
          $intent,
          PostLoginDecision::EXPLICIT_INTERNAL_DESTINATION,
          PostLoginDecision::ROUTE_LOG_NA,
        ),
      );
      try {
        return Url::fromUserInput($explicit_destination);
      }
      catch (\InvalidArgumentException) {
        // The policy has already rejected unsafe input. Fall through to the
        // account-aware default if Drupal cannot construct the internal URL.
      }
    }

    $vendor_ids = $this->userVendorMembershipQuery->getVendorIdsForUser($uid);
    if ($vendor_ids === []) {
      return $this->safeUrlFromRoute(
        $this->routePolicy->defaultRoute(FALSE, FALSE),
        [],
        $uid,
        $intent,
        PostLoginDecision::CUSTOMER_ACCOUNT,
      );
    }

    $state = $this->onboardingManager->loadVendorStateByUid($uid);
    $is_complete = $state !== NULL
      && $state->getStage() === 'complete'
      && $state->isCompleted();

    if (!$is_complete) {
      return $this->safeUrlFromRoute(
        $this->routePolicy->defaultRoute(TRUE, FALSE),
        [],
        $uid,
        $intent,
        PostLoginDecision::VENDOR_ONBOARDING_INCOMPLETE,
      );
    }

    return $this->safeUrlFromRoute(
      $this->routePolicy->defaultRoute(TRUE, TRUE),
      [],
      $uid,
      $intent,
      PostLoginDecision::VENDOR_COMPLETE_DASHBOARD,
    );
  }

  /**
   * Applies create_event from registration when verify_mail deferred login (user.data).
   */
  private function applyPendingCreateEventIntentFromUserData(int $uid, string $intent): string {
    $pending = $this->userData->get(
      'myeventlane_auth',
      $uid,
      IdentityIntentResolver::USER_DATA_PENDING_MEL_INTENT,
    );
    if ($pending !== IdentityIntentResolver::INTENT_CREATE_EVENT) {
      return $intent;
    }
    $this->userData->delete(
      'myeventlane_auth',
      $uid,
      IdentityIntentResolver::USER_DATA_PENDING_MEL_INTENT,
    );
    $this->logger->notice(
      'PostLoginRouter: consumed pending_mel_intent create_event uid=@uid',
      ['@uid' => (string) $uid],
    );
    return IdentityIntentResolver::INTENT_CREATE_EVENT;
  }

  /**
   * Whether a route name is registered (does not validate route parameters).
   */
  private function routeExists(string $route): bool {
    return $this->routeHelper->routeExists($route);
  }

  /**
   * User edit with optional pass-reset-token (core password set form).
   */
  private function safeUrlPasswordResetContinue(
    AccountInterface $account,
    Request $request,
    int $uid,
    string $intent,
  ): Url {
    $route = 'entity.user.edit_form';
    $decision = PostLoginDecision::PASSWORD_RESET_CONTINUE_USER_EDIT;
    $query = $this->extractPassResetTokenQuery($request);

    if (!$this->routeExists($route)) {
      $this->logger->warning(
        'PostLoginRouter: password reset handoff route not registered uid=@uid intent=@intent decision=@decision route=@route',
        $this->routerLogContext($uid, $intent, $decision, $route),
      );
      return $this->urlFrontFallback($uid, $intent, $decision, $route);
    }

    try {
      $options = $query !== [] ? ['query' => $query] : [];
      $url = Url::fromRoute($route, ['user' => $account->id()], $options);
      $this->logger->notice(
        'PostLoginRouter: password reset handoff uid=@uid intent=@intent decision=@decision route=@route',
        $this->routerLogContext($uid, $intent, $decision, $route),
      );
      return $url;
    }
    catch (\Throwable $e) {
      $this->logger->error(
        'PostLoginRouter: password reset Url::fromRoute failed uid=@uid intent=@intent decision=@decision route=@route message=@message',
        $this->routerLogContext($uid, $intent, $decision, $route) + [
          '@message' => $e->getMessage(),
        ],
      );
      return $this->urlFrontFallback($uid, $intent, $decision, $route);
    }
  }

  /**
   * @return array<string, string>
   */
  private function extractPassResetTokenQuery(Request $request): array {
    if ($request->query->has('pass-reset-token')) {
      $t = $request->query->get('pass-reset-token');
      return is_string($t) && $t !== '' ? ['pass-reset-token' => $t] : [];
    }
    $destination = $request->query->get('destination');
    if (!is_string($destination) || $destination === '') {
      return [];
    }
    $decoded = rawurldecode($destination);
    if (!str_contains(strtolower($decoded), 'pass-reset-token')) {
      return [];
    }
    $query_string = '';
    if (preg_match('#^https?://#i', $decoded)) {
      $parsed = parse_url($decoded);
      if ($parsed === FALSE) {
        return [];
      }
      $query_string = $parsed['query'] ?? '';
    }
    elseif (str_contains($decoded, '?')) {
      $parts = explode('?', $decoded, 2);
      $query_string = $parts[1] ?? '';
    }
    if ($query_string === '') {
      return [];
    }
    parse_str($query_string, $q);
    if (!empty($q['pass-reset-token']) && is_string($q['pass-reset-token']) && $q['pass-reset-token'] !== '') {
      return ['pass-reset-token' => $q['pass-reset-token']];
    }
    return [];
  }

  /**
   * Builds a Url when the route is registered; logs and falls back otherwise.
   */
  private function safeUrlFromRoute(
    string $route,
    array $parameters,
    int $uid,
    string $intent,
    string $decision,
  ): Url {
    if (!$this->routeExists($route)) {
      $this->logger->warning(
        'PostLoginRouter: route not registered uid=@uid intent=@intent decision=@decision route=@route',
        $this->routerLogContext($uid, $intent, $decision, $route),
      );
      return $this->urlFrontFallback($uid, $intent, $decision, $route);
    }

    try {
      $url = Url::fromRoute($route, $parameters);
      $this->logger->notice(
        'PostLoginRouter: resolved uid=@uid intent=@intent decision=@decision route=@route',
        $this->routerLogContext($uid, $intent, $decision, $route),
      );
      return $url;
    }
    catch (\Throwable $e) {
      $this->logger->error(
        'PostLoginRouter: Url::fromRoute failed uid=@uid intent=@intent decision=@decision route=@route message=@message',
        $this->routerLogContext($uid, $intent, $decision, $route) + [
          '@message' => $e->getMessage(),
        ],
      );
      return $this->urlFrontFallback($uid, $intent, $decision, $route);
    }
  }

  /**
   * @return array<string, string>
   */
  private function routerLogContext(int $uid, string $intent, string $decision, string $route): array {
    return [
      '@uid' => (string) $uid,
      '@intent' => $intent,
      '@decision' => $decision,
      '@route' => $route,
    ];
  }

  private function urlFrontFallback(int $uid, string $intent, string $decision, string $failed_route): Url {
    try {
      return Url::fromRoute('<front>');
    }
    catch (\Throwable $e) {
      $this->logger->error(
        'PostLoginRouter: front route unavailable uid=@uid intent=@intent decision=@decision route=@route failed_route=@failed_route message=@message',
        $this->routerLogContext($uid, $intent, $decision, '<front>') + [
          '@failed_route' => $failed_route,
          '@message' => $e->getMessage(),
        ],
      );
      return Url::fromUri('internal:/');
    }
  }

}
