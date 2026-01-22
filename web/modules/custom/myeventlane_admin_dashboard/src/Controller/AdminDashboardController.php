<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Provides the admin overview dashboard page.
 */
final class AdminDashboardController extends ControllerBase {

  /**
   * Safely gets the order from an order item.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The order entity, or NULL if not available.
   */
  protected function getOrderFromItem(OrderItemInterface $order_item) {
    if (!$order_item->hasField('order_id') || $order_item->get('order_id')->isEmpty()) {
      return NULL;
    }

    // Get the target_id and load the order manually to avoid warnings.
    $order_id = $order_item->get('order_id')->target_id;
    if (!$order_id) {
      return NULL;
    }

    try {
      return $this->entityTypeManager()
        ->getStorage('commerce_order')
        ->load($order_id);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Returns the admin overview page.
   *
   * @return array
   *   A render array for the admin dashboard.
   */
  public function overview(): array {
    $platform_metrics = $this->getPlatformMetrics();
    return [
      '#theme' => 'admin_dashboard',
      '#event_count' => $this->getEventCount(),
      '#vendor_count' => $this->getVendorCount(),
      '#user_count' => $this->getUserCount(),
      '#recent_events' => $this->getRecentEvents(),
      '#quick_links' => $this->getQuickLinks(),
      '#platform_metrics' => $platform_metrics,
      '#revenue_kpis' => $this->getRevenueKpis(),
      '#recent_transactions' => $this->getRecentTransactions(),
      '#top_events' => $this->getTopEvents(),
      '#sidebar_metrics' => $this->getSidebarMetrics($platform_metrics),
      '#detailed_analytics' => $this->getDetailedAnalytics(),
      '#vendor_activity' => $this->getVendorActivity(),
      '#event_breakdown' => $this->getEventBreakdown(),
      '#vendor_breakdown' => $this->getVendorBreakdown(),
      '#customer_activity' => $this->getCustomerActivity(),
      '#escalation_summary' => $this->getEscalationSummary(),
      '#attached' => [
        'library' => [
          'myeventlane_admin_dashboard/admin_dashboard',
        ],
      ],
      '#cache' => [
        'tags' => ['node_list', 'user_list', 'myeventlane_vendor_list', 'commerce_order_list', 'escalation_list'],
        'contexts' => ['user.permissions'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Gets the total count of published events.
   *
   * @return int
   *   The event count.
   */
  protected function getEventCount(): int {
    try {
      $count = $this->entityTypeManager()
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('status', 1)
        ->count()
        ->execute();
      return (int) $count;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Gets the total count of vendors.
   *
   * @return int
   *   The vendor count.
   */
  protected function getVendorCount(): int {
    try {
      $entity_type_manager = $this->entityTypeManager();
      if (!$entity_type_manager->hasDefinition('myeventlane_vendor')) {
        return 0;
      }
      $count = $entity_type_manager
        ->getStorage('myeventlane_vendor')
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
      return (int) $count;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Gets the total count of active users.
   *
   * @return int
   *   The user count.
   */
  protected function getUserCount(): int {
    try {
      $count = $this->entityTypeManager()
        ->getStorage('user')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition('uid', 0, '>')
        ->count()
        ->execute();
      return (int) $count;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Gets recent events for the dashboard.
   *
   * @return array
   *   An array of recent event data.
   */
  protected function getRecentEvents(): array {
    try {
      $entity_type_manager = $this->entityTypeManager();
      $nids = $entity_type_manager
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('status', 1)
        ->sort('created', 'DESC')
        ->range(0, 5)
        ->execute();

      if (empty($nids)) {
        return [];
      }

      $events = [];
      $nodes = $entity_type_manager->getStorage('node')->loadMultiple($nids);
      foreach ($nodes as $node) {
        $events[] = [
          'title' => $node->label(),
          'url' => $node->toUrl()->toString(),
          'created' => $node->getCreatedTime(),
        ];
      }
      return $events;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets quick links for the admin dashboard.
   *
   * @return array
   *   An array of quick link data.
   */
  protected function getQuickLinks(): array {
    $links = [];

    // Content management.
    $links[] = [
      'title' => $this->t('Manage Events'),
      'url' => Url::fromRoute('system.admin_content')->toString(),
      'description' => $this->t('View and edit all content.'),
      'icon' => 'event',
    ];

    // Vendors.
    if ($this->entityTypeManager()->hasDefinition('myeventlane_vendor')) {
      $links[] = [
        'title' => $this->t('Manage Vendors'),
        'url' => Url::fromRoute('entity.myeventlane_vendor.collection')->toString(),
        'description' => $this->t('View and manage vendor accounts.'),
        'icon' => 'vendor',
      ];
    }

    // Users.
    $links[] = [
      'title' => $this->t('Manage Users'),
      'url' => Url::fromRoute('entity.user.collection')->toString(),
      'description' => $this->t('View and manage user accounts.'),
      'icon' => 'user',
    ];

    // Donation reports.
    try {
      $donationUrl = Url::fromRoute('myeventlane_donations.admin_report');
      $links[] = [
        'title' => $this->t('Donation Reports'),
        'url' => $donationUrl->toString(),
        'description' => $this->t('View platform and RSVP donation analytics.'),
        'icon' => 'donations',
      ];
    }
    catch (\Exception) {
      // Donations module may not be available.
    }

    // Reports.
    $links[] = [
      'title' => $this->t('Reports'),
      'url' => Url::fromRoute('system.admin_reports')->toString(),
      'description' => $this->t('View site reports and logs.'),
      'icon' => 'report',
    ];

    // Escalations.
    if ($this->entityTypeManager()->hasDefinition('escalation')) {
      $links[] = [
        'title' => $this->t('Manage Escalations'),
        'url' => Url::fromRoute('entity.escalation.collection')->toString(),
        'description' => $this->t('Handle vendor-customer escalations and support requests.'),
        'icon' => 'escalation',
      ];
    }

    return $links;
  }

  /**
   * Gets platform-wide metrics.
   *
   * @return array
   *   Platform metrics data.
   */
  protected function getPlatformMetrics(): array {
    try {
      $metrics = [
        'total_revenue' => 0.0,
        'platform_fees' => 0.0,
        'net_revenue' => 0.0,
        'total_transactions' => 0,
        'total_orders' => 0,
        'last_30_days_revenue' => 0.0,
      ];

      // Get all completed orders.
      $order_storage = $this->entityTypeManager()->getStorage('commerce_order');
      $order_ids = $order_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('state', 'completed')
        ->execute();

      if (!empty($order_ids)) {
        $orders = $order_storage->loadMultiple($order_ids);
        $thirty_days_ago = strtotime('-30 days');

        foreach ($orders as $order) {
          if (!$order->getTotalPrice()) {
            continue;
          }

          $amount = (float) $order->getTotalPrice()->getNumber();
          $metrics['total_revenue'] += $amount;
          $metrics['total_orders']++;

          // Calculate platform fee (typically 5%).
          $platform_fee_rate = 0.05;
          $platform_fee = $amount * $platform_fee_rate;
          $metrics['platform_fees'] += $platform_fee;
          $metrics['net_revenue'] += ($amount - $platform_fee);

          // Check if order is within last 30 days.
          $order_time = $order->getCompletedTime() ?? $order->getChangedTime();
          if ($order_time >= $thirty_days_ago) {
            $metrics['last_30_days_revenue'] += $amount;
          }

          // Count transactions (order items).
          $metrics['total_transactions'] += count($order->getItems());
        }
      }

      return $metrics;
    }
    catch (\Exception $e) {
      return [
        'total_revenue' => 0.0,
        'platform_fees' => 0.0,
        'net_revenue' => 0.0,
        'total_transactions' => 0,
        'total_orders' => 0,
        'last_30_days_revenue' => 0.0,
      ];
    }
  }

  /**
   * Gets revenue KPIs for the dashboard.
   *
   * @return array
   *   Array of KPI card data.
   */
  protected function getRevenueKpis(): array {
    $metrics = $this->getPlatformMetrics();
    $sidebarMetrics = $this->getSidebarMetrics($metrics);

    $kpis = [
      [
        'label' => 'Total Revenue',
        'value' => '$' . number_format($metrics['total_revenue'], 2),
        'icon' => 'revenue',
        'color' => 'coral',
        'delta' => $metrics['last_30_days_revenue'] > 0 ? [
          'value' => '$' . number_format($metrics['last_30_days_revenue'], 2),
          'label' => 'last 30 days',
          'positive' => TRUE,
        ] : NULL,
      ],
      [
        'label' => 'Platform Fees',
        'value' => '$' . number_format($metrics['platform_fees'], 2),
        'icon' => 'fees',
        'color' => 'blue',
        'delta' => NULL,
      ],
      [
        'label' => 'Net Revenue',
        'value' => '$' . number_format($metrics['net_revenue'], 2),
        'icon' => 'net',
        'color' => 'green',
        'delta' => NULL,
      ],
      [
        'label' => 'Total Orders',
        'value' => (string) $metrics['total_orders'],
        'icon' => 'orders',
        'color' => 'purple',
        'delta' => [
          'value' => (string) $metrics['total_transactions'],
          'label' => 'transactions',
          'positive' => TRUE,
        ],
      ],
    ];

    // Add donation KPIs if available.
    if (isset($sidebarMetrics['total_donations']) && $sidebarMetrics['total_donations'] > 0) {
      $kpis[] = [
        'label' => 'Total Donations',
        'value' => '$' . number_format($sidebarMetrics['total_donations'], 2),
        'icon' => 'donations',
        'color' => 'orange',
        'delta' => [
          'value' => (string) ($sidebarMetrics['platform_donation_count'] + $sidebarMetrics['rsvp_donation_count']),
          'label' => 'donations',
          'positive' => TRUE,
        ],
      ];
    }

    return $kpis;
  }

  /**
   * Gets recent transactions across the platform.
   *
   * @return array
   *   Array of recent transaction data.
   */
  protected function getRecentTransactions(): array {
    try {
      $order_storage = $this->entityTypeManager()->getStorage('commerce_order');
      $order_ids = $order_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('state', 'completed')
        ->sort('completed', 'DESC')
        ->range(0, 10)
        ->execute();

      if (empty($order_ids)) {
        return [];
      }

      $transactions = [];
      $orders = $order_storage->loadMultiple($order_ids);
      $node_storage = $this->entityTypeManager()->getStorage('node');

      foreach ($orders as $order) {
        // Get first order item to find associated event.
        $items = $order->getItems();
        $event_title = 'Multiple items';
        $event_id = NULL;

        if (!empty($items)) {
          $first_item = reset($items);

          // Try field_target_event first (direct link).
          if ($first_item->hasField('field_target_event') && !$first_item->get('field_target_event')->isEmpty()) {
            $event_id = $first_item->get('field_target_event')->target_id;
          }
          // Otherwise try via purchased variation.
          elseif ($first_item->hasField('purchased_entity') && !$first_item->get('purchased_entity')->isEmpty()) {
            $variation = $first_item->get('purchased_entity')->entity;
            if ($variation && $variation->hasField('field_event') && !$variation->get('field_event')->isEmpty()) {
              $event_id = $variation->get('field_event')->target_id;
            }
          }

          if ($event_id) {
            $event = $node_storage->load($event_id);
            if ($event) {
              $event_title = $event->label();
            }
          }
        }

        $completed_time = $order->getCompletedTime() ?? $order->getChangedTime();
        $amount = $order->getTotalPrice() ? (float) $order->getTotalPrice()->getNumber() : 0.0;

        $transactions[] = [
          'order_id' => $order->id(),
          'order_number' => $order->getOrderNumber(),
          'event_title' => $event_title,
          'event_url' => $event_id ? Url::fromRoute('entity.node.canonical', ['node' => $event_id])->toString() : NULL,
          'amount' => $amount,
          'date' => $completed_time,
          'status' => 'Completed',
        ];
      }

      return $transactions;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets top performing events.
   *
   * @return array
   *   Array of top event data.
   */
  protected function getTopEvents(): array {
    try {
      $node_storage = $this->entityTypeManager()->getStorage('node');
      $event_ids = $node_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('status', 1)
        ->execute();

      if (empty($event_ids)) {
        return [];
      }

      // Calculate revenue per event.
      $event_revenues = [];
      $order_item_storage = $this->entityTypeManager()->getStorage('commerce_order_item');
      $order_items = $order_item_storage->loadByProperties([
        'field_target_event' => array_values($event_ids),
      ]);

      foreach ($order_items as $item) {
        try {
          $order = $this->getOrderFromItem($item);
          if ($order && $order->getState()->getId() === 'completed') {
            $event_id = NULL;
            if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
              $event_id = $item->get('field_target_event')->target_id;
            }

            if ($event_id) {
              $total_price = $item->getTotalPrice();
              if ($total_price) {
                $amount = (float) $total_price->getNumber();
                $event_revenues[$event_id] = ($event_revenues[$event_id] ?? 0) + $amount;
              }
            }
          }
        }
        catch (\Exception $e) {
          continue;
        }
      }

      // Sort by revenue and get top 5.
      arsort($event_revenues);
      $top_event_ids = array_slice(array_keys($event_revenues), 0, 5);

      $top_events = [];
      $events = $node_storage->loadMultiple($top_event_ids);
      foreach ($top_event_ids as $event_id) {
        if (!isset($events[$event_id])) {
          continue;
        }

        $event = $events[$event_id];
        $top_events[] = [
          'title' => $event->label(),
          'url' => $event->toUrl()->toString(),
          'revenue' => $event_revenues[$event_id],
          'created' => $event->getCreatedTime(),
        ];
      }

      return $top_events;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets sidebar metrics for quick reference.
   *
   * @param array $platform_metrics
   *   Platform metrics array.
   *
   * @return array
   *   Sidebar metrics data.
   */
  protected function getSidebarMetrics(array $platform_metrics): array {
    try {
      // Get RSVP counts.
      $rsvp_count = 0;
      $rsvp_confirmed = 0;
      try {
        $rsvp_storage = $this->entityTypeManager()->getStorage('rsvp_submission');
        $rsvp_count = (int) $rsvp_storage->getQuery()
          ->accessCheck(FALSE)
          ->count()
          ->execute();
        $rsvp_confirmed = (int) $rsvp_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('status', 'confirmed')
          ->count()
          ->execute();
      }
      catch (\Exception $e) {
        // RSVP module may not be available.
      }

      // Get attendee counts.
      $attendee_count = 0;
      try {
        $attendee_storage = $this->entityTypeManager()->getStorage('event_attendee');
        $attendee_count = (int) $attendee_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('status', 'confirmed')
          ->count()
          ->execute();
      }
      catch (\Exception $e) {
        // Attendee module may not be available.
      }

      // Get tickets sold.
      $tickets_sold = 0;
      try {
        $order_item_storage = $this->entityTypeManager()->getStorage('commerce_order_item');
        $order_items = $order_item_storage->getQuery()
          ->accessCheck(FALSE)
          ->execute();
        $items = $order_item_storage->loadMultiple($order_items);
        foreach ($items as $item) {
          try {
            $order = $this->getOrderFromItem($item);
            if ($order && $order->getState()->getId() === 'completed') {
              $tickets_sold += (int) $item->getQuantity();
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

      // Get upcoming events count.
      $upcoming_count = 0;
      $now = date('Y-m-d\TH:i:s');
      try {
        $upcoming_count = (int) $this->entityTypeManager()
          ->getStorage('node')
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'event')
          ->condition('status', 1)
          ->condition('field_event_start', $now, '>=')
          ->count()
          ->execute();
      }
      catch (\Exception $e) {
        // Event field may not exist.
      }

      // Get donation metrics.
      $platform_donation_total = 0.0;
      $rsvp_donation_total = 0.0;
      $platform_donation_count = 0;
      $rsvp_donation_count = 0;
      try {
        // Check if donations module is enabled and service exists.
        if ($this->moduleHandler()->moduleExists('myeventlane_donations') && \Drupal::hasService('myeventlane_donations.service')) {
          try {
            $donationService = \Drupal::service('myeventlane_donations.service');

            // Get all platform donations.
            $order_storage = $this->entityTypeManager()->getStorage('commerce_order');
            $platform_order_ids = $order_storage->getQuery()
              ->accessCheck(FALSE)
              ->condition('type', 'platform_donation')
              ->condition('state', 'completed')
              ->execute();

            if (!empty($platform_order_ids)) {
              $platform_orders = $order_storage->loadMultiple($platform_order_ids);
              foreach ($platform_orders as $order) {
                $totalPrice = $order->getTotalPrice();
                if ($totalPrice) {
                  $platform_donation_total += (float) $totalPrice->getNumber();
                  $platform_donation_count++;
                }
              }
            }

            // Get all RSVP donations.
            $rsvp_order_ids = $order_storage->getQuery()
              ->accessCheck(FALSE)
              ->condition('type', 'rsvp_donation')
              ->condition('state', 'completed')
              ->execute();

            if (!empty($rsvp_order_ids)) {
              $rsvp_orders = $order_storage->loadMultiple($rsvp_order_ids);
              foreach ($rsvp_orders as $order) {
                $totalPrice = $order->getTotalPrice();
                if ($totalPrice) {
                  $rsvp_donation_total += (float) $totalPrice->getNumber();
                  $rsvp_donation_count++;
                }
              }
            }
          }
          catch (\Exception $serviceException) {
            // Service exists but can't be instantiated - log and continue.
            $this->getLogger('myeventlane_admin_dashboard')->warning('Donation service unavailable: @message', [
              '@message' => $serviceException->getMessage(),
            ]);
          }
        }
      }
      catch (\Exception $e) {
        // Donations module may not be available.
      }

      return [
        'total_rsvps' => $rsvp_count,
        'confirmed_rsvps' => $rsvp_confirmed,
        'total_attendees' => $attendee_count,
        'tickets_sold' => $tickets_sold,
        'upcoming_events' => $upcoming_count,
        'total_events' => $this->getEventCount(),
        'total_vendors' => $this->getVendorCount(),
        'total_users' => $this->getUserCount(),
        'total_orders' => $platform_metrics['total_orders'],
        'total_revenue' => $platform_metrics['total_revenue'],
        'platform_fees' => $platform_metrics['platform_fees'],
        'platform_donation_total' => $platform_donation_total,
        'rsvp_donation_total' => $rsvp_donation_total,
        'platform_donation_count' => $platform_donation_count,
        'rsvp_donation_count' => $rsvp_donation_count,
        'total_donations' => $platform_donation_total + $rsvp_donation_total,
      ];
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets detailed analytics across the platform.
   *
   * @return array
   *   Detailed analytics data.
   */
  protected function getDetailedAnalytics(): array {
    try {
      $analytics = [
        'events_by_status' => [],
        'orders_by_status' => [],
        'revenue_by_month' => [],
        'top_vendors' => [],
      ];

      // Events by status.
      try {
        $event_storage = $this->entityTypeManager()->getStorage('node');
        $analytics['events_by_status'] = [
          'published' => (int) $event_storage->getQuery()
            ->accessCheck(FALSE)
            ->condition('type', 'event')
            ->condition('status', 1)
            ->count()
            ->execute(),
          'unpublished' => (int) $event_storage->getQuery()
            ->accessCheck(FALSE)
            ->condition('type', 'event')
            ->condition('status', 0)
            ->count()
            ->execute(),
        ];
      }
      catch (\Exception $e) {
        // Events may not exist.
      }

      // Orders by status.
      try {
        $order_storage = $this->entityTypeManager()->getStorage('commerce_order');
        $order_ids = $order_storage->getQuery()
          ->accessCheck(FALSE)
          ->execute();
        $orders = $order_storage->loadMultiple($order_ids);
        foreach ($orders as $order) {
          $state = $order->getState()->getId();
          $analytics['orders_by_status'][$state] = ($analytics['orders_by_status'][$state] ?? 0) + 1;
        }
      }
      catch (\Exception $e) {
        // Orders may not exist.
      }

      // Revenue by month (last 6 months).
      try {
        $order_storage = $this->entityTypeManager()->getStorage('commerce_order');
        $order_ids = $order_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('state', 'completed')
          ->execute();
        $orders = $order_storage->loadMultiple($order_ids);
        $monthly_revenue = [];
        foreach ($orders as $order) {
          if (!$order->getTotalPrice()) {
            continue;
          }
          $completed_time = $order->getCompletedTime() ?? $order->getChangedTime();
          $month_key = date('Y-m', $completed_time);
          $amount = (float) $order->getTotalPrice()->getNumber();
          $monthly_revenue[$month_key] = ($monthly_revenue[$month_key] ?? 0) + $amount;
        }
        // Get last 6 months.
        $analytics['revenue_by_month'] = [];
        for ($i = 5; $i >= 0; $i--) {
          $month_key = date('Y-m', strtotime("-$i months"));
          $analytics['revenue_by_month'][] = [
            'month' => date('M Y', strtotime("-$i months")),
            'revenue' => $monthly_revenue[$month_key] ?? 0.0,
          ];
        }
      }
      catch (\Exception $e) {
        // Revenue calculation failed.
      }

      // Top vendors by revenue.
      try {
        if ($this->entityTypeManager()->hasDefinition('myeventlane_vendor')) {
          $vendor_storage = $this->entityTypeManager()->getStorage('myeventlane_vendor');
          $vendor_ids = $vendor_storage->getQuery()
            ->accessCheck(FALSE)
            ->execute();
          $vendors = $vendor_storage->loadMultiple($vendor_ids);
          $vendor_revenues = [];

          foreach ($vendors as $vendor) {
            $vendor_id = $vendor->id();
            // Get events for this vendor.
            $event_ids = $this->entityTypeManager()
              ->getStorage('node')
              ->getQuery()
              ->accessCheck(FALSE)
              ->condition('type', 'event')
              ->condition('field_event_vendor', $vendor_id)
              ->execute();

            if (empty($event_ids)) {
              continue;
            }

            // Calculate revenue for vendor's events.
            $order_item_storage = $this->entityTypeManager()->getStorage('commerce_order_item');
            $order_items = $order_item_storage->loadByProperties([
              'field_target_event' => array_values($event_ids),
            ]);

            $revenue = 0.0;
            foreach ($order_items as $item) {
              try {
                $order = $this->getOrderFromItem($item);
                if ($order && $order->getState()->getId() === 'completed') {
                  $total_price = $item->getTotalPrice();
                  if ($total_price) {
                    $revenue += (float) $total_price->getNumber();
                  }
                }
              }
              catch (\Exception $e) {
                continue;
              }
            }

            if ($revenue > 0) {
              $vendor_revenues[$vendor_id] = [
                'name' => $vendor->label(),
                'revenue' => $revenue,
              ];
            }
          }

          // Sort by revenue and get top 5.
          usort($vendor_revenues, function ($a, $b) {
            return $b['revenue'] <=> $a['revenue'];
          });
          $analytics['top_vendors'] = array_slice($vendor_revenues, 0, 5);
        }
      }
      catch (\Exception $e) {
        // Vendor calculation failed.
      }

      return $analytics;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets vendor activity summary.
   *
   * @return array
   *   Vendor activity data.
   */
  protected function getVendorActivity(): array {
    try {
      if (!$this->entityTypeManager()->hasDefinition('myeventlane_vendor')) {
        return [];
      }

      $vendor_storage = $this->entityTypeManager()->getStorage('myeventlane_vendor');
      $vendor_ids = $vendor_storage->getQuery()
        ->accessCheck(FALSE)
        ->execute();
      $vendors = $vendor_storage->loadMultiple($vendor_ids);

      $activity = [];
      foreach ($vendors as $vendor) {
        $vendor_id = $vendor->id();

        // Count events per vendor.
        $event_count = (int) $this->entityTypeManager()
          ->getStorage('node')
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'event')
          ->condition('field_event_vendor', $vendor_id)
          ->condition('status', 1)
          ->count()
          ->execute();

        if ($event_count > 0) {
          $activity[] = [
            'vendor_name' => $vendor->label(),
            'vendor_id' => $vendor_id,
            'event_count' => $event_count,
          ];
        }
      }

      // Sort by event count.
      usort($activity, function ($a, $b) {
        return $b['event_count'] <=> $a['event_count'];
      });

      return array_slice($activity, 0, 10);
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets detailed event breakdown with analytics.
   *
   * @return array
   *   Event breakdown data.
   */
  protected function getEventBreakdown(): array {
    try {
      $node_storage = $this->entityTypeManager()->getStorage('node');
      $event_ids = $node_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('status', 1)
        ->sort('created', 'DESC')
        ->range(0, 20)
        ->execute();

      if (empty($event_ids)) {
        return [];
      }

      $events = $node_storage->loadMultiple($event_ids);
      $breakdown = [];

      foreach ($events as $event) {
        $event_id = (int) $event->id();

        // Get vendor info.
        $vendor_name = 'N/A';
        $vendor_id = NULL;
        if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
          $vendor = $event->get('field_event_vendor')->entity;
          if ($vendor) {
            $vendor_name = $vendor->label();
            $vendor_id = $vendor->id();
          }
        }

        // Get event date.
        $event_date = '';
        if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
          $date_item = $event->get('field_event_start');
          if ($date_item->date) {
            $event_date = $date_item->date->format('M j, Y');
          }
        }

        // Calculate revenue for this event.
        $revenue = 0.0;
        $tickets_sold = 0;
        $order_item_storage = $this->entityTypeManager()->getStorage('commerce_order_item');
        $order_items = $order_item_storage->loadByProperties([
          'field_target_event' => $event_id,
        ]);

        foreach ($order_items as $item) {
          try {
            $order = $this->getOrderFromItem($item);
            if ($order && $order->getState()->getId() === 'completed') {
              $total_price = $item->getTotalPrice();
              if ($total_price) {
                $revenue += (float) $total_price->getNumber();
              }
              $tickets_sold += (int) $item->getQuantity();
            }
          }
          catch (\Exception $e) {
            continue;
          }
        }

        // Get RSVP count.
        $rsvp_count = 0;
        try {
          $rsvp_storage = $this->entityTypeManager()->getStorage('rsvp_submission');
          $rsvp_count = (int) $rsvp_storage->getQuery()
            ->accessCheck(FALSE)
            ->condition('event_id', $event_id)
            ->condition('status', 'confirmed')
            ->count()
            ->execute();
        }
        catch (\Exception $e) {
          // RSVP module may not be available.
        }

        // Get attendee count.
        $attendee_count = 0;
        try {
          $attendee_storage = $this->entityTypeManager()->getStorage('event_attendee');
          $attendee_count = (int) $attendee_storage->getQuery()
            ->accessCheck(FALSE)
            ->condition('event', $event_id)
            ->condition('status', 'confirmed')
            ->count()
            ->execute();
        }
        catch (\Exception $e) {
          // Attendee module may not be available.
        }

        // Get donation metrics for this event.
        $donation_total = 0.0;
        $donation_count = 0;
        try {
          if ($this->moduleHandler()->moduleExists('myeventlane_donations') && \Drupal::hasService('myeventlane_donations.service')) {
            try {
              $donationService = \Drupal::service('myeventlane_donations.service');
              $donationStats = $donationService->getEventDonationStats($event_id);
              $donation_total = $donationStats['total'] ?? 0;
              $donation_count = $donationStats['count'] ?? 0;
            }
            catch (\Exception $serviceException) {
              // Service exists but can't be instantiated - skip donation stats.
            }
          }
        }
        catch (\Exception $e) {
          // Donations module may not be available.
        }

        $breakdown[] = [
          'event_id' => $event_id,
          'event_title' => $event->label(),
          'event_url' => $event->toUrl()->toString(),
          'vendor_name' => $vendor_name,
          'vendor_id' => $vendor_id,
          'event_date' => $event_date,
          'revenue' => $revenue,
          'tickets_sold' => $tickets_sold,
          'rsvps' => $rsvp_count,
          'attendees' => $attendee_count,
          'donation_total' => $donation_total,
          'donation_count' => $donation_count,
          'total_participants' => $tickets_sold + $attendee_count + $rsvp_count,
          'created' => $event->getCreatedTime(),
        ];
      }

      return $breakdown;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets detailed vendor breakdown with analytics.
   *
   * @return array
   *   Vendor breakdown data.
   */
  protected function getVendorBreakdown(): array {
    try {
      if (!$this->entityTypeManager()->hasDefinition('myeventlane_vendor')) {
        return [];
      }

      $vendor_storage = $this->entityTypeManager()->getStorage('myeventlane_vendor');
      $vendor_ids = $vendor_storage->getQuery()
        ->accessCheck(FALSE)
        ->execute();
      $vendors = $vendor_storage->loadMultiple($vendor_ids);

      $breakdown = [];

      foreach ($vendors as $vendor) {
        $vendor_id = $vendor->id();

        // Get events for this vendor.
        $event_ids = $this->entityTypeManager()
          ->getStorage('node')
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'event')
          ->condition('field_event_vendor', $vendor_id)
          ->execute();

        $event_count = count($event_ids);
        $published_count = (int) $this->entityTypeManager()
          ->getStorage('node')
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'event')
          ->condition('field_event_vendor', $vendor_id)
          ->condition('status', 1)
          ->count()
          ->execute();

        // Calculate revenue for vendor's events.
        $revenue = 0.0;
        $tickets_sold = 0;
        $total_orders = 0;
        $donation_total = 0.0;
        $donation_count = 0;

        if (!empty($event_ids)) {
          $order_item_storage = $this->entityTypeManager()->getStorage('commerce_order_item');
          $order_items = $order_item_storage->loadByProperties([
            'field_target_event' => array_values($event_ids),
          ]);

          $processed_orders = [];
          foreach ($order_items as $item) {
            try {
              $order = $this->getOrderFromItem($item);
              if ($order && $order->getState()->getId() === 'completed') {
                $order_id = $order->id();
                if (!isset($processed_orders[$order_id])) {
                  $processed_orders[$order_id] = TRUE;
                  $total_orders++;
                }

                $total_price = $item->getTotalPrice();
                if ($total_price) {
                  $revenue += (float) $total_price->getNumber();
                }
                $tickets_sold += (int) $item->getQuantity();
              }
            }
            catch (\Exception $e) {
              continue;
            }
          }

          // Get donation totals for vendor's events.
          try {
            if ($this->moduleHandler()->moduleExists('myeventlane_donations') && \Drupal::hasService('myeventlane_donations.service')) {
              try {
                $donationService = \Drupal::service('myeventlane_donations.service');
                foreach ($event_ids as $eventId) {
                  $donationStats = $donationService->getEventDonationStats((int) $eventId);
                  $donation_total += $donationStats['total'] ?? 0;
                  $donation_count += $donationStats['count'] ?? 0;
                }
              }
              catch (\Exception $serviceException) {
                // Service exists but can't be instantiated - skip donation stats.
              }
            }
          }
          catch (\Exception $e) {
            // Donations module may not be available.
          }
        }

        // Get vendor users count.
        $user_count = 0;
        if ($vendor->hasField('field_vendor_users') && !$vendor->get('field_vendor_users')->isEmpty()) {
          $user_count = $vendor->get('field_vendor_users')->count();
        }

        $breakdown[] = [
          'vendor_id' => $vendor_id,
          'vendor_name' => $vendor->label(),
          'vendor_url' => $vendor->toUrl()->toString(),
          'event_count' => $event_count,
          'published_events' => $published_count,
          'total_revenue' => $revenue,
          'platform_fees' => $revenue * 0.05,
          'net_revenue' => $revenue * 0.95,
          'tickets_sold' => $tickets_sold,
          'total_orders' => $total_orders,
          'donation_total' => $donation_total,
          'donation_count' => $donation_count,
          'user_count' => $user_count,
          'created' => $vendor->getCreatedTime(),
        ];
      }

      // Sort by revenue.
      usort($breakdown, function ($a, $b) {
        return $b['total_revenue'] <=> $a['total_revenue'];
      });

      return $breakdown;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets customer activity summary.
   *
   * @return array
   *   Customer activity data.
   */
  protected function getCustomerActivity(): array {
    try {
      $activity = [];

      // Get total unique customers (users with orders).
      $order_storage = $this->entityTypeManager()->getStorage('commerce_order');
      $order_ids = $order_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('state', 'completed')
        ->execute();

      if (!empty($order_ids)) {
        $orders = $order_storage->loadMultiple($order_ids);
        $customer_ids = [];
        $customer_spend = [];
        $customer_orders = [];

        foreach ($orders as $order) {
          $customer = $order->getCustomer();
          if ($customer && $customer->id() > 0) {
            $uid = (int) $customer->id();
            $customer_ids[$uid] = TRUE;

            $amount = $order->getTotalPrice() ? (float) $order->getTotalPrice()->getNumber() : 0.0;
            $customer_spend[$uid] = ($customer_spend[$uid] ?? 0) + $amount;
            $customer_orders[$uid] = ($customer_orders[$uid] ?? 0) + 1;
          }
        }

        // Get top customers.
        arsort($customer_spend);
        $top_customer_uids = array_slice(array_keys($customer_spend), 0, 10);
        $users = $this->entityTypeManager()->getStorage('user')->loadMultiple($top_customer_uids);

        foreach ($top_customer_uids as $uid) {
          if (!isset($users[$uid])) {
            continue;
          }
          $user = $users[$uid];
          $activity[] = [
            'customer_id' => $uid,
            'customer_name' => $user->getDisplayName(),
            'customer_email' => $user->getEmail(),
            'total_spent' => $customer_spend[$uid],
            'order_count' => $customer_orders[$uid],
            'average_order' => $customer_orders[$uid] > 0 ? $customer_spend[$uid] / $customer_orders[$uid] : 0,
          ];
        }

        $activity['summary'] = [
          'total_customers' => count($customer_ids),
          'total_spent' => array_sum($customer_spend),
          'average_customer_value' => count($customer_ids) > 0 ? array_sum($customer_spend) / count($customer_ids) : 0,
        ];
      }

      return $activity;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets escalation summary for the dashboard.
   *
   * @return array
   *   Escalation summary data.
   */
  protected function getEscalationSummary(): array {
    try {
      if (!$this->entityTypeManager()->hasDefinition('escalation')) {
        return [];
      }

      $escalation_storage = $this->entityTypeManager()->getStorage('escalation');

      $total = (int) $escalation_storage->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();

      $by_status = [];
      $statuses = ['new', 'in_progress', 'waiting_vendor', 'waiting_customer', 'resolved', 'closed'];
      foreach ($statuses as $status) {
        $by_status[$status] = (int) $escalation_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('status', $status)
          ->count()
          ->execute();
      }

      $by_priority = [];
      $priorities = ['low', 'normal', 'high', 'urgent'];
      foreach ($priorities as $priority) {
        $by_priority[$priority] = (int) $escalation_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('priority', $priority)
          ->count()
          ->execute();
      }

      // Get recent escalations.
      $recent_ids = $escalation_storage->getQuery()
        ->accessCheck(FALSE)
        ->sort('created', 'DESC')
        ->range(0, 5)
        ->execute();

      $recent = [];
      if (!empty($recent_ids)) {
        $escalations = $escalation_storage->loadMultiple($recent_ids);
        foreach ($escalations as $escalation) {
          $customer = $escalation->getCustomer();
          $recent[] = [
            'id' => $escalation->id(),
            'subject' => $escalation->getSubject(),
            'url' => $escalation->toUrl()->toString(),
            'status' => $escalation->getStatus(),
            'priority' => $escalation->getPriority(),
            'customer' => $customer ? $customer->getDisplayName() : 'Unknown',
            'created' => $escalation->getCreatedTime(),
          ];
        }
      }

      return [
        'total' => $total,
        'by_status' => $by_status,
        'by_priority' => $by_priority,
        'recent' => $recent,
      ];
    }
    catch (\Exception $e) {
      return [];
    }
  }

}
