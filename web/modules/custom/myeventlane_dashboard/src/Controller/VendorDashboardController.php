<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Controller;

use Drupal\node\NodeInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Drupal\myeventlane_boost\BoostManager;
use Drupal\myeventlane_core\Service\StripeService;
use Drupal\myeventlane_dashboard\Service\DashboardAccess;
use Drupal\myeventlane_dashboard\Service\DashboardEventLoader;
use Drupal\myeventlane_dashboard\Service\VendorContextServiceInterface;
use Drupal\myeventlane_dashboard\Service\VendorMetricsServiceInterface;
use Drupal\myeventlane_event_attendees\Service\AttendanceWaitlistManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for the vendor dashboard.
 */
final class VendorDashboardController extends ControllerBase {

  /**
   * Constructs a VendorDashboardController object.
   */
  public function __construct(
    private readonly DashboardAccess $dashboardAccess,
    private readonly DashboardEventLoader $eventLoader,
    private readonly TimeInterface $time,
    private readonly ?BoostManager $boostManager = NULL,
    private readonly ?AttendanceWaitlistManager $waitlistManager = NULL,
    private readonly ?StripeService $stripeService = NULL,
    private readonly ?VendorContextServiceInterface $vendorContext = NULL,
    private readonly ?VendorMetricsServiceInterface $vendorMetrics = NULL,
    private readonly ?RequestStack $requestStack = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    try {
      $boostManager = $container->get('myeventlane_boost.manager');
    }
    catch (\Exception) {
      $boostManager = NULL;
    }

    try {
      $waitlistManager = $container->get('myeventlane_event_attendees.waitlist');
    }
    catch (\Exception) {
      $waitlistManager = NULL;
    }

    try {
      $stripeService = $container->get('myeventlane_core.stripe');
    }
    catch (\Exception) {
      $stripeService = NULL;
    }

    try {
      $vendorContext = $container->get('myeventlane_dashboard.vendor_context');
    }
    catch (\Exception) {
      $vendorContext = NULL;
    }

    try {
      $vendorMetrics = $container->get('myeventlane_dashboard.metrics');
    }
    catch (\Exception) {
      $vendorMetrics = NULL;
    }

    return new static(
      $container->get('myeventlane_dashboard.access'),
      $container->get('myeventlane_dashboard.event_loader'),
      $container->get('datetime.time'),
      $boostManager,
      $waitlistManager,
      $stripeService,
      $vendorContext,
      $vendorMetrics,
      $container->get('request_stack'),
    );
  }

