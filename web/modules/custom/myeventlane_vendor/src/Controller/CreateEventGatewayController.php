<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
   * Constructs the controller.
   */
  public function __construct(
    OnboardingManager $onboarding_manager,
    RendererInterface $renderer,
  ) {
    $this->onboardingManager = $onboarding_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_onboarding.manager'),
      $container->get('renderer'),
    );
  }

  /**
   * Redirects users based on their vendor status.
   *
   * Logic:
   * - Anonymous users → login with destination back to /create-event
   * - Logged-in users without vendor → /vendor/onboard (vendor setup)
   * - Logged-in users with vendor → event creation wizard.
   *
   * Option A: When user has vendor but onboarding incomplete, adds a flash
   * onboarding panel message on the destination (status).
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response.
   */
  public function gateway(): RedirectResponse {
    $current_user = $this->currentUser();

    // Anonymous users: redirect to login with destination and vendor intent.
    if ($current_user->isAnonymous()) {
      $this->messenger()->addWarning($this->t('To create events, you need to log in with a vendor/organiser account. If you don\'t have an account yet, you can create one after logging in.'));
      $login_url = Url::fromRoute('user.login', [], [
        'query' => [
          'destination' => '/create-event',
        ],
      ]);
      return new RedirectResponse($login_url->toString());
    }

    $uid = (int) $current_user->id();
    if ($uid <= 0) {
      $login_url = Url::fromRoute('user.login', [], [
        'query' => [
          'destination' => '/create-event',
        ],
      ]);
      return new RedirectResponse($login_url->toString());
    }

    $state = $this->onboardingManager->loadVendorStateByUid($uid);
    if ($state === NULL) {
      $this->onboardingManager->createVendorStateForUid($uid);
      $onboard_url = Url::fromRoute('myeventlane_vendor.onboard.profile');
      return new RedirectResponse($onboard_url->toString());
    }

    $is_complete = $state->getStage() === 'complete' && $state->isCompleted();

    // In progress: always route to onboarding start (never to vendor wizard).
    if (!$is_complete) {
      $onboard_url = Url::fromRoute('myeventlane_vendor.onboard.profile');
      return new RedirectResponse($onboard_url->toString());
    }

    // Completed: ensure vendor entity exists, ensure vendor role, then redirect.
    $vendor_ids = $this->getUserVendors($uid);
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

    $create_url = Url::fromRoute('myeventlane_event.wizard.create');
    return new RedirectResponse($create_url->toString());
  }

  /**
   * Gets vendor IDs associated with a user.
   *
   * Checks both:
   * - uid (owner) field
   * - field_vendor_users (multi-user field)
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array
   *   Array of vendor IDs (as integers).
   */
  private function getUserVendors(int $uid): array {
    $storage = $this->entityTypeManager()->getStorage('myeventlane_vendor');

    // Check vendors where user is the owner (uid field).
    $owner_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->execute();

    // Check vendors where user is in field_vendor_users.
    $users_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_vendor_users', $uid)
      ->execute();

    // Merge and return unique IDs, converting to integers.
    $all_ids = array_merge(
      $owner_ids ?: [],
      $users_ids ?: []
    );

    // Convert string keys to integer values and ensure uniqueness.
    return array_values(array_unique(array_map('intval', $all_ids)));
  }

}
