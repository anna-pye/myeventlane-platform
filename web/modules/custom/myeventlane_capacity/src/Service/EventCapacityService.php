<?php

declare(strict_types=1);

namespace Drupal\myeventlane_capacity\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Psr\Log\LoggerInterface;

/**
 * Capacity service for events.
 *
 * INVARIANT:
 * Ticket capacity MUST be enforced here (getCapacityTotal, getSoldCount,
 * assertCanBook). Do not rely on node edit form validation or UI state.
 * This protects Ticket UX (Phase 3A).
 */
final class EventCapacityService implements EventCapacityServiceInterface {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getCapacityTotal(NodeInterface $event): ?int {
    // Check field_event_capacity_total first.
    if ($event->hasField('field_event_capacity_total') && !$event->get('field_event_capacity_total')->isEmpty()) {
      $value = (int) $event->get('field_event_capacity_total')->value;
      return $value > 0 ? $value : NULL;
    }

    // Fallback to field_capacity if it exists.
    if ($event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()) {
      $value = (int) $event->get('field_capacity')->value;
      return $value > 0 ? $value : NULL;
    }

    // No capacity set = unlimited.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSoldCount(NodeInterface $event): int {
    $cache_key = 'capacity_sold:' . $event->id();
    $cache = $this->cache->get($cache_key);
    if ($cache) {
      return $cache->data;
    }

    $count = $this->computeSoldCount($event);
    $this->cache->set($cache_key, $count, time() + 300, ['node:' . $event->id()]);
    return $count;
  }

  /**
   * Computes sold count without caching.
   */
  private function computeSoldCount(NodeInterface $event): int {
    $eventId = (int) $event->id();
    $count = 0;

    // Determine event type.
    $eventType = 'rsvp';
    if ($event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
      $eventType = $event->get('field_event_type')->value ?? 'rsvp';
    }

    // Count RSVPs for RSVP events.
    if (in_array($eventType, ['rsvp', 'both'], TRUE)) {
      $count += $this->countRsvps($eventId);
    }

    // Count paid tickets for paid events.
    if (in_array($eventType, ['paid', 'both'], TRUE)) {
      $count += $this->countPaidTickets($eventId);
    }

    return $count;
  }

  /**
   * Counts confirmed RSVPs for an event.
   *
   * @throws \Throwable
   *   When RSVP storage is unavailable or the count query fails.
   */
  private function countRsvps(int $eventId): int {
    if (!$this->entityTypeManager->hasDefinition('rsvp_submission')) {
      $message = 'rsvp_submission entity type is not available.';
      $this->logger->error('Unable to count RSVPs for event @event_id: @message', [
        '@event_id' => $eventId,
        '@message' => $message,
      ]);
      throw new \RuntimeException($message);
    }

    try {
      $count = $this->entityTypeManager
        ->getStorage('rsvp_submission')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', $eventId)
        ->condition('status', 'confirmed')
        ->count()
        ->execute();

      return (int) $count;
    }
    catch (\Throwable $e) {
      $this->logger->error('Unable to count RSVPs for event @event_id: @message', [
        '@event_id' => $eventId,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Counts paid tickets for an event.
   *
   * Uses order_id + load instead of OrderItem::getOrder() to avoid
   * undefined $entity on EntityReferenceFieldItemList when the reference
   * is not resolved (e.g. during OrderStorage::postLoad / OrderRefresh).
   */
  private function countPaidTickets(int $eventId): int {
    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventId,
      ]);

      $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
      $count = 0;

      foreach ($orderItems as $item) {
        try {
          if ($item->get('order_id')->isEmpty()) {
            continue;
          }
          $orderId = $item->get('order_id')->target_id;
          if (!$orderId) {
            continue;
          }
          $order = $orderStorage->load($orderId);
          if ($order && $order->getState()->getId() === 'completed') {
            $count += (int) $item->getQuantity();
          }
        }
        catch (\Exception $e) {
          continue;
        }
      }

      return $count;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getRemaining(NodeInterface $event): ?int {
    $total = $this->getCapacityTotal($event);
    if ($total === NULL) {
      return NULL;
    }

    $sold = $this->getSoldCount($event);
    $remaining = $total - $sold;
    return max(0, $remaining);
  }

  /**
   * {@inheritdoc}
   */
  public function isSoldOut(NodeInterface $event): bool {
    $total = $this->getCapacityTotal($event);
    if ($total === NULL) {
      return FALSE;
    }

    $sold = $this->getSoldCount($event);
    return $sold >= $total;
  }

  /**
   * {@inheritdoc}
   */
  public function assertCanBook(NodeInterface $event, int $requested = 1): void {
    if ($this->isSoldOut($event)) {
      throw new CapacityExceededException('This event is sold out.');
    }

    $remaining = $this->getRemaining($event);
    if ($remaining !== NULL && $requested > $remaining) {
      throw new CapacityExceededException("Only {$remaining} ticket(s) remaining.");
    }
  }

  /**
   * Invalidates capacity cache for an event.
   *
   * @param int $eventId
   *   The event node ID.
   */
  public function invalidateCache(int $eventId): void {
    $this->cache->delete('capacity_sold:' . $eventId);
    $this->cache->delete('event_state:' . $eventId);
  }

}
