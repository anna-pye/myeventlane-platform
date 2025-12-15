<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_vendor\Service\MetricsAggregator;
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
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    private readonly MetricsAggregator $metricsAggregator,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($domain_detector, $current_user);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('myeventlane_vendor.service.metrics_aggregator'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Displays the vendor dashboard.
   */
  public function dashboard(): array {
    $userId = (int) $this->currentUser->id();

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
   * Get all events owned by user.
   */
  private function getUserEvents(int $userId): array {
    return $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('uid', $userId)
      ->execute();
  }

  /**
   * Build comprehensive KPI card data.
   */
  private function buildKpiCards(int $userId, array $userEvents): array {
    $eventCount = count($userEvents);

    // Calculate revenue metrics.
    $totalRevenue = 0;
    $last30DaysRevenue = 0;
    $ticketsSold = 0;
    $thirtyDaysAgo = strtotime('-30 days');

    if (!empty($userEvents)) {
      try {
        $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
        $orderItems = $orderItemStorage->loadByProperties([
          'field_target_event' => array_values($userEvents),
        ]);

        foreach ($orderItems as $item) {
          if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
            continue;
          }
          try {
            $order = $item->getOrder();
            if ($order && $order->getState()->getId() === 'completed') {
              $totalPrice = $item->getTotalPrice();
              if ($totalPrice) {
                $amount = (float) $totalPrice->getNumber();
                $totalRevenue += $amount;
                $ticketsSold += (int) $item->getQuantity();

                // Check if order is within last 30 days.
                $orderTime = $order->getCompletedTime() ?? $order->getChangedTime();
                if ($orderTime >= $thirtyDaysAgo) {
                  $last30DaysRevenue += $amount;
                }
              }
            }
          }
          catch (\Exception $e) {
            continue;
          }
        }
      }
      catch (\Exception $e) {
        // Commerce may not be available.
      }
    }

    // Count RSVPs.
    $rsvpCount = 0;
    if (!empty($userEvents)) {
      try {
        $rsvpCount = (int) $this->entityTypeManager
          ->getStorage('rsvp_submission')
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('event_id', $userEvents, 'IN')
          ->condition('status', 'confirmed')
          ->count()
          ->execute();
      }
      catch (\Exception $e) {
        // RSVP module may not be available.
      }
    }

    // Count attendees (if separate from commerce).
    $attendeeCount = 0;
    if (!empty($userEvents)) {
      try {
        $attendeeCount = (int) $this->entityTypeManager
          ->getStorage('event_attendee')
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('event', $userEvents, 'IN')
          ->condition('status', 'confirmed')
          ->count()
          ->execute();
      }
      catch (\Exception $e) {
        // Use tickets sold as fallback.
        $attendeeCount = $ticketsSold;
      }
    }

    // Get upcoming events count.
    $upcomingCount = $this->getUpcomingEventsCount($userEvents);

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
        'value' => (string) max($ticketsSold, $attendeeCount),
        'icon' => 'tickets',
        'color' => 'green',
        'delta' => NULL,
      ],
      [
        'label' => 'RSVPs',
        'value' => (string) $rsvpCount,
        'icon' => 'users',
        'color' => 'purple',
        'delta' => NULL,
      ],
    ];
  }

  /**
   * Get upcoming events count.
   */
  private function getUpcomingEventsCount(array $userEvents): int {
    if (empty($userEvents)) {
      return 0;
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $now = date('Y-m-d\TH:i:s');

    try {
      return (int) $nodeStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('nid', $userEvents, 'IN')
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
   */
  private function getEventsTableData(array $userEvents): array {
    if (empty($userEvents)) {
      return [];
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nodes = $nodeStorage->loadMultiple($userEvents);
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

      // Get revenue and ticket counts.
      $revenue = $this->getEventRevenue($eventId);
      $ticketsSold = $this->getEventTicketsSold($eventId);
      $rsvps = $this->getEventRsvpCount($eventId);

      // Get waitlist analytics.
      $waitlistAnalytics = $this->getEventWaitlistAnalytics($eventId);

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
        'view_url' => $node->toUrl()->toString(),
        'edit_url' => $node->toUrl('edit-form')->toString(),
        'manage_url' => '/vendor/events/' . $eventId . '/overview',
        'tickets_url' => '/vendor/events/' . $eventId . '/tickets',
        'analytics_url' => '/vendor/analytics/event/' . $eventId,
        'attendees_url' => '/vendor/events/' . $eventId . '/attendees',
        'waitlist_url' => '/vendor/event/' . $eventId . '/waitlist',
      ];
    }

    // Sort by start date descending.
    usort($events, fn($a, $b) => $b['start_timestamp'] <=> $a['start_timestamp']);

    return $events;
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
   * Get revenue for a specific event.
   */
  private function getEventRevenue(int $eventId): float {
    $revenue = 0;
    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventId,
      ]);

      foreach ($orderItems as $item) {
        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }
        try {
          $order = $item->getOrder();
          if ($order && $order->getState()->getId() === 'completed') {
            $totalPrice = $item->getTotalPrice();
            if ($totalPrice) {
              $revenue += (float) $totalPrice->getNumber();
            }
          }
        }
        catch (\Exception $e) {
          continue;
        }
      }
    }
    catch (\Exception $e) {
      // Commerce may not be available.
    }
    return $revenue;
  }

  /**
   * Get tickets sold for a specific event.
   */
  private function getEventTicketsSold(int $eventId): int {
    $count = 0;
    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventId,
      ]);

      foreach ($orderItems as $item) {
        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }
        try {
          $order = $item->getOrder();
          if ($order && $order->getState()->getId() === 'completed') {
            $count += (int) $item->getQuantity();
          }
        }
        catch (\Exception $e) {
          continue;
        }
      }
    }
    catch (\Exception $e) {
      // Commerce may not be available.
    }
    return $count;
  }

  /**
   * Get RSVP count for a specific event.
   */
  private function getEventRsvpCount(int $eventId): int {
    try {
      return (int) $this->entityTypeManager
        ->getStorage('rsvp_submission')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', $eventId)
        ->condition('status', 'confirmed')
        ->count()
        ->execute();
    }
    catch (\Exception $e) {
      return 0;
    }
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
