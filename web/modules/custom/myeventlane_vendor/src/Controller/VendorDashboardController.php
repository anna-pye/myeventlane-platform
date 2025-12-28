<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_vendor\Service\MetricsAggregator;
use Drupal\myeventlane_vendor\Service\RsvpStatsService;
use Drupal\myeventlane_vendor\Service\BoostStatusService;
use Drupal\myeventlane_vendor\Service\TicketSalesService;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Vendor dashboard controller - Full functional control centre.
 */
final class VendorDashboardController extends VendorConsoleBaseController {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * RSVP stats service.
   */
  protected RsvpStatsService $rsvpStats;

  /**
   * Ticket sales service.
   */
  protected TicketSalesService $ticketSales;

  /**
   * Boost status service.
   */
  protected BoostStatusService $boostStatus;

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    RsvpStatsService $rsvp_stats,
    EntityTypeManagerInterface $entity_type_manager,
    BoostStatusService $boost_status,
    TicketSalesService $ticket_sales,
  ) {
    parent::__construct($domain_detector, $current_user);
    $this->rsvpStats = $rsvp_stats;
    $this->entityTypeManager = $entity_type_manager;
    $this->boostStatus = $boost_status;
    $this->ticketSales = $ticket_sales;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('myeventlane_vendor.service.rsvp_stats'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_vendor.service.boost_status'),
      $container->get('myeventlane_vendor.service.ticket_sales'),
    );
  }

  /**
   * Displays the vendor dashboard.
   */
  public function dashboard(): array {
    $userId = (int) $this->currentUser->id();

    // Load vendor entity for current user.
    $vendor = $this->getCurrentVendorOrNull();
    $vendorEditUrl = NULL;
    if ($vendor) {
      $vendorEditUrl = Url::fromRoute('entity.myeventlane_vendor.edit_form', [
        'myeventlane_vendor' => $vendor->id(),
      ]);
    }

    // Load vendor's events once for all queries.
    $userEvents = $this->getUserEvents($userId);

    // Build all dashboard data.
    $kpis = $this->buildKpiCards($userId, $userEvents);
    $events = $this->getEventsTableData($userEvents);
    $bestEvent = $this->getBestPerformingEvent($userEvents);
    $stripeStatus = $this->getStripeConnectStatus($userId);
    $notifications = $this->getNotifications($userId, $userEvents);
    $accountSummary = $this->getAccountSummary($userId);
    $quickActions = $this->getQuickActions();
    $upcomingCount = $this->getUpcomingEventsCount($userEvents);

    // Chart configurations.
    $charts = [
      ['id' => 'revenue', 'title' => 'Revenue Over Time', 'type' => 'line'],
      ['id' => 'tickets-by-type', 'title' => 'Tickets by Type', 'type' => 'donut'],
      ['id' => 'traffic-sources', 'title' => 'Traffic Sources', 'type' => 'bar'],
    ];

    // Check if new vendor (show welcome banner).
    $showWelcome = empty($userEvents);

    // Chart data for JavaScript.
    $chartData = $this->buildChartData($userId, $userEvents);

    // Format stripe status message for template.
    $stripeStatusFormatted = $stripeStatus;
    if (!$stripeStatus['connected']) {
      $stripeStatusFormatted['status_message'] = $this->t('Connect your Stripe account to receive payments from ticket sales and donations.');
    }
    else {
      $stripeStatusFormatted['status_message'] = $this->t('Your Stripe account is connected and ready to receive payments.');
    }

    // Use vendor theme template format (matches myeventlane_vendor_theme).
    return $this->buildVendorPage('myeventlane_vendor_dashboard', [
      'vendor' => $vendor,
      'vendor_edit_url' => $vendorEditUrl,
      'kpis' => $kpis,
      'charts' => $charts,
      'events' => $events,
      'best_event' => $bestEvent,
      'stripe' => $stripeStatusFormatted,
      'notifications' => $notifications,
      'account' => $accountSummary,
      'quick_actions' => $quickActions,
      'upcoming_count' => $upcomingCount,
      'show_welcome' => $showWelcome,
      '#attached' => [
        'library' => [
          'myeventlane_vendor_theme/global-styling',
        ],
        'drupalSettings' => [
          'vendorCharts' => $chartData,
        ],
      ],
    ]);
  }

  /**
   * Get all events owned by user (includes drafts).
   *
   * Used for display purposes (event list, notifications).
   * For analytics, use getPublishedUserEvents() instead.
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return array
   *   Array of event node IDs. Returns empty array if no events or invalid user.
   */
  private function getUserEvents(int $userId): array {
    if ($userId <= 0) {
      return [];
    }

    try {
      return $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'event')
        ->condition('uid', $userId)
        ->execute();
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Get published events owned by user (excludes drafts).
   *
   * Used for analytics calculations.
   * All vendor analytics exclude draft events.
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return array
   *   Array of published event node IDs. Returns empty array if no events or invalid user.
   */
  private function getPublishedUserEvents(int $userId): array {
    if ($userId <= 0) {
      return [];
    }

    try {
      return $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'event')
        ->condition('uid', $userId)
        ->condition('status', 1)
        ->execute();
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Safely gets the order from an order item.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The order entity, or NULL if not available.
   */
  private function getOrderFromItem(OrderItemInterface $order_item) {
    if (!$order_item->hasField('order_id') || $order_item->get('order_id')->isEmpty()) {
      return NULL;
    }

    // Get the target_id from field value to avoid triggering Commerce's lazy loading.
    // Access the field value directly without triggering entity loading.
    $field_value = $order_item->get('order_id')->getValue();
    if (empty($field_value) || !isset($field_value[0]['target_id'])) {
      return NULL;
    }

    $order_id = $field_value[0]['target_id'];
    if (!$order_id) {
      return NULL;
    }

    try {
      return $this->entityTypeManager
        ->getStorage('commerce_order')
        ->load($order_id);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Build comprehensive KPI card data.
   *
   * Uses TicketSalesService and RsvpStatsService to avoid duplicate calculations.
   * All metrics exclude draft events (only published events are counted).
   * Metrics:
   * - Total Revenue: Gross revenue from completed orders (published events only)
   * - Last 30 Days Revenue: Revenue from orders completed in last 30 days
   * - Upcoming Events: Published events with start date in the future
   * - Total Events: All events (published + drafts) for context
   * - Tickets Sold: Total tickets from completed orders (published events only)
   * - RSVPs: Total confirmed RSVPs (published events only)
   *
   * @param int $userId
   *   The vendor user ID.
   * @param array $userEvents
   *   All events (includes drafts) - used for total event count display only.
   *
   * @return array
   *   Array of KPI card arrays. Returns empty array if services unavailable.
   */
  private function buildKpiCards(int $userId, array $userEvents): array {
    // Defensive guard: ensure services are available.
    if (!$this->rsvpStats || !$this->ticketSales) {
      return [];
    }

    if ($userId <= 0) {
      return [];
    }

    // Get published events for analytics (excludes drafts).
    $publishedEvents = $this->getPublishedUserEvents($userId);
    $eventCount = count($userEvents);

    // Use TicketSalesService for revenue metrics (includes published filter).
    $revenue = $this->ticketSales->getVendorRevenue($userId);
    $totalRevenue = $revenue['gross_raw'] ?? 0.0;
    $ticketsSold = $revenue['tickets'] ?? 0;

    // Calculate last 30 days revenue using TicketSalesService method.
    $thirtyDaysAgo = strtotime('-30 days');
    $last30DaysRevenue = 0.0;
    try {
      $last30DaysRevenue = $this->ticketSales->getVendorRevenueInRange($userId, $thirtyDaysAgo);
    }
    catch (\Exception $e) {
      // Default to 0 if method fails.
    }

    // Use RsvpStatsService for RSVP count (includes published filter).
    $total_rsvps = 0;
    try {
      $total_rsvps = $this->rsvpStats->getVendorRsvpCount($userId);
    }
    catch (\Exception $e) {
      // Default to 0 if service fails.
    }

    // Get upcoming events count (filters by published internally).
    $upcomingCount = $this->getUpcomingEventsCount($publishedEvents);

    return [
      [
        'label' => 'Total Revenue',
        'value' => number_format($totalRevenue, 0),
        'currency' => '$',
        'icon' => 'revenue',
        'color' => 'coral',
        'delta' => $last30DaysRevenue > 0 ? [
          'value' => '$' . number_format($last30DaysRevenue, 0),
          'label' => 'last 30 days',
          'positive' => TRUE,
        ] : NULL,
        'highlight' => TRUE,
      ],
      [
        'label' => 'Upcoming Events',
        'value' => (string) $upcomingCount,
        'icon' => 'calendar',
        'color' => 'blue',
        'delta' => [
          'value' => (string) $eventCount,
          'label' => 'total events',
          'positive' => TRUE,
        ],
      ],
      [
        'label' => 'Tickets Sold',
        'value' => (string) $ticketsSold,
        'icon' => 'tickets',
        'color' => 'green',
        'delta' => NULL,
      ],
      [
        'label' => 'RSVPs',
        'value' => (string) $total_rsvps,
        'icon' => 'users',
        'color' => 'purple',
        'delta' => NULL,
      ],
    ];
  }

  /**
   * Get upcoming events count (published events with future start date).
   *
   * Counts: Published events (status=1) with start date >= now.
   * Excludes: Draft events, past events.
   * Tables: node (event).
   *
   * @param array $eventIds
   *   Array of event node IDs (should be published events only).
   *
   * @return int
   *   Count of upcoming events. Returns 0 if no events, empty array, or on error.
   */
  private function getUpcomingEventsCount(array $eventIds): int {
    if (empty($eventIds)) {
      return 0;
    }

    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $now = date('Y-m-d\TH:i:s');

      return (int) $nodeStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('nid', $eventIds, 'IN')
        ->condition('status', 1)
        ->condition('field_event_start', $now, '>=')
        ->count()
        ->execute();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Get events table data with full details.
   *
   * Loads event nodes and builds table data including revenue, tickets, RSVPs.
   * Uses TicketSalesService and RsvpStatsService to avoid duplicate calculations.
   * Includes both published and draft events for display purposes.
   *
   * @param array $userEvents
   *   Array of event node IDs (can include drafts).
   *
   * @return array
   *   Array of event data arrays. Returns empty array if no events, services unavailable,
   *   or on error. Each event array includes: id, title, venue, date, status, revenue,
   *   tickets_sold, rsvps, boost data, URLs, etc.
   */
  private function getEventsTableData(array $userEvents): array {
    if (empty($userEvents)) {
      return [];
    }

    // Guard against null services during container rebuilds.
    if (!$this->boostStatus || !$this->ticketSales || !$this->rsvpStats) {
      return [];
    }

    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $nodes = $nodeStorage->loadMultiple($userEvents);
    }
    catch (\Exception $e) {
      // Entity storage may fail during container rebuilds.
      return [];
    }

    if (empty($nodes)) {
      return [];
    }

    $events = [];

    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }

      $eventId = (int) $node->id();

      // Get event date.
      $startDate = '';
      $startTimestamp = 0;
      if ($node->hasField('field_event_start') && !$node->get('field_event_start')->isEmpty()) {
        $dateItem = $node->get('field_event_start');
        if ($dateItem->date) {
          $startDate = $dateItem->date->format('M j, Y');
          $startTimestamp = $dateItem->date->getTimestamp();
        }
        elseif (!empty($dateItem->value)) {
          $startDate = date('M j, Y', strtotime($dateItem->value));
          $startTimestamp = strtotime($dateItem->value);
        }
      }

      // Get venue name.
      $venue = '';
      if ($node->hasField('field_event_venue') && !$node->get('field_event_venue')->isEmpty()) {
        $venue = $node->get('field_event_venue')->value;
      }
      elseif ($node->hasField('field_location') && !$node->get('field_location')->isEmpty()) {
        $venue = $node->get('field_location')->value;
      }

      // Get status.
      $status = 'draft';
      $statusLabel = 'Draft';
      if ($node->isPublished()) {
        if ($startTimestamp > 0 && $startTimestamp < time()) {
          $status = 'past';
          $statusLabel = 'Past';
        }
        else {
          $status = 'on-sale';
          $statusLabel = 'On Sale';
        }
      }

      // Get revenue and ticket counts using services (avoids duplicate calculations).
      $salesSummary = [];
      try {
        if ($this->ticketSales) {
          $salesSummary = $this->ticketSales->getSalesSummary($node);
        }
      }
      catch (\Exception $e) {
        // Service may fail, use defaults.
      }
      $revenue = $salesSummary['gross_raw'] ?? 0.0;
      $ticketsSold = $salesSummary['tickets_sold'] ?? 0;

      $rsvps = 0;
      try {
        if ($this->rsvpStats) {
          $rsvps = $this->rsvpStats->getEventRsvpCount($eventId);
        }
      }
      catch (\Exception $e) {
        // Service may fail, use default 0.
      }

      // Get waitlist analytics.
      $waitlistAnalytics = $this->getEventWaitlistAnalytics($eventId);

      // Get RSVP stats and boost status with defensive checks.
      try {
        $stats = $this->rsvpStats->getStatsForEvent($eventId);
      }
      catch (\Exception $e) {
        $stats = ['total' => 0, 'recent' => 0];
      }

      try {
        $boostData = $this->boostStatus->getBoostStatuses($eventId);
      }
      catch (\Exception $e) {
        $boostData = [
          'eligible' => FALSE,
          'active' => FALSE,
          'reason' => 'error',
        ];
      }

      $isBoosted = !empty($boostData['active']);
      $isPublished = $node->isPublished();
      $isEligible = !empty($boostData['eligible']);

      // Build boost button data with proper state handling.
      $boost = [
        'allowed' => $isPublished && $isEligible,
        'label' => $isBoosted ? 'Boost active' : ($isPublished ? 'Boost event' : 'Publish to boost'),
        'url' => ($isPublished && $isEligible)
          ? Url::fromRoute('myeventlane_boost.boost_page', ['node' => $eventId])->toString()
          : NULL,
        'is_boosted' => $isBoosted,
        'message' => !$isPublished ? 'Publish this event to enable boosting.' : ($boostData['reason'] ?? NULL),
      ];

      $events[] = [
        'id' => $eventId,
        'title' => $node->label(),
        'venue' => $venue,
        'date' => $startDate,
        'start_timestamp' => $startTimestamp,
        'status' => $status,
        'status_label' => $statusLabel,
        'revenue' => $revenue,
        'revenue_formatted' => '$' . number_format($revenue, 0),
        'tickets_sold' => $ticketsSold,
        'rsvps' => $rsvps,
        'waitlist' => $waitlistAnalytics,
        'rsvp' => $stats,
        'boost' => $boost,
        'view_url' => $node->toUrl()->toString(),
        // Use wizard route for editing (vendors never see default node edit form).
        'edit_url' => Url::fromRoute('myeventlane_event.wizard.edit', ['node' => $eventId])->toString(),
        'manage_url' => '/vendor/events/' . $eventId . '/overview',
        'tickets_url' => '/vendor/events/' . $eventId . '/tickets',
        'analytics_url' => '/vendor/analytics/event/' . $eventId,
        'attendees_url' => '/vendor/events/' . $eventId . '/attendees',
        'waitlist_url' => '/vendor/event/' . $eventId . '/waitlist',
      ];
    }

    // Sort by start date descending (newest first).
    usort($events, fn($a, $b) => $b['start_timestamp'] <=> $a['start_timestamp']);

    return $events;
  }

  /**
   * Safely gets event revenue using TicketSalesService.
   *
   * Counts: Completed orders only (order state='completed').
   * Excludes: Draft/cancelled/refunded orders.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return float
   *   Total revenue. Returns 0.0 if service unavailable, no sales, or on error.
   */
  private function getEventRevenueSafe(NodeInterface $event): float {
    if (!$this->ticketSales) {
      return 0.0;
    }

    try {
      $salesSummary = $this->ticketSales->getSalesSummary($event);
      return $salesSummary['gross_raw'] ?? 0.0;
    }
    catch (\Exception $e) {
      return 0.0;
    }
  }

  /**
   * Safely gets event tickets sold using TicketSalesService.
   *
   * Counts: Completed orders only (order state='completed').
   * Excludes: Draft/cancelled/refunded orders.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return int
   *   Total tickets sold. Returns 0 if service unavailable, no sales, or on error.
   */
  private function getEventTicketsSoldSafe(NodeInterface $event): int {
    if (!$this->ticketSales) {
      return 0;
    }

    try {
      $salesSummary = $this->ticketSales->getSalesSummary($event);
      return $salesSummary['tickets_sold'] ?? 0;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Get best performing event.
   */
  private function getBestPerformingEvent(array $userEvents): ?array {
    if (empty($userEvents)) {
      return NULL;
    }

    $events = $this->getEventsTableData($userEvents);
    if (empty($events)) {
      return NULL;
    }

    // Calculate score for each event.
    // Score = tickets_sold * 0.7 + revenue * 0.2 + rsvps * 0.1.
    $bestEvent = NULL;
    $bestScore = 0;

    foreach ($events as $event) {
      $score = ($event['tickets_sold'] * 0.7)
        + ($event['revenue'] * 0.002) // Normalize revenue
        + ($event['rsvps'] * 0.1);

      if ($score > $bestScore) {
        $bestScore = $score;
        $bestEvent = $event;
      }
    }

    if ($bestEvent) {
      $bestEvent['score'] = $bestScore;
      // Calculate conversion rate (placeholder - would need views data).
      $bestEvent['conversion_rate'] = NULL;
    }

    return $bestEvent;
  }

  /**
   * Get Stripe Connect status for vendor.
   */
  private function getStripeConnectStatus(int $userId): array {
    $status = [
      'connected' => FALSE,
      'status' => 'not_connected',
      'status_label' => 'Not Connected',
      'account_id' => NULL,
      'next_payout_date' => NULL,
      'total_paid_out' => 0,
      'pending_balance' => 0,
      'stripe_dashboard_url' => NULL,
      'connect_url' => '/vendor/stripe/connect',
    ];

    // Check for Stripe Connect entity or commerce_store.
    try {
      $user = $this->entityTypeManager->getStorage('user')->load($userId);
      if ($user instanceof UserInterface) {
        // Check if user has a Stripe account field.
        if ($user->hasField('field_stripe_account_id') && !$user->get('field_stripe_account_id')->isEmpty()) {
          $status['connected'] = TRUE;
          $status['status'] = 'connected';
          $status['status_label'] = 'Connected';
          $status['account_id'] = $user->get('field_stripe_account_id')->value;
          $status['stripe_dashboard_url'] = 'https://dashboard.stripe.com';
        }
      }

      // Try to get from commerce_store.
      $stores = $this->entityTypeManager->getStorage('commerce_store')
        ->loadByProperties(['uid' => $userId]);

      if (!empty($stores)) {
        $store = reset($stores);
        if ($store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
          $status['connected'] = TRUE;
          $status['status'] = 'connected';
          $status['status_label'] = 'Connected';
          $status['account_id'] = $store->get('field_stripe_account_id')->value;
          $status['stripe_dashboard_url'] = 'https://dashboard.stripe.com';
        }
      }
    }
    catch (\Exception $e) {
      // Stripe Connect may not be configured.
    }

    return $status;
  }

  /**
   * Get notifications/alerts for vendor.
   */
  private function getNotifications(int $userId, array $userEvents): array {
    $notifications = [];

    if (empty($userEvents)) {
      return $notifications;
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nodes = $nodeStorage->loadMultiple($userEvents);
    $now = time();
    $threeDaysFromNow = $now + (3 * 24 * 60 * 60);

    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }

      // Check for events starting soon.
      if ($node->hasField('field_event_start') && !$node->get('field_event_start')->isEmpty()) {
        $dateItem = $node->get('field_event_start');
        $startTimestamp = 0;
        if ($dateItem->date) {
          $startTimestamp = $dateItem->date->getTimestamp();
        }
        elseif (!empty($dateItem->value)) {
          $startTimestamp = strtotime($dateItem->value);
        }

        if ($startTimestamp > $now && $startTimestamp <= $threeDaysFromNow) {
          $daysUntil = ceil(($startTimestamp - $now) / 86400);
          $notifications[] = [
            'type' => 'info',
            'icon' => 'calendar',
            'message' => t('@title starts in @days day(s)', [
              '@title' => $node->label(),
              '@days' => $daysUntil,
            ]),
            'url' => '/vendor/events/' . $node->id() . '/overview',
          ];
        }
      }

      // Check for missing event image.
      if ($node->hasField('field_event_image') && $node->get('field_event_image')->isEmpty()) {
        $notifications[] = [
          'type' => 'warning',
          'icon' => 'image',
          'message' => t('@title is missing a cover image', [
            '@title' => $node->label(),
          ]),
          'url' => $node->toUrl('edit-form')->toString(),
        ];
      }

      // Check for draft events.
      if (!$node->isPublished()) {
        $notifications[] = [
          'type' => 'neutral',
          'icon' => 'edit',
          'message' => t('@title is still in draft', [
            '@title' => $node->label(),
          ]),
          'url' => $node->toUrl('edit-form')->toString(),
        ];
      }
    }

    // Check Stripe status.
    $stripeStatus = $this->getStripeConnectStatus($userId);
    if (!$stripeStatus['connected']) {
      $notifications[] = [
        'type' => 'warning',
        'icon' => 'credit-card',
        'message' => t('Connect Stripe to receive payouts'),
        'url' => '/vendor/payouts',
      ];
    }

    // Limit to 5 notifications.
    return array_slice($notifications, 0, 5);
  }

  /**
   * Get account summary for vendor.
   */
  private function getAccountSummary(int $userId): array {
    $account = [
      'display_name' => '',
      'email' => '',
      'store_name' => '',
      'last_login' => NULL,
    ];

    try {
      $user = $this->entityTypeManager->getStorage('user')->load($userId);
      if ($user instanceof UserInterface) {
        $account['display_name'] = $user->getDisplayName();
        $account['email'] = $user->getEmail();
        $lastLogin = $user->getLastLoginTime();
        if ($lastLogin) {
          $account['last_login'] = date('M j, Y g:ia', (int) $lastLogin);
        }

        // Get vendor entity if exists.
        $vendors = $this->entityTypeManager->getStorage('myeventlane_vendor')
          ->loadByProperties(['uid' => $userId]);
        if (!empty($vendors)) {
          $vendor = reset($vendors);
          $account['store_name'] = $vendor->label();
        }
      }
    }
    catch (\Exception $e) {
      // User loading failed.
    }

    return $account;
  }

  /**
   * Get quick actions for dashboard.
   */
  private function getQuickActions(): array {
    return [
      [
        'label' => 'Create Event',
        'url' => '/vendor/events/add',
        'icon' => 'plus',
        'style' => 'primary',
      ],
      [
        'label' => 'Manage Payouts',
        'url' => '/vendor/payouts',
        'icon' => 'dollar',
        'style' => 'secondary',
      ],
      [
        'label' => 'View Attendees',
        'url' => '/vendor/audience',
        'icon' => 'users',
        'style' => 'secondary',
      ],
      [
        'label' => 'Boost Event',
        'url' => '/vendor/boost',
        'icon' => 'zap',
        'style' => 'secondary',
      ],
      [
        'label' => 'Contact Audience',
        'url' => '/vendor/audience',
        'icon' => 'mail',
        'style' => 'secondary',
      ],
      [
        'label' => 'Edit Profile',
        'url' => '/vendor/settings',
        'icon' => 'settings',
        'style' => 'secondary',
      ],
    ];
  }

  /**
   * Build chart data for JavaScript.
   */
  private function buildChartData(int $userId, array $userEvents): array {
    // Generate last 7 days labels.
    $labels = [];
    $revenueData = [];

    for ($i = 6; $i >= 0; $i--) {
      $date = date('M j', strtotime("-$i days"));
      $labels[] = $date;
      $revenueData[] = 0; // Would be populated with real daily revenue.
    }

    return [
      'revenue' => [
        'type' => 'line',
        'labels' => $labels,
        'datasets' => [
          [
            'label' => 'Revenue',
            'data' => $revenueData,
            'borderColor' => '#6366f1',
            'backgroundColor' => 'rgba(99, 102, 241, 0.1)',
            'fill' => TRUE,
          ],
        ],
      ],
      'tickets-by-type' => [
        'type' => 'doughnut',
        'labels' => ['General Admission', 'VIP', 'Early Bird'],
        'datasets' => [
          [
            'data' => [0, 0, 0],
            'backgroundColor' => ['#6366f1', '#10b981', '#f59e0b'],
          ],
        ],
      ],
      'traffic-sources' => [
        'type' => 'bar',
        'labels' => ['Direct', 'Social', 'Search', 'Referral'],
        'datasets' => [
          [
            'label' => 'Visitors',
            'data' => [0, 0, 0, 0],
            'backgroundColor' => '#6366f1',
          ],
        ],
      ],
    ];
  }


  /**
   * Get waitlist analytics for a specific event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array with waitlist analytics data.
   */
  private function getEventWaitlistAnalytics(int $eventId): array {
    try {
      $waitlistManager = \Drupal::service('myeventlane_event_attendees.waitlist');
      return $waitlistManager->getWaitlistAnalytics($eventId);
    }
    catch (\Exception $e) {
      // Return empty analytics if service unavailable.
      return [
        'total_waitlist' => 0,
        'total_promoted' => 0,
        'conversion_rate' => 0.0,
        'average_wait_time' => 0.0,
        'current_waitlist' => 0,
      ];
    }
  }

}
