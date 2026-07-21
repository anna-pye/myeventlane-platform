<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_legal\Service\LegalGatekeeper;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Drupal\myeventlane_vendor\Service\VendorEventStudioCreateService;
use Drupal\user\UserInterface;
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
   * Draft resume + Stripe checks aligned with Event Studio create.
   */
  private readonly VendorEventStudioCreateService $eventStudioCreate;

  /**
   * Constructs the controller.
   */
  public function __construct(
    OnboardingManager $onboarding_manager,
    LegalGatekeeper $legal_gatekeeper,
    RequestStack $request_stack,
    UserVendorMembershipQuery $user_vendor_membership_query,
    VendorEventStudioCreateService $event_studio_create,
  ) {
    $this->onboardingManager = $onboarding_manager;
    $this->legalGatekeeper = $legal_gatekeeper;
    $this->requestStack = $request_stack;
    $this->userVendorMembershipQuery = $user_vendor_membership_query;
    $this->eventStudioCreate = $event_studio_create;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_onboarding.manager'),
      $container->get('myeventlane_legal.gatekeeper'),
      $container->get('request_stack'),
      $container->get('myeventlane_vendor.user_vendor_membership_query'),
      $container->get('myeventlane_vendor.event_studio_create'),
    );
  }

  /**
   * Redirects or renders based on vendor onboarding status.
   *
   * Logic:
   * - Anonymous → login with return to /create-event (MEL continue when available)
   * - No vendor entity → organiser profile onboarding (destination /create-event)
   * - Stripe not ready (non-admin) → soft warning; Connect only required at publish
   * - Vendor + incomplete onboarding → status warning; Event Studio shows MEL workflow
   * - Legal terms → soft warning if not accepted; accept before publish
   * - Unpublished draft event → Event Studio edit
   * - Else → Event Studio create
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|array
   *   Redirect or render array.
   */
  public function gateway(): RedirectResponse|array {
    $current_user = $this->currentUser();

    if ($current_user->isAnonymous()) {
      $login_url = $this->buildAnonymousAuthEntryLoginUrl();
      return new RedirectResponse($login_url->toString());
    }

    $uid = (int) $current_user->id();
    if ($uid <= 0) {
      $login_url = $this->buildAnonymousAuthEntryLoginUrl();
      return new RedirectResponse($login_url->toString());
    }

    $vendor_ids = $this->userVendorMembershipQuery->getVendorIdsForUser($uid);
    $has_vendor = $vendor_ids !== [];
    $draft_nid = $this->eventStudioCreate->findLatestResumableDraftNidForUser($uid);

    if (!$has_vendor) {
      if ($draft_nid !== NULL) {
        $this->getLogger('myeventlane_vendor')->warning(
          'Create-event gateway: draft node @nid exists for uid=@uid without vendor membership; not sending to event edit.',
          ['@nid' => (string) $draft_nid, '@uid' => (string) $uid],
        );
      }
      $state = $this->onboardingManager->createVendorStateForUid($uid);
      $this->onboardingManager->recordCreateEventGatewayMilestone($state, 'event_started');

      if ($this->onboardingManager->isCompleted($state) && $current_user->getAccount() instanceof UserInterface) {
        $this->onboardingManager->ensureVendorExists($current_user->getAccount());
        $vendor_ids = $this->userVendorMembershipQuery->getVendorIdsForUser($uid);
        $has_vendor = $vendor_ids !== [];
      }
      if (!$has_vendor) {
        $onboard_url = Url::fromRoute('myeventlane_vendor.onboard.profile', [], [
          'query' => ['destination' => '/create-event'],
        ]);
        $this->getLogger('myeventlane_vendor')->notice(
          'Create-event gateway: no vendor, redirecting to organiser profile uid=@uid',
          ['@uid' => (string) $uid],
        );
        return new RedirectResponse($onboard_url->toString());
      }
    }

    $state = $this->onboardingManager->loadVendorStateByUid($uid);
    if ($state === NULL) {
      $vendor = $this->entityTypeManager()->getStorage('myeventlane_vendor')->load(reset($vendor_ids));
      if (!$vendor instanceof Vendor) {
        $this->getLogger('myeventlane_vendor')->error('Create-event gateway: vendor @vid missing for uid=@uid', [
          '@vid' => (string) reset($vendor_ids),
          '@uid' => (string) $uid,
        ]);
        $onboard_url = Url::fromRoute('myeventlane_vendor.onboard.profile', [], [
          'query' => ['destination' => '/create-event'],
        ]);
        return new RedirectResponse($onboard_url->toString());
      }
      $account = $current_user->getAccount();
      if (!$account instanceof UserInterface) {
        $this->getLogger('myeventlane_vendor')->error('Create-event gateway: account not UserInterface uid=@uid', ['@uid' => (string) $uid]);
        $onboard_url = Url::fromRoute('myeventlane_vendor.onboard.profile', [], [
          'query' => ['destination' => '/create-event'],
        ]);
        return new RedirectResponse($onboard_url->toString());
      }
      $state = $this->onboardingManager->loadOrCreateVendor($account, $vendor);
    }

    $is_complete = $this->onboardingManager->isCompleted($state);

    if (!$is_complete) {
      $this->getLogger('myeventlane_vendor')->notice(
        'MEL: onboarding incomplete but allowing event creation uid=@uid',
        ['@uid' => (string) $uid],
      );
      $this->messenger()->addWarning(
        $this->t('Finish your organiser setup to unlock full features.'),
      );
    }

    $vendor = NULL;
    if (!empty($vendor_ids)) {
      $vendor = $this->entityTypeManager()->getStorage('myeventlane_vendor')->load(reset($vendor_ids));
    }
    if (!$vendor instanceof Vendor) {
      $account = $current_user->getAccount();
      $vendor = $this->onboardingManager->ensureVendorExists($account);
    }

    $this->onboardingManager->ensureVendorAccess($current_user->getAccount());

    if ($state->getVendorId() !== (int) $vendor->id()) {
      $state->setVendorId((int) $vendor->id());
      $this->onboardingManager->persistOnboardingState($state);
    }

    if ($vendor instanceof Vendor && !$this->eventStudioCreate->isVendorStripeConnected($vendor, $uid, $current_user->getAccount())) {
      $this->messenger()->addWarning(
        $this->t('Connect Stripe before publishing your event.'),
      );
    }

    // Onboarding CTAs: Event Studio (create/edit) renders MELWorkflowSystem primary
    // progress — do not duplicate myeventlane_vendor_onboarding_panel in messenger.

    if (!$this->legalGatekeeper->hasVendorAcceptedTerms()) {
      $this->messenger()->addWarning($this->t('Accept terms to publish.'));
    }

    $http_request = $this->requestStack->getCurrentRequest();
    $is_first_event = $http_request !== NULL
      && (string) $http_request->query->get('mel_first_event') === '1';

    // First-event onboarding may continue an existing draft silently.
    // Explicit Create event otherwise offers Continue / Start new.
    if ($draft_nid !== NULL) {
      if ($is_first_event) {
        return new RedirectResponse(
          Url::fromRoute('myeventlane_event_studio.workspace', ['node' => $draft_nid], [
            'query' => ['mel_first_event' => '1'],
          ])->toString(),
        );
      }
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.create_event_draft_choice')->toString(),
      );
    }

    $options = [];
    if ($is_first_event) {
      $options['query'] = ['mel_first_event' => '1'];
    }
    $create_url = Url::fromRoute('myeventlane_event_studio.create', [], $options);
    return new RedirectResponse($create_url->toString());
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
