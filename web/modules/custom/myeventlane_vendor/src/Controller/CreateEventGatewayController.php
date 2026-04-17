<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Entity\OnboardingStateInterface;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_legal\Service\LegalGatekeeper;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Gateway controller for event creation that enforces vendor onboarding.
 */
class CreateEventGatewayController extends ControllerBase {

  /**
   * The onboarding manager.
   */
  private readonly OnboardingManager $onboardingManager;

  /**
   * The renderer.
   */
  private readonly RendererInterface $renderer;

  /**
   * The legal gatekeeper.
   */
  private readonly LegalGatekeeper $legalGatekeeper;

  /**
   * The request stack.
   */
  private readonly RequestStack $requestStack;

  /**
   * Vendor membership query (shared with PostLoginRouter).
   */
  private readonly UserVendorMembershipQuery $userVendorMembershipQuery;

  /**
   * Constructs the controller.
   */
  public function __construct(
    OnboardingManager $onboarding_manager,
    RendererInterface $renderer,
    LegalGatekeeper $legal_gatekeeper,
    RequestStack $request_stack,
    UserVendorMembershipQuery $user_vendor_membership_query,
  ) {
    $this->onboardingManager = $onboarding_manager;
    $this->renderer = $renderer;
    $this->legalGatekeeper = $legal_gatekeeper;
    $this->requestStack = $request_stack;
    $this->userVendorMembershipQuery = $user_vendor_membership_query;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_onboarding.manager'),
      $container->get('renderer'),
      $container->get('myeventlane_legal.gatekeeper'),
      $container->get('request_stack'),
      $container->get('myeventlane_vendor.user_vendor_membership_query'),
    );
  }

  /**
   * Redirects or renders based on vendor onboarding status.
   *
   * Logic:
   * - Anonymous users → MEL auth continue (intent create_event) when available,
   *   else login with destination back to /create-event
   * - No state → redirect to profile
   * - Incomplete + ?auto=1 → redirect to next onboarding step
   * - Incomplete (no auto) → render explanatory "Complete setup" page
   * - Complete → ensure vendor, assert terms, redirect to wizard
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|array
   *   Redirect or render array.
   */
  public function gateway(): RedirectResponse|array {
    $current_user = $this->currentUser();

    // Anonymous users: intent-aware auth handoff, then return to this gateway.
    // Encouraging copy for create-event is added on user.login by myeventlane_auth (Gin Login–safe).
    if ($current_user->isAnonymous()) {
      $login_url = $this->buildAnonymousAuthEntryLoginUrl();
      return new RedirectResponse($login_url->toString());
    }

    $uid = (int) $current_user->id();
    if ($uid <= 0) {
      $login_url = $this->buildAnonymousAuthEntryLoginUrl();
      return new RedirectResponse($login_url->toString());
    }

    $state = $this->onboardingManager->loadVendorStateByUid($uid);
    if ($state === NULL) {
      $this->onboardingManager->createVendorStateForUid($uid);
      $onboard_url = Url::fromRoute('myeventlane_vendor.onboard.profile');
      return new RedirectResponse($onboard_url->toString());
    }

    $is_complete = $state->getStage() === 'complete' && $state->isCompleted();

    // In progress: redirect if ?auto=1, else render explanatory page.
    if (!$is_complete) {
      $request = $this->requestStack->getCurrentRequest();
      $auto_redirect = $request && (string) $request->query->get('auto') === '1';

      $next_route = $this->onboardingManager->getNextVendorOnboardRouteForAuthenticated($state);
      $next_route = $next_route ?: 'myeventlane_vendor.onboard.profile';

      if ($auto_redirect) {
        $redirect_url = $next_route === 'myeventlane_vendor.onboard.stripe'
          ? Url::fromRoute('myeventlane_vendor.stripe_connect', [], ['query' => ['destination' => '/create-event']])
          : Url::fromRoute($next_route);
        return new RedirectResponse($redirect_url->toString());
      }

      return $this->buildCompleteSetupPage($state, $next_route);
    }

    // Completed: ensure vendor entity exists, ensure vendor role, then redirect.
    $vendor_ids = $this->userVendorMembershipQuery->getVendorIdsForUser($uid);
    $vendor = NULL;
    if (!empty($vendor_ids)) {
      $vendor = $this->entityTypeManager()->getStorage('myeventlane_vendor')->load(reset($vendor_ids));
    }
    if (!$vendor instanceof Vendor) {
      $account = $current_user->getAccount();
      $vendor = $this->onboardingManager->ensureVendorExists($account);
    }

    // Ensure vendor console role/permission (idempotent).
    $this->onboardingManager->ensureVendorAccess($current_user->getAccount());

    // Ensure state references vendor for completion invariant.
    if ($state->getVendorId() !== (int) $vendor->id()) {
      $state->setVendorId((int) $vendor->id());
      $state->save();
    }

    // Optionally add onboarding flash panel message on the destination (status).
    try {
      $user = $this->entityTypeManager()->getStorage('user')->load((int) $current_user->id());
      if ($vendor instanceof Vendor && $user instanceof \Drupal\user\UserInterface && $vendor->id()) {
        $state = $this->onboardingManager->loadOrCreateVendor($user, $vendor);
        $this->onboardingManager->refreshFlags($state);
        if (!$this->onboardingManager->isCompleted($state)) {
          $stage = $state->getStage();
          $stage_labels = [
            'probe' => $this->t('Get started'),
            'present' => $this->t('Profile'),
            'listen' => $this->t('Payments'),
            'ask' => $this->t('First event'),
            'invite' => $this->t('Boost'),
            'complete' => $this->t('Complete'),
          ];
          $next = $this->onboardingManager->getNextActionForAuthenticatedVendor($state);
          $panel = [
            '#theme' => 'myeventlane_vendor_onboarding_panel',
            '#stage_label' => $stage_labels[$stage] ?? $stage,
            '#flags' => $state->getFlags(),
            '#next_action' => $next,
            '#vendor' => $vendor,
          ];
          $markup = (string) $this->renderer->renderInIsolation($panel);
          if (trim($markup) !== '') {
            $this->messenger()->addStatus($markup);
          }
        }
      }
    }
    catch (\Throwable $e) {
      $this->getLogger('myeventlane_vendor')->warning('Create-event gateway onboarding flash failed: @m', ['@m' => $e->getMessage()]);
    }

    $this->legalGatekeeper->assertVendorTermsAccepted();

    $create_url = Url::fromRoute('myeventlane_event_studio.create');
    return new RedirectResponse($create_url->toString());
  }

  /**
   * Builds the "Complete your organiser setup" explanatory page.
   *
   * @param \Drupal\myeventlane_core\Entity\OnboardingStateInterface $state
   *   The vendor onboarding state.
   * @param string $next_route
   *   Route for the next onboarding step.
   *
   * @return array
   *   Render array.
   */
  private function buildCompleteSetupPage(OnboardingStateInterface $state, string $next_route): array {
    $stage_labels = [
      'probe' => $this->t('Get started'),
      'present' => $this->t('Profile'),
      'listen' => $this->t('Payments'),
      'ask' => $this->t('First event'),
      'invite' => $this->t('Boost'),
      'complete' => $this->t('Complete'),
    ];
    $stage_order = OnboardingStateInterface::STAGE_ORDER;
    $current_idx = array_search($state->getStage(), $stage_order, TRUE);
    $current_idx = $current_idx !== FALSE ? $current_idx : 0;

    $incomplete_stages = [];
    foreach (array_slice($stage_order, $current_idx, NULL, TRUE) as $stage) {
      if ($stage !== 'complete') {
        $incomplete_stages[] = (string) ($stage_labels[$stage] ?? $stage);
      }
    }

    // When next step is Stripe, link directly to stripe/connect (functional) instead
    // of vendor/onboard/stripe (which may not work in some setups).
    $next_url = $next_route === 'myeventlane_vendor.onboard.stripe'
      ? Url::fromRoute('myeventlane_vendor.stripe_connect', [], [
          'query' => ['destination' => '/create-event'],
        ])->toString()
      : Url::fromRoute($next_route)->toString();

    return [
      '#theme' => 'create_event_gateway_complete_setup',
      '#attached' => [
        'library' => ['myeventlane_vendor/onboarding'],
      ],
      '#title' => $this->t('Complete your organiser setup')->render(),
      '#incomplete_stages' => $incomplete_stages,
      '#next_route' => $next_route,
      '#next_url' => $next_url,
      '#auto_url' => Url::fromRoute('myeventlane_vendor.create_event_gateway', [], [
        'query' => ['auto' => '1'],
      ])->toString(),
    ];
  }

  /**
   * Builds the login URL with destination pointing at MEL continue or /create-event.
   */
  private function buildAnonymousAuthEntryLoginUrl(): Url {
    if ($this->moduleHandler()->moduleExists('myeventlane_auth')) {
      $auth_continue = Url::fromRoute('myeventlane_auth.mel_continue', [], [
        'query' => [
          'destination' => '/create-event',
          'mel_intent' => 'create_event',
        ],
      ]);
      return Url::fromRoute('user.login', [], [
        'query' => [
          'destination' => $auth_continue->toString(),
        ],
      ]);
    }
    return Url::fromRoute('user.login', [], [
      'query' => [
        'destination' => '/create-event',
      ],
    ]);
  }

}
