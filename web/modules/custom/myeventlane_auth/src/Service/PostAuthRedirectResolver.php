<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Drupal\myeventlane_auth\PostLoginDecision;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_core\Service\MelDestinationNormalizer;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Picks a safe post-login redirect from destination + intent for MEL flows.
 *
 * Vendor onboarding and /create-event remain the canonical paths; this only
 * centralizes destination validation and domain-aware absolute URLs.
 */
final class PostAuthRedirectResolver {

  public function __construct(
    private readonly IdentityIntentResolver $intentResolver,
    private readonly MelDestinationNormalizer $destinationNormalizer,
    private readonly UrlGeneratorInterface $urlGenerator,
    private readonly RequestStack $requestStack,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Returns an absolute URL to the MEL post-login hub (preserves destination + intent).
   *
   * Used after password/login handoff so users see branded onboarding before deep links.
   */
  public function buildPostLoginHubUrl(AccountInterface $account, string $destination_raw, string $intent_raw): string {
    if ($account->isAnonymous()) {
      $intent = $this->intentResolver->normalizeIntent($intent_raw);
      $this->logger->warning(
        'PostAuthRedirectResolver: buildPostLoginHubUrl anonymous uid=@uid intent=@intent decision=@decision route=@route',
        [
          '@uid' => '0',
          '@intent' => $intent,
          '@decision' => PostLoginDecision::BUILD_POST_LOGIN_HUB_ANONYMOUS,
          '@route' => 'myeventlane_auth.post_login',
        ],
      );
      return $this->urlGenerator->generateFromRoute('<front>', [], ['absolute' => TRUE]);
    }
    $intent = $this->intentResolver->normalizeIntent($intent_raw);
    $path = $this->sanitizeInternalPath($destination_raw);
    $query = [];
    if ($path !== '/' && $path !== '') {
      $query['destination'] = $path;
    }
    if ($intent !== IdentityIntentResolver::INTENT_BROWSE) {
      $query['mel_intent'] = $intent;
    }
    return $this->urlGenerator->generateFromRoute(
      'myeventlane_auth.post_login',
      [],
      ['absolute' => TRUE, 'query' => $query],
    );
  }

  /**
   * Returns an absolute URL suitable for RedirectResponse.
   */
  public function resolveRedirectUrl(AccountInterface $account, string $destination_raw, string $intent_raw): string {
    if ($account->isAnonymous()) {
      $intent = $this->intentResolver->normalizeIntent($intent_raw);
      $this->logger->warning(
        'PostAuthRedirectResolver: resolveRedirectUrl anonymous uid=@uid intent=@intent decision=@decision route=@route',
        [
          '@uid' => '0',
          '@intent' => $intent,
          '@decision' => PostLoginDecision::RESOLVE_REDIRECT_URL_ANONYMOUS,
          '@route' => PostLoginDecision::ROUTE_LOG_NA,
        ],
      );
      return $this->urlGenerator->generateFromRoute('<front>', [], ['absolute' => TRUE]);
    }

    $intent = $this->intentResolver->normalizeIntent($intent_raw);
    $path = $this->sanitizeInternalPath($destination_raw);

    if ($intent === IdentityIntentResolver::INTENT_CREATE_EVENT) {
      $path = '/create-event';
    }

    if ($path === '/' && $intent === IdentityIntentResolver::INTENT_BROWSE) {
      try {
        return $this->urlGenerator->generateFromRoute(
          'entity.user.canonical',
          ['user' => $account->id()],
          ['absolute' => TRUE],
        );
      }
      catch (\Throwable $e) {
        $this->logger->warning('Post-auth default account URL failed: @m', ['@m' => $e->getMessage()]);
      }
    }

    $absolute = $this->destinationNormalizer->absoluteFromInternalPathVendorPublicSplit($path);
    if ($absolute !== NULL && $absolute !== '') {
      return $absolute;
    }

    $request = $this->requestStack->getCurrentRequest();
    $base = $request ? $request->getSchemeAndHttpHost() : '';
    if ($base !== '' && str_starts_with($path, '/')) {
      return $base . $path;
    }

    return $this->urlGenerator->generateFromRoute('<front>', [], ['absolute' => TRUE]);
  }

  /**
   * Limits redirect targets to same-site internal paths.
   */
  private function sanitizeInternalPath(string $path): string {
    $path = trim($path);
    if ($path === '' || $path === '/') {
      return '/';
    }
    if (!str_starts_with($path, '/')) {
      return '/';
    }
    if (str_contains($path, '..') || str_contains($path, "\0")) {
      return '/';
    }
    if (strlen($path) > 2048) {
      return '/';
    }
    return $path;
  }

}
