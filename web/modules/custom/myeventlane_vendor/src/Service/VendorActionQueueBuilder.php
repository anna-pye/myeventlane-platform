<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_surface\MelVendorDashboardActionQueueGovernance;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Builds a prioritised vendor dashboard action queue from the TASK 3 view model.
 *
 * Uses only data already present on the dashboard model plus route/access checks.
 * Readiness wording is sourced from MELStateSystem (MelReadinessHelper); prioritisation is
 * adjusted by workflow and operational-policy governance (presentation only).
 */
final class VendorActionQueueBuilder {

  use StringTranslationTrait;

  private const MAX_ACTIONS = 6;

  private const MAX_DRAFT_EVENT_ACTIONS = 2;

  public function __construct(
    private readonly RouteProviderInterface $routeProvider,
    private readonly AccessManagerInterface $accessManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly MelReadinessHelper $readinessHelper,
    private readonly MelVendorDashboardActionQueueGovernance $actionGovernance,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $dashboardModel
   *
   * @return list<array<string, mixed>>
   */
  public function build(array $dashboardModel, AccountInterface $account): array {
    try {
      return $this->doBuild($dashboardModel, $account);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->error('Vendor action queue build failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * @param array<string, mixed> $dashboardModel
   *
   * @return list<array<string, mixed>>
   */
  private function doBuild(array $dashboardModel, AccountInterface $account): array {
    $actions = [];

    $vendorId = $dashboardModel['vendor']['id'] ?? NULL;
    $vendorMissing = $vendorId === NULL || $vendorId === 0;

    $readiness = $dashboardModel['readiness'] ?? [];
    $events = $dashboardModel['events'] ?? [];
    if (!is_array($events)) {
      $events = [];
    }

    $stripeReady = (bool) ($readiness['stripe_ready'] ?? FALSE);
    $hasPaidOrBoth = $this->eventsIncludePaidOrBoth($events);

    $analytics = $dashboardModel['analytics_summary'] ?? [];
    $analyticsAvailable = (bool) ($analytics['available'] ?? FALSE);

    // 1. No vendor profile.
    if ($vendorMissing) {
      $copy = $this->readinessHelper->vendorActionNoVendorProfileStrings();
      $actions[] = [
        'key' => 'no_vendor_profile',
        'priority' => 10,
        'severity' => 'warning',
        'title' => $copy['title'],
        'message' => $copy['message'],
        'action_label' => $copy['action_label'],
        'url' => $this->routeUrlIfAccessible('myeventlane_vendor.console.settings', [], $account),
        'context' => [
          'type' => 'vendor_profile',
          'entity_id' => NULL,
        ],
      ];
    }

    // 2. Profile incomplete (skip when there is no vendor row — covered by action 1).
    $profileItemComplete = $this->getReadinessItemComplete($readiness, 'profile');
    $profileCompleteTop = (bool) ($readiness['profile_complete'] ?? FALSE);
    $profileIncomplete = !$profileCompleteTop || $profileItemComplete === FALSE;
    if (!$vendorMissing && $profileIncomplete) {
      $vid = is_int($vendorId) ? $vendorId : NULL;
      $copy = $this->readinessHelper->vendorActionProfileIncompleteStrings();
      $actions[] = [
        'key' => 'profile_incomplete',
        'priority' => 20,
        'severity' => 'warning',
        'title' => $copy['title'],
        'message' => $copy['message'],
        'action_label' => $copy['action_label'],
        'url' => $this->routeUrlIfAccessible('myeventlane_vendor.console.settings', [], $account),
        'context' => [
          'type' => 'vendor_profile',
          'entity_id' => $vid,
        ],
      ];
    }

    // 6. Published event missing RSVP/tickets (priority 25).
    foreach ($events as $event) {
      if (!is_array($event)) {
        continue;
      }
      if (!$this->isPublishedLiveEvent($event)) {
        continue;
      }
      if (($event['event_type'] ?? '') !== 'unknown') {
        continue;
      }

      $nid = isset($event['nid']) ? (int) $event['nid'] : 0;
      $url = $this->pickEventEditOrTicketsUrl($event);
      $copy = $this->readinessHelper->vendorActionMissingBookingStrings();

      $actions[] = [
        'key' => 'missing_booking_' . ($nid > 0 ? (string) $nid : 'unknown'),
        'priority' => 25,
        'severity' => 'error',
        'title' => $copy['title'],
        'message' => $copy['message'],
        'action_label' => $copy['action_label'],
        'url' => $url,
        'context' => [
          'type' => 'event',
          'entity_id' => $nid > 0 ? $nid : NULL,
        ],
      ];
    }

    // 3. Stripe / payout incomplete (no API calls).
    $stripeItemComplete = $this->getReadinessItemComplete($readiness, 'stripe');
    $stripeMarkedIncomplete = $stripeItemComplete === FALSE;
    $needsStripeAction = $stripeMarkedIncomplete || (!$stripeReady && $hasPaidOrBoth);

    // Stripe Connect is tied to the vendor-linked store; skip when no vendor row.
    if ($needsStripeAction && !$vendorMissing) {
      $severity = $hasPaidOrBoth ? 'error' : 'warning';
      $priority = $hasPaidOrBoth ? 30 : 80;
      $copy = $this->readinessHelper->vendorActionStripePayoutStrings($hasPaidOrBoth);

      $actions[] = [
        'key' => 'stripe_payout_incomplete',
        'priority' => $priority,
        'severity' => $severity,
        'title' => $copy['title'],
        'message' => $copy['message'],
        'action_label' => $copy['action_label'],
        'url' => $this->stripeDashboardUrl($account),
        'context' => [
          'type' => 'stripe',
          'entity_id' => NULL,
        ],
      ];
    }

    // 4. No events yet.
    if ($events === []) {
      $copy = $this->readinessHelper->vendorActionNoEventsStrings();
      $actions[] = [
        'key' => 'no_events_yet',
        'priority' => 40,
        'severity' => 'info',
        'title' => $copy['title'],
        'message' => $copy['message'],
        'action_label' => $copy['action_label'],
        'url' => $this->routeUrlIfAccessible('myeventlane_vendor.create_event_gateway', [], $account),
        'context' => [
          'type' => 'events',
          'entity_id' => NULL,
        ],
      ];
    }

    // 5. Draft events (max 2).
    $draftCount = 0;
    foreach ($events as $event) {
      if (!is_array($event) || !$this->isDraftLikeEvent($event)) {
        continue;
      }
      if ($draftCount >= self::MAX_DRAFT_EVENT_ACTIONS) {
        break;
      }
      $nid = isset($event['nid']) ? (int) $event['nid'] : 0;
      $links = $event['links'] ?? [];
      $url = (is_array($links) && isset($links['edit']) && $links['edit'] instanceof Url)
        ? $links['edit']
        : NULL;

      $copy = $this->readinessHelper->vendorActionDraftEventStrings();

      $actions[] = [
        'key' => 'draft_event_' . ($nid > 0 ? (string) $nid : (string) $draftCount),
        'priority' => 50,
        'severity' => 'warning',
        'title' => $copy['title'],
        'message' => $copy['message'],
        'action_label' => $copy['action_label'],
        'url' => $url,
        'context' => [
          'type' => 'event',
          'entity_id' => $nid > 0 ? $nid : NULL,
        ],
      ];
      $draftCount++;
    }

    // 7. Upcoming attendee prep — skipped: no machine date on dashboard model (TASK 4).

    // 8. Analytics unavailable but paid activity exists.
    if (!$analyticsAvailable && $hasPaidOrBoth) {
      $copy = $this->readinessHelper->vendorActionAnalyticsUnavailableStrings();
      $actions[] = [
        'key' => 'analytics_unavailable',
        'priority' => 90,
        'severity' => 'info',
        'title' => $copy['title'],
        'message' => $copy['message'],
        'action_label' => $copy['action_label'],
        'url' => $this->routeUrlIfAccessible('myeventlane_vendor.console.events', [], $account),
        'context' => [
          'type' => 'analytics',
          'entity_id' => NULL,
        ],
      ];
    }

    $actions = $this->actionGovernance->apply($actions);

    if (count($actions) > self::MAX_ACTIONS) {
      $actions = array_slice($actions, 0, self::MAX_ACTIONS);
    }

    return $actions;
  }

  /**
   * @param array<string, mixed> $readiness
   */
  private function getReadinessItemComplete(array $readiness, string $key): ?bool {
    $item = $this->getReadinessItem($readiness, $key);
    if (!is_array($item) || !array_key_exists('complete', $item)) {
      return NULL;
    }
    return (bool) $item['complete'];
  }

  /**
   * @param array<string, mixed> $readiness
   *
   * @return array<string, mixed>|null
   */
  private function getReadinessItem(array $readiness, string $key): ?array {
    $items = $readiness['items'] ?? [];
    if (!is_array($items)) {
      return NULL;
    }
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      if (($item['key'] ?? '') === $key) {
        return $item;
      }
    }
    return NULL;
  }

  /**
   * @param list<array<string, mixed>> $events
   */
  private function eventsIncludePaidOrBoth(array $events): bool {
    foreach ($events as $event) {
      if (!is_array($event)) {
        continue;
      }
      $type = (string) ($event['event_type'] ?? '');
      if ($type === 'paid' || $type === 'both') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $event
   */
  private function isPublishedLiveEvent(array $event): bool {
    $status = (string) ($event['status'] ?? '');
    return $status === 'upcoming' || $status === 'past';
  }

  /**
   * @param array<string, mixed> $event
   */
  private function isDraftLikeEvent(array $event): bool {
    $status = (string) ($event['status'] ?? '');
    if ($status === 'draft') {
      return TRUE;
    }
    $label = mb_strtolower((string) ($event['status_label'] ?? ''));
    foreach (['draft', 'unpublished', 'incomplete'] as $needle) {
      if ($label !== '' && str_contains($label, $needle)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $event
   */
  private function pickEventEditOrTicketsUrl(array $event): ?Url {
    $links = $event['links'] ?? [];
    if (!is_array($links)) {
      return NULL;
    }
    $edit = $links['edit'] ?? NULL;
    if ($edit instanceof Url) {
      return $edit;
    }
    $tickets = $links['tickets'] ?? NULL;
    return $tickets instanceof Url ? $tickets : NULL;
  }

  private function stripeDashboardUrl(AccountInterface $account): ?Url {
    $connectOptions = ['query' => ['return_to' => '/vendor/dashboard']];
    $url = $this->routeUrlIfAccessible('myeventlane_vendor.stripe_connect', [], $account, $connectOptions);
    if ($url instanceof Url) {
      return $url;
    }
    return $this->routeUrlIfAccessible('myeventlane_vendor.stripe_manage', [], $account);
  }

  private function routeExists(string $routeName): bool {
    try {
      $this->routeProvider->getRouteByName($routeName);
      return TRUE;
    }
    catch (RouteNotFoundException) {
      return FALSE;
    }
  }

  /**
   * @param array<string, mixed> $parameters
   * @param array<string, mixed> $options
   */
  private function routeUrl(string $routeName, array $parameters = [], array $options = []): ?Url {
    if (!$this->routeExists($routeName)) {
      return NULL;
    }
    try {
      return Url::fromRoute($routeName, $parameters, $options);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Action queue: failed to build URL for route @route: @message', [
        '@route' => $routeName,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * @param array<string, mixed> $parameters
   * @param array<string, mixed> $options
   */
  private function routeUrlIfAccessible(string $routeName, array $parameters = [], ?AccountInterface $account = NULL, array $options = []): ?Url {
    if ($account === NULL || !$account->isAuthenticated()) {
      return NULL;
    }
    if (!$this->routeExists($routeName)) {
      return NULL;
    }
    try {
      $access = $this->accessManager->checkNamedRoute($routeName, $parameters, $account, TRUE);
      if (!$access->isAllowed()) {
        return NULL;
      }
    }
    catch (\Throwable) {
      return NULL;
    }

    return $this->routeUrl($routeName, $parameters, $options);
  }

}
