<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Theme preprocess helpers for Event Studio templates.
 */
final class EventStudioPreprocess {

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
    private readonly OnboardingManager $onboardingManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Adds contextual action URLs for the mel_event_studio theme hook.
   *
   * @param array<string, mixed> $variables
   *   Theme variables for mel_event_studio; expects element['#mel_studio_node'].
   */
  public function preprocess(array &$variables): void {
    $variables['mel_publish_blocked'] = FALSE;
    $variables['mel_publish_stripe_gate'] = FALSE;
    $variables['mel_stripe_connected'] = FALSE;
    $variables['mel_show_first_event_banner'] = FALSE;
    $variables['show_onboarding_sidebar'] = FALSE;
    $variables['onboarding_stages'] = [];

    $uid = (int) $this->currentUser->id();
    $vendor_ids = $uid > 0 ? $this->userVendorMembershipQuery->getVendorIdsForUser($uid) : [];

    if ($uid > 0 && $vendor_ids === []) {
      $variables['mel_publish_blocked'] = TRUE;
    }

    $stripe_connected = FALSE;
    $state = NULL;
    if ($uid > 0 && $vendor_ids !== []) {
      $state = $this->onboardingManager->loadVendorStateByUid($uid);
      $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load(reset($vendor_ids));
      if ($state !== NULL && $vendor instanceof Vendor) {
        $user = $this->entityTypeManager->getStorage('user')->load($uid);
        if ($user instanceof UserInterface) {
          $state = $this->onboardingManager->loadOrCreateVendor($user, $vendor);
          $this->onboardingManager->refreshFlags($state);
          $stripe_connected = !empty($state->getFlags()['stripe_connected']);
        }
      }
    }

    $variables['mel_stripe_connected'] = $stripe_connected;

    if ($state !== NULL && !$state->isCompleted() && \function_exists('_myeventlane_vendor_theme_build_onboarding_stages')) {
      $variables['onboarding_stages'] = _myeventlane_vendor_theme_build_onboarding_stages($state);
      $variables['show_onboarding_sidebar'] = TRUE;
    }

    $mode = (string) ($variables['mode'] ?? '');
    $request = $this->requestStack->getCurrentRequest();
    $first_flag = $request && (string) $request->query->get('mel_first_event') === '1';
    if ($mode === 'create' && $state !== NULL && !$state->isCompleted()) {
      if ($first_flag || $state->getStage() === 'ask') {
        $variables['mel_show_first_event_banner'] = TRUE;
      }
    }

    $element = $variables['element'] ?? [];
    $node = $element['#mel_studio_node'] ?? NULL;

    if ($node instanceof NodeInterface && $node->bundle() === 'event' && $vendor_ids !== []) {
      $flags = $state !== NULL ? $state->getFlags() : [];
      $event_type = '';
      if ($node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()) {
        $event_type = (string) $node->get('field_event_type')->value;
      }
      $is_paid = in_array($event_type, ['paid', 'both'], TRUE);
      if ($is_paid && empty($flags['stripe_connected'])) {
        $variables['mel_publish_blocked'] = TRUE;
        $variables['mel_publish_stripe_gate'] = TRUE;
      }
    }

    $actions = [
      'view' => NULL,
      'booking' => NULL,
      'scan' => NULL,
    ];

    if ($node instanceof NodeInterface && !$node->isNew()) {
      $nid = (int) $node->id();

      $view = $node->toUrl();
      if ($view->access()) {
        $actions['view'] = $view->toString();
      }

      $event_type = '';
      if ($node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()) {
        $event_type = (string) $node->get('field_event_type')->value;
      }
      if ($event_type !== 'external') {
        $booking = Url::fromRoute('myeventlane_commerce.event_book', ['node' => $nid]);
        if ($booking->access()) {
          $actions['booking'] = $booking->toString();
        }
      }

      $scan = Url::fromRoute('myeventlane_tickets.ticket_scan', ['event' => $nid]);
      if ($scan->access()) {
        $actions['scan'] = $scan->toString();
      }
    }

    $variables['mel_event_actions'] = $actions;
  }

}