  /**
   * Redirects from old /dashboard path to /vendor/dashboard.
   */
  public function legacyRedirect(): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('myeventlane_vendor.console.dashboard')->toString(),
      301
    );
  }

  /**
   * Gets attendee and revenue stats for an event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array with 'attendee_count', 'rsvp_count', 'waitlist_count', 'revenue', 'mode', and action URLs.
   */
  private function getEventStats(int $eventId): array {
    // Get attendee count (ticket-based attendees).
    $attendeeCount = $this->entityTypeManager()
      ->getStorage('event_attendee')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->condition('status', 'confirmed')
      ->count()
      ->execute();

    // Get RSVP counts (separate from ticket attendees).
    $rsvpCount = 0;
    $waitlistCount = 0;
    try {
      $rsvpStorage = $this->entityTypeManager()->getStorage('rsvp_submission');
      $rsvpCount = (int) $rsvpStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', $eventId)
        ->condition('status', 'confirmed')
        ->count()
        ->execute();

      $waitlistCount = (int) $rsvpStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', $eventId)
        ->condition('status', 'waitlist')
        ->count()
        ->execute();
    }
    catch (\Exception) {
      // RSVP module may not be available.
    }

    // Get revenue from Commerce orders.
    // Find all order items linked to this event.
    $orderItemStorage = $this->entityTypeManager()->getStorage('commerce_order_item');
    $orderItems = $orderItemStorage->loadByProperties([
      'field_target_event' => $eventId,
    ]);

    $revenue = 0;
    foreach ($orderItems as $item) {
      // Check if order reference field exists and has a value before accessing.
      if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
        continue;
      }

      // Safely load the order entity to avoid getOrder() warnings.
      $order_id = $item->get('order_id')->target_id;
      if (!$order_id) {
        continue;
      }

      try {
        $order = $this->entityTypeManager()
          ->getStorage('commerce_order')
          ->load($order_id);
        // Only count revenue from completed orders.
        if ($order && $order->getState()->getId() === 'completed') {
          $totalPrice = $item->getTotalPrice();
          if ($totalPrice) {
            $revenue += (float) $totalPrice->getNumber();
          }
        }
      }
      catch (\Exception $e) {
        // Skip order items with broken or missing order references.
        // Log error for debugging but don't break the dashboard.
        \Drupal::logger('myeventlane_dashboard')->warning('Skipping order item @id with invalid order reference: @message', [
          '@id' => $item->id(),
          '@message' => $e->getMessage(),
        ]);
        continue;
      }
    }

    // Get event mode.
    $eventNode = $this->entityTypeManager()->getStorage('node')->load($eventId);
    $mode = 'unknown';
    if ($eventNode) {
      try {
        $modeManager = \Drupal::service('myeventlane_event.mode_manager');
        $mode = $modeManager->getEffectiveMode($eventNode);
      }
      catch (ServiceNotFoundException) {
        // Service not available, use default mode.
      }
    }

    // Build action URLs.
    $rsvpViewUrl = NULL;
    $rsvpExportUrl = NULL;
    $attendeeViewUrl = NULL;
    $attendeeExportUrl = NULL;

    try {
      $rsvpViewUrl = Url::fromRoute('myeventlane_rsvp.vendor_event_rsvps', ['event' => $eventId])->toString();
    }
    catch (RouteNotFoundException) {
      // Route may not exist if RSVP module is not enabled.
    }

    try {
      $rsvpExportUrl = Url::fromRoute('myeventlane_rsvp.export_csv', ['event' => $eventId])->toString();
    }
    catch (RouteNotFoundException) {
      // Route may not exist if RSVP module is not enabled.
    }

    try {
      $attendeeViewUrl = Url::fromRoute('myeventlane_event_attendees.vendor_list', ['node' => $eventId])->toString();
    }
    catch (RouteNotFoundException) {
      // Route may not exist if attendee module is not enabled.
    }

    try {
      $attendeeExportUrl = Url::fromRoute('myeventlane_event_attendees.vendor_export', ['node' => $eventId])->toString();
    }
    catch (RouteNotFoundException) {
      // Route may not exist if attendee module is not enabled.
    }

    // Get waitlist analytics if service is available.
    $waitlistAnalytics = NULL;
    if ($this->waitlistManager) {
      try {
        $waitlistAnalytics = $this->waitlistManager->getWaitlistAnalytics($eventId);
      }
      catch (\Exception) {
        // Analytics not available.
      }
    }

    // Get donation metrics (RSVP donations for this event).
    $donationTotal = 0;
    $donationCount = 0;
    try {
      if (\Drupal::hasService('myeventlane_donations.service')) {
        $donationService = \Drupal::service('myeventlane_donations.service');
        $donationStats = $donationService->getEventDonationStats($eventId);
        $donationTotal = $donationStats['total'] ?? 0;
        $donationCount = $donationStats['count'] ?? 0;
      }
    }
    catch (\Exception) {
      // Donations module may not be available.
    }

    return [
      'attendee_count' => (int) $attendeeCount,
      'rsvp_count' => $rsvpCount,
      'waitlist_count' => $waitlistCount,
      'revenue' => $revenue,
      'donation_total' => $donationTotal,
      'donation_count' => $donationCount,
      'mode' => $mode,
      'waitlist_analytics' => $waitlistAnalytics,
      'rsvp_view_url' => $rsvpViewUrl,
      'rsvp_export_url' => $rsvpExportUrl,
      'attendee_view_url' => $attendeeViewUrl,
      'attendee_export_url' => $attendeeExportUrl,
    ];
  }

  /**
   * Renders the vendor dashboard page.
   *
   * @return array
   *   A render array for the vendor dashboard.
   */
  public function dashboard(): array {
    $currentUser = $this->currentUser();
    // Load events for the current user (admin=FALSE loads only user's events).
    $events = $this->eventLoader->loadEvents(FALSE, 50);

    // Build event summary data.
    $upcomingEvents = [];
    $pastEvents = [];
    $now = $this->time->getRequestTime();

    foreach ($events as $event) {
      $startTime = NULL;

      // Get start time from datetime field.
      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        $dateItem = $event->get('field_event_start');
        if ($dateItem->date) {
          $startTime = $dateItem->date->getTimestamp();
        }
        // Fallback to value parsing if date object not available.
        elseif (!empty($dateItem->value)) {
          try {
            $startTime = strtotime($dateItem->value);
          }
          catch (\Exception) {
            // Ignore date parsing errors.
          }
        }
      }

      // Get attendee counts and revenue for this event.
      $stats = $this->getEventStats((int) $event->id());

      // Get Boost status.
      $boostStatus = $this->getBoostStatus($event);

      $eventData = [
        'id' => $event->id(),
        'title' => $event->label(),
        'url' => $event->toUrl()->toString(),
        // Use wizard route for editing (vendors never see default node edit form).
        'edit_url' => Url::fromRoute('myeventlane_event.wizard.edit', ['node' => $event->id()])->toString(),
        'start_date' => $startTime ? date('M j, Y', $startTime) : '',
        'start_time' => $startTime ? date('g:ia', $startTime) : '',
        'attendee_count' => $stats['attendee_count'],
        'rsvp_count' => $stats['rsvp_count'] ?? 0,
        'waitlist_count' => $stats['waitlist_count'] ?? 0,
        'revenue' => $stats['revenue'],
        'donation_total' => $stats['donation_total'] ?? 0,
        'donation_count' => $stats['donation_count'] ?? 0,
        'event_mode' => $stats['mode'],
        'waitlist_analytics' => $stats['waitlist_analytics'] ?? NULL,
        'boost_status' => $boostStatus,
        'rsvp_view_url' => $stats['rsvp_view_url'] ?? NULL,
        'rsvp_export_url' => $stats['rsvp_export_url'] ?? NULL,
        'attendee_view_url' => $stats['attendee_view_url'] ?? NULL,
        'attendee_export_url' => $stats['attendee_export_url'] ?? NULL,
      ];

      // Categorize events: upcoming if start time is in the future, otherwise past.
      // Events without dates go to past events.
      if ($startTime && $startTime > $now) {
        $upcomingEvents[] = $eventData;
      }
      else {
        $pastEvents[] = $eventData;
      }
    }

    // Quick action links.
    $quickLinks = [
      [
        'title' => $this->t('Create Event'),
        // Use canonical wizard route for event creation.
        'url' => Url::fromRoute('myeventlane_event.wizard.create')->toString(),
        'icon' => 'add',
      ],
    ];

    // Add donations link if donations module is available.
    try {
      $donationsUrl = Url::fromRoute('myeventlane_donations.vendor_list');
      $quickLinks[] = [
        'title' => $this->t('View Donations'),
        'url' => $donationsUrl->toString(),
        'icon' => 'donations',
      ];
    }
    catch (\Exception) {
      // Donations module may not be available.
    }

    // Add analytics link if module is available.
    try {
      $analyticsUrl = Url::fromRoute('myeventlane_analytics.dashboard');
      $quickLinks[] = [
        'title' => $this->t('View Analytics'),
        'url' => $analyticsUrl->toString(),
        'icon' => 'analytics',
      ];
    }
    catch (\Exception) {
      // Analytics module not available.
    }

    // Get Stripe connection status.
    $stripeStatus = $this->getStripeStatus();

    // Add Stripe Connect links to quick actions.
    if ($stripeStatus) {
      if (!$stripeStatus['connected'] && $stripeStatus['connect_url']) {
        $quickLinks[] = [
          'title' => $this->t('Connect Stripe'),
          'url' => $stripeStatus['connect_url'],
          'icon' => 'payment',
        ];
      }
      if ($stripeStatus['connected'] && $stripeStatus['manage_url']) {
        $quickLinks[] = [
          'title' => $this->t('Manage Stripe Account'),
          'url' => $stripeStatus['manage_url'],
          'icon' => 'settings',
        ];
      }
    }

    // Admin-only debug mode.
    $debug_data = NULL;
    $request = $this->requestStack?->getCurrentRequest();
    $debug_enabled = $request && (string) $request->query->get('debug') === '1';
    $is_admin = $currentUser->id() === 1 || $currentUser->hasPermission('administer commerce_store');

    if ($debug_enabled && $is_admin && $this->vendorContext && $this->vendorMetrics) {
      try {
        $store = $this->vendorContext->getCurrentVendorStore();
        $range = [
          'start' => 0,
          'end' => $this->time->getRequestTime(),
          'key' => 'all_time',
        ];
        $metrics_debug = $this->vendorMetrics->getDebugTotals($store, $range);

        $debug_data = [
          'store_id' => (int) $store->id(),
          'store_label' => (string) $store->label(),
          'range_key' => (string) ($range['key'] ?? ''),
          'range_start' => (int) ($range['start'] ?? 0),
          'range_end' => (int) ($range['end'] ?? 0),
          'gross_cents' => (int) $metrics_debug['gross_cents'],
          'refund_cents' => (int) $metrics_debug['refund_cents'],
          'net_cents' => (int) $metrics_debug['net_cents'],
          'tickets_sold' => (int) $metrics_debug['tickets_sold'],
          'confirmed_rsvps' => (int) $metrics_debug['confirmed_rsvps'],
          'events_loaded' => (int) count($upcomingEvents) + count($pastEvents),
        ];

        // Log debug information.
        \Drupal::logger('myeventlane_vendor_dashboard')->info('Dashboard debug: store_id=@sid, range=@key (@start → @end), gross=@gross_cents, refund=@refund_cents, net=@net_cents, tickets=@tickets, rsvps=@rsvps, events=@events', [
          '@sid' => (string) $debug_data['store_id'],
          '@key' => $debug_data['range_key'],
          '@start' => (string) $debug_data['range_start'],
          '@end' => (string) $debug_data['range_end'],
          '@gross_cents' => (string) $debug_data['gross_cents'],
          '@refund_cents' => (string) $debug_data['refund_cents'],
          '@net_cents' => (string) $debug_data['net_cents'],
          '@tickets' => (string) $debug_data['tickets_sold'],
          '@rsvps' => (string) $debug_data['confirmed_rsvps'],
          '@events' => (string) $debug_data['events_loaded'],
        ]);
      }
      catch (\Exception $e) {
        // Silently fail if debug cannot be generated.
      }
    }

    return [
      '#theme' => 'myeventlane_vendor_dashboard',
      '#upcoming_events' => $upcomingEvents,
      '#past_events' => $pastEvents,
      '#quick_links' => $quickLinks,
      '#stripe_status' => $stripeStatus,
      '#welcome_message' => $this->t('Welcome back, @name. Here is an overview of your events.', [
        '@name' => $currentUser->getDisplayName(),
      ]),
      '#dashboard' => [
        'debug' => $debug_data,
      ],
      '#attached' => [
        'library' => ['myeventlane_dashboard/dashboard'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list', 'user:' . $currentUser->id()],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Gets Stripe connection status for the current user's store.
   *
   * @return array
   *   Array with 'connected', 'status', 'account_id', 'connect_url', 'manage_url'.
   */
  private function getStripeStatus(): array {
    $currentUser = $this->currentUser();
    $userId = (int) $currentUser->id();

    if ($userId === 0) {
      return [
        'connected' => FALSE,
        'status' => 'unconnected',
        'status_label' => $this->t('Not Connected'),
        'status_message' => $this->t('Connect your Stripe account to start accepting payments.'),
        'account_id' => NULL,
        'connect_url' => NULL,
        'manage_url' => NULL,
      ];
    }

    // Find store for this user.
    $storeStorage = $this->entityTypeManager()->getStorage('commerce_store');
    $storeIds = $storeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $userId)
      ->range(0, 1)
      ->execute();

    if (empty($storeIds)) {
      return [
        'connected' => FALSE,
        'status' => 'unconnected',
        'account_id' => NULL,
        'connect_url' => Url::fromRoute('myeventlane_vendor.stripe_connect')->toString(),
        'manage_url' => NULL,
      ];
    }

    $store = $storeStorage->load(reset($storeIds));
    if (!$store) {
      return [
        'connected' => FALSE,
        'status' => 'unconnected',
        'account_id' => NULL,
        'connect_url' => Url::fromRoute('myeventlane_vendor.stripe_connect')->toString(),
        'manage_url' => NULL,
      ];
    }

    // Check for Stripe account ID.
    // VALIDATE STRIPE ACCOUNT ID: Only use if it exists, is not empty, and has valid format.
    $accountId = NULL;
    $status = 'unconnected';
    $connected = FALSE;

    if ($store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
      $rawAccountId = trim($store->get('field_stripe_account_id')->value);
      // Only accept valid Stripe account IDs (format: acct_xxxxx).
      if (!empty($rawAccountId) && str_starts_with($rawAccountId, 'acct_')) {
        $accountId = $rawAccountId;
      }
    }

    // Check charges enabled status - if charges are enabled, account is functional.
    $chargesEnabled = FALSE;
    if ($store->hasField('field_stripe_charges_enabled') && !$store->get('field_stripe_charges_enabled')->isEmpty()) {
      $chargesEnabled = (bool) $store->get('field_stripe_charges_enabled')->value;
    }

    // Determine status based on available fields.
    if ($store->hasField('field_stripe_status') && !$store->get('field_stripe_status')->isEmpty()) {
      $status = $store->get('field_stripe_status')->value;
    }
    elseif ($chargesEnabled) {
      // If charges are enabled, account is connected and functional.
      $status = 'complete';
      $connected = TRUE;
    }
    elseif ($store->hasField('field_stripe_connected') && !$store->get('field_stripe_connected')->isEmpty()) {
      $connected = (bool) $store->get('field_stripe_connected')->value;
      $status = $connected ? 'complete' : 'pending';
    }
    elseif (!empty($accountId)) {
      // Account ID exists but no status info - assume pending.
      $status = 'pending';
    }

    // Override: if charges are enabled, mark as connected regardless of other flags.
    if ($chargesEnabled) {
      $connected = TRUE;
      $status = 'complete';
    }
    elseif ($status === 'complete') {
      $connected = TRUE;
    }

    // Build status labels and messages.
    $statusLabel = $this->t('Not Connected');
    $statusMessage = $this->t('Connect your Stripe account to start accepting payments.');

    if ($connected) {
      $statusLabel = $this->t('Connected');
      $statusMessage = $this->t('Your Stripe account is active and ready to accept payments.');
    }
    elseif ($status === 'pending') {
      $statusLabel = $this->t('Pending');
      $statusMessage = $this->t('Your Stripe account is pending verification. Complete onboarding to start accepting payments.');
    }

    // CHECK ELIGIBILITY: Only return manage_url if account ID is valid AND account is eligible.
    // This prevents UI from showing broken Stripe buttons and ensures we never call
    // createLoginLink() on accounts that aren't dashboard-eligible.
    $manageUrl = NULL;
    $dashboardEligible = FALSE;

    if (!empty($accountId) && str_starts_with($accountId, 'acct_')) {
      // Check eligibility if StripeService is available.
      if ($this->stripeService) {
        try {
          $eligibility = $this->stripeService->validateAccountDashboardEligibility($accountId);
          $dashboardEligible = $eligibility['eligible'];

          if (!$dashboardEligible) {
            // Account exists but is not eligible - don't show manage button.
            // Log at notice level (expected for incomplete accounts).
            \Drupal::logger('myeventlane_vendor')->notice('Stripe dashboard: Account @account_id is not eligible for dashboard access. Reason: @reason', [
              '@account_id' => $accountId,
              '@reason' => $eligibility['reason'] ?? 'Unknown',
            ]);

            // Update status message to indicate onboarding incomplete.
            if ($status === 'pending' || $status === 'unconnected') {
              $statusMessage = $this->t('Stripe onboarding incomplete. Complete onboarding to access dashboard.');
            }
          }
        }
        catch (\Exception $e) {
          // If eligibility check fails, don't show manage URL (safe default).
          \Drupal::logger('myeventlane_vendor')->error('Stripe dashboard: Failed to check eligibility for account @account_id: @message', [
            '@account_id' => $accountId,
            '@message' => $e->getMessage(),
          ]);
        }
      }

      // Only provide manage_url if account is eligible.
      if ($dashboardEligible) {
        $manageUrl = Url::fromRoute('myeventlane_vendor.stripe_manage')->toString();
      }
    }

    return [
      'connected' => $connected,
      'status' => $status,
      'status_label' => $statusLabel,
      'status_message' => $statusMessage,
      'account_id' => $accountId,
      'dashboard_eligible' => $dashboardEligible,
      'connect_url' => Url::fromRoute('myeventlane_vendor.stripe_connect')->toString(),
      'manage_url' => $manageUrl,
    ];
  }

  /**
   * Gets Boost status for an event.
   *
   * Uses canonical BoostManager::getBoostStatusForEvent() to avoid duplicating
   * boost logic.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array with 'is_boosted', 'expires_date', 'boost_url', 'can_boost', and button data.
   */
  private function getBoostStatus(NodeInterface $event): array {
    if (!$this->boostManager) {
      $isPublished = $event->isPublished();
      return [
        'is_boosted' => FALSE,
        'is_expired' => FALSE,
        'expires_date' => NULL,
        'boost_url' => NULL,
        'can_boost' => FALSE,
        'allowed' => $isPublished,
        'label' => $isPublished ? 'Boost event' : 'Publish to boost',
      ];
    }

    // Use canonical API to get complete boost status.
    $boostStatus = $this->boostManager->getBoostStatusForEvent($event);
    $isBoosted = $boostStatus['active'];
    $isExpired = $boostStatus['expired'];
    $isPublished = $event->isPublished();

    // Format expiry date if available.
    $expiresDate = NULL;
    if ($boostStatus['end_timestamp']) {
      $expiresDate = date('M j, Y', $boostStatus['end_timestamp']);
    }

    return [
      'is_boosted' => $isBoosted,
      'is_expired' => $isExpired,
      'expires_date' => $expiresDate,
      'boost_url' => $isPublished
        ? Url::fromRoute('myeventlane_boost.boost_page', ['node' => $event->id()])->toString()
        : NULL,
      'can_boost' => $isPublished,
      'allowed' => $isPublished,
      'label' => $isPublished ? 'Boost event' : 'Publish to boost',
    ];
  }

}
