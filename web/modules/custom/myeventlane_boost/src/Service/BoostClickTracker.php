<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tracks Boost clicks server-side.
 *
 * Increments click counts when users visit event pages via Boost links.
 * Validates boost order item and event relationship before tracking.
 */
final class BoostClickTracker {

  /**
   * Constructs a BoostClickTracker.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Records a click for a Boost placement.
   *
   * Validates that:
   * - Boost order item exists and has bundle 'boost'.
   * - Boost order item targets the specified event.
   * - Placement parameter is provided.
   *
   * @param int $boost_order_item_id
   *   The Commerce order item ID (bundle: boost).
   * @param int $event_id
   *   The event node ID.
   * @param string $placement
   *   The placement identifier (required).
   *
   * @return bool
   *   TRUE if click was recorded, FALSE on validation failure or error.
   */
  public function recordClick(int $boost_order_item_id, int $event_id, string $placement): bool {
    if ($boost_order_item_id <= 0 || $event_id <= 0 || empty($placement)) {
      $this->logger->warning('Invalid click tracking parameters', [
        'boost_order_item_id' => $boost_order_item_id,
        'event_id' => $event_id,
        'placement' => $placement,
      ]);
      return FALSE;
    }

    try {
      // Validate boost order item exists and targets this event.
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItem = $orderItemStorage->load($boost_order_item_id);

      if (!$orderItem || $orderItem->bundle() !== 'boost') {
        $this->logger->warning('Invalid boost order item for click tracking', [
          'boost_order_item_id' => $boost_order_item_id,
          'event_id' => $event_id,
        ]);
        return FALSE;
      }

      // Check if order item targets this event.
      if (!$orderItem->hasField('field_target_event')) {
        $this->logger->warning('Boost order item missing field_target_event', [
          'boost_order_item_id' => $boost_order_item_id,
        ]);
        return FALSE;
      }

      $targetEventId = (int) ($orderItem->get('field_target_event')->target_id ?? 0);
      if ($targetEventId !== $event_id) {
        $this->logger->warning('Boost order item does not target this event', [
          'boost_order_item_id' => $boost_order_item_id,
          'expected_event_id' => $event_id,
          'actual_event_id' => $targetEventId,
        ]);
        return FALSE;
      }

      $now = $this->time->getRequestTime();

      $query = $this->database->merge('myeventlane_boost_stats')
        ->key([
          'boost_order_item_id' => $boost_order_item_id,
          'placement' => $placement,
        ])
        ->insertFields([
          'event_id' => $event_id,
          'clicks' => 1,
          'created' => $now,
          'changed' => $now,
        ])
        ->updateFields([
          'event_id' => $event_id,
          'changed' => $now,
        ]);
      $query->expression('clicks', 'clicks + 1');

      $query->execute();

      if ($this->database->schema()->tableExists('myeventlane_boost_stats_log')) {
        $this->database->insert('myeventlane_boost_stats_log')
          ->fields([
            'boost_order_item_id' => $boost_order_item_id,
            'event_id' => $event_id,
            'placement' => $placement,
            'kind' => 'click',
            'occurred_at' => $now,
          ])
          ->execute();
      }
      if ($this->database->schema()->tableExists('myeventlane_boost_click_log')) {
        $this->database->insert('myeventlane_boost_click_log')
          ->fields([
            'boost_order_item_id' => $boost_order_item_id,
            'event_id' => $event_id,
            'placement' => $placement,
            'clicked_at' => $now,
          ])
          ->execute();
      }

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to record Boost click', [
        'boost_order_item_id' => $boost_order_item_id,
        'event_id' => $event_id,
        'placement' => $placement,
        'error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
