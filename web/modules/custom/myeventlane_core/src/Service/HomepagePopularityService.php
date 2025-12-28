<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\NodeInterface;

/**
 * Service for calculating event popularity rankings.
 *
 * Ranking logic: RSVP count + ticket sales + views (if available)
 * Excludes: boosted/promoted events
 * Time window: last 7 days of activity
 */
class HomepagePopularityService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a HomepagePopularityService.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Gets popular event IDs ranked by activity in the last 7 days.
   *
   * @param int $limit
   *   Maximum number of events to return.
   *
   * @return array
   *   Array of event node IDs, sorted by popularity score (descending).
   */
  public function getPopularEventIds(int $limit = 6): array {
    try {
      $now = time();
      $week_ago = $now - (7 * 24 * 60 * 60);

      // Get RSVP counts from last 7 days.
      $rsvp_query = $this->database->select('myeventlane_rsvp', 'r')
        ->fields('r', ['event_nid'])
        ->condition('r.created', $week_ago, '>=')
        ->condition('r.status', 'active');

      $rsvp_counts = [];
      foreach ($rsvp_query->execute() as $row) {
        $event_nid = (int) $row->event_nid;
        $rsvp_counts[$event_nid] = ($rsvp_counts[$event_nid] ?? 0) + 1;
      }

      // Get ticket sales from last 7 days (completed orders).
      // Query order items directly and check via purchased entity.
      $ticket_counts = [];
      
      try {
        $order_storage = $this->entityTypeManager->getStorage('commerce_order');
        $order_ids = $order_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('state', 'completed')
          ->condition('completed', $week_ago, '>=')
          ->execute();
        
        if (!empty($order_ids)) {
          $orders = $order_storage->loadMultiple($order_ids);
          foreach ($orders as $order) {
            foreach ($order->getItems() as $order_item) {
              // Check if order item has event reference.
              if ($order_item->hasField('field_target_event') && !$order_item->get('field_target_event')->isEmpty()) {
                $event_nid = (int) $order_item->get('field_target_event')->target_id;
                if ($event_nid) {
                  $quantity = (int) $order_item->getQuantity();
                  $ticket_counts[$event_nid] = ($ticket_counts[$event_nid] ?? 0) + $quantity;
                }
              }
              // Otherwise try via purchased variation -> product -> event.
              elseif ($order_item->hasField('purchased_entity') && !$order_item->get('purchased_entity')->isEmpty()) {
                $variation = $order_item->get('purchased_entity')->entity;
                if ($variation) {
                  // Check if variation has event field.
                  if ($variation->hasField('field_event') && !$variation->get('field_event')->isEmpty()) {
                    $event_nid = (int) $variation->get('field_event')->target_id;
                    if ($event_nid) {
                      $quantity = (int) $order_item->getQuantity();
                      $ticket_counts[$event_nid] = ($ticket_counts[$event_nid] ?? 0) + $quantity;
                    }
                  }
                  // Otherwise check via product.
                  elseif ($variation->hasField('product_id')) {
                    $product_id = $variation->get('product_id')->target_id;
                    if ($product_id) {
                      $product_storage = $this->entityTypeManager->getStorage('commerce_product');
                      $product = $product_storage->load($product_id);
                      if ($product && $product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
                        $event_nid = (int) $product->get('field_event')->target_id;
                        if ($event_nid) {
                          $quantity = (int) $order_item->getQuantity();
                          $ticket_counts[$event_nid] = ($ticket_counts[$event_nid] ?? 0) + $quantity;
                        }
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
      catch (\Exception $e) {
        // If ticket query fails, continue with RSVP counts only.
        $ticket_counts = [];
      }

      // Load all events that have activity.
      $all_event_ids = array_unique(array_merge(array_keys($rsvp_counts), array_keys($ticket_counts)));

      if (empty($all_event_ids)) {
        return [];
      }

      // Load events and filter out promoted/boosted, only upcoming events.
      $node_storage = $this->entityTypeManager->getStorage('node');
      $events = $node_storage->loadMultiple($all_event_ids);

      $popularity_scores = [];
      foreach ($events as $event) {
        if (!$this->isValidEvent($event)) {
          continue;
        }

        $event_id = (int) $event->id();
        $score = ($rsvp_counts[$event_id] ?? 0) + ($ticket_counts[$event_id] ?? 0);
        // Note: Views/pageview metrics not yet available, so excluded for now.
        $popularity_scores[$event_id] = $score;
      }

      // Sort by score descending, then limit.
      arsort($popularity_scores);
      return array_slice(array_keys($popularity_scores), 0, $limit);
    }
    catch (\Exception $e) {
      // If metrics unavailable, return empty array (section will be hidden).
      return [];
    }
  }

  /**
   * Checks if an event is valid for popularity ranking.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  protected function isValidEvent(NodeInterface $event): bool {
    // Must be published.
    if (!$event->isPublished()) {
      return FALSE;
    }

    // Must be event content type.
    if ($event->bundle() !== 'event') {
      return FALSE;
    }

    // Exclude promoted/boosted events.
    if ($event->hasField('field_promoted') && !$event->get('field_promoted')->isEmpty()) {
      $promoted = $event->get('field_promoted')->value;
      if ($promoted) {
        return FALSE;
      }
    }

    // Must be upcoming (start date >= now).
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $start_date = $event->get('field_event_start')->date;
      if ($start_date) {
        $start_timestamp = $start_date->getTimestamp();
        if ($start_timestamp < time()) {
          return FALSE;
        }
      }
    }

    return TRUE;
  }

}
