<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for vendor detail pages with analytics.
 */
final class VendorDetailController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);
    return $instance;
  }

  /**
   * Displays vendor detail page with analytics.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $myeventlane_vendor
   *   The vendor entity.
   *
   * @return array
   *   A render array.
   */
  public function view(Vendor $myeventlane_vendor): array {
    // Build standard entity view.
    $view_builder = $this->entityTypeManager()->getViewBuilder('myeventlane_vendor');
    $build = $view_builder->view($myeventlane_vendor, 'full');

    // Add analytics data.
    $analytics = $this->getVendorAnalytics($myeventlane_vendor);
    $events = $this->getVendorEvents($myeventlane_vendor);

    // Add analytics section.
    $build['vendor_analytics'] = [
      '#theme' => 'myeventlane_vendor_analytics',
      '#vendor' => $myeventlane_vendor,
      '#analytics' => $analytics,
      '#events' => $events,
      '#weight' => 100,
      '#attached' => [
        'library' => [
          'myeventlane_vendor/vendor_detail_analytics',
        ],
      ],
    ];

    return $build;
  }

  /**
   * Gets comprehensive analytics for a vendor.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   *
   * @return array
   *   Analytics data.
   */
  protected function getVendorAnalytics(Vendor $vendor): array {
    $vendor_id = (int) $vendor->id();

    // Get vendor's events.
    $event_ids = $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('field_event_vendor', $vendor_id)
      ->execute();

    $total_events = count($event_ids);
    $published_events = (int) $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('field_event_vendor', $vendor_id)
      ->condition('status', 1)
      ->count()
      ->execute();

    // Calculate revenue metrics.
    $total_revenue = 0.0;
    $last_30_days_revenue = 0.0;
    $tickets_sold = 0;
    $total_orders = 0;
    $thirty_days_ago = strtotime('-30 days');

    if (!empty($event_ids)) {
      $order_item_storage = $this->entityTypeManager()->getStorage('commerce_order_item');
      $order_items = $order_item_storage->loadByProperties([
        'field_target_event' => array_values($event_ids),
      ]);

      $processed_orders = [];
      foreach ($order_items as $item) {
        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }
        try {
          $order = $item->getOrder();
          if ($order && $order->getState()->getId() === 'completed') {
            $order_id = $order->id();
            if (!isset($processed_orders[$order_id])) {
              $processed_orders[$order_id] = TRUE;
              $total_orders++;

              // Check if order is within last 30 days.
              $order_time = $order->getCompletedTime() ?? $order->getChangedTime();
              if ($order_time >= $thirty_days_ago) {
                $order_total = $order->getTotalPrice();
                if ($order_total) {
                  $last_30_days_revenue += (float) $order_total->getNumber();
                }
              }
            }

            $total_price = $item->getTotalPrice();
            if ($total_price) {
              $total_revenue += (float) $total_price->getNumber();
            }
            $tickets_sold += (int) $item->getQuantity();
          }
        }
        catch (\Exception $e) {
          continue;
        }
      }
    }

    // Get RSVP counts.
    $total_rsvps = 0;
    $confirmed_rsvps = 0;
    try {
      $rsvp_storage = $this->entityTypeManager()->getStorage('rsvp_submission');
      $total_rsvps = (int) $rsvp_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', $event_ids, 'IN')
        ->count()
        ->execute();
      $confirmed_rsvps = (int) $rsvp_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', $event_ids, 'IN')
        ->condition('status', 'confirmed')
        ->count()
        ->execute();
    }
    catch (\Exception $e) {
      // RSVP module may not be available.
    }

    // Get attendee counts.
    $total_attendees = 0;
    try {
      $attendee_storage = $this->entityTypeManager()->getStorage('event_attendee');
      $total_attendees = (int) $attendee_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $event_ids, 'IN')
        ->condition('status', 'confirmed')
        ->count()
        ->execute();
    }
    catch (\Exception $e) {
      // Attendee module may not be available.
    }

    // Calculate platform fees and net revenue.
    $platform_fee_rate = 0.05;
    $platform_fees = $total_revenue * $platform_fee_rate;
    $net_revenue = $total_revenue - $platform_fees;

    // Get vendor users count.
    $user_count = 0;
    if ($vendor->hasField('field_vendor_users') && !$vendor->get('field_vendor_users')->isEmpty()) {
      $user_count = $vendor->get('field_vendor_users')->count();
    }

    return [
      'total_events' => $total_events,
      'published_events' => $published_events,
      'total_revenue' => $total_revenue,
      'last_30_days_revenue' => $last_30_days_revenue,
      'platform_fees' => $platform_fees,
      'net_revenue' => $net_revenue,
      'tickets_sold' => $tickets_sold,
      'total_orders' => $total_orders,
      'total_rsvps' => $total_rsvps,
      'confirmed_rsvps' => $confirmed_rsvps,
      'total_attendees' => $total_attendees,
      'total_participants' => $tickets_sold + $total_attendees + $confirmed_rsvps,
      'user_count' => $user_count,
    ];
  }

  /**
   * Gets detailed event list for vendor with metrics.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   *
   * @return array
   *   Events data with analytics.
   */
  protected function getVendorEvents(Vendor $vendor): array {
    $vendor_id = (int) $vendor->id();

    // Get vendor's events.
    $event_ids = $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('field_event_vendor', $vendor_id)
      ->sort('created', 'DESC')
      ->execute();

    if (empty($event_ids)) {
      return [];
    }

    $events = $this->entityTypeManager()->getStorage('node')->loadMultiple($event_ids);
    $events_data = [];

    foreach ($events as $event) {
      $event_id = (int) $event->id();

      // Get event date.
      $event_date = '';
      $event_date_timestamp = NULL;
      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        $date_item = $event->get('field_event_start');
        if ($date_item->date) {
          $event_date = $date_item->date->format('M j, Y');
          $event_date_timestamp = $date_item->date->getTimestamp();
        }
      }

      // Calculate revenue for this event.
      $revenue = 0.0;
      $tickets_sold = 0;
      $orders_count = 0;

      $order_item_storage = $this->entityTypeManager()->getStorage('commerce_order_item');
      $order_items = $order_item_storage->loadByProperties([
        'field_target_event' => $event_id,
      ]);

      $processed_orders = [];
      foreach ($order_items as $item) {
        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }
        try {
          $order = $item->getOrder();
          if ($order && $order->getState()->getId() === 'completed') {
            $order_id = $order->id();
            if (!isset($processed_orders[$order_id])) {
              $processed_orders[$order_id] = TRUE;
              $orders_count++;
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

      $events_data[] = [
        'event_id' => $event_id,
        'event_title' => $event->label(),
        'event_url' => $event->toUrl()->toString(),
        'event_date' => $event_date,
        'event_date_timestamp' => $event_date_timestamp,
        'status' => $event->isPublished() ? 'published' : 'draft',
        'revenue' => $revenue,
        'tickets_sold' => $tickets_sold,
        'rsvps' => $rsvp_count,
        'attendees' => $attendee_count,
        'total_participants' => $tickets_sold + $attendee_count + $rsvp_count,
        'orders' => $orders_count,
        'created' => $event->getCreatedTime(),
      ];
    }

    return $events_data;
  }

}















