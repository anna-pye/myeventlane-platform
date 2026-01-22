<?php

declare(strict_types=1);

namespace Drupal\myeventlane_metrics\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_attendee\Service\AttendeeRepositoryResolver;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\node\NodeInterface;

/**
 * Centralized event metrics service.
 */
final class EventMetricsService implements EventMetricsServiceInterface {

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface $capacityService
   *   The capacity service.
   * @param \Drupal\myeventlane_attendee\Service\AttendeeRepositoryResolver $repositoryResolver
   *   The attendee repository resolver.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventCapacityServiceInterface $capacityService,
    private readonly AttendeeRepositoryResolver $repositoryResolver,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getCapacityTotal(NodeInterface $event): ?int {
    return $this->capacityService->getCapacityTotal($event);
  }

  /**
   * {@inheritdoc}
   */
  public function getAttendeeCount(NodeInterface $event): int {
    $cacheKey = $this->getCacheKey($event, 'attendee_count');
    $cached = $this->cache->get($cacheKey);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    $repository = $this->repositoryResolver->getRepository($event);
    $count = $repository->countByEvent($event);

    $this->cache->set($cacheKey, $count, $this->time->getRequestTime() + 300, $this->getCacheTags($event));
    return $count;
  }

  /**
   * {@inheritdoc}
   */
  public function getCheckedInCount(NodeInterface $event): int {
    $cacheKey = $this->getCacheKey($event, 'checked_in_count');
    $cached = $this->cache->get($cacheKey);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    $repository = $this->repositoryResolver->getRepository($event);
    $count = $repository->countCheckedIn($event);

    $this->cache->set($cacheKey, $count, $this->time->getRequestTime() + 300, $this->getCacheTags($event));
    return $count;
  }

  /**
   * {@inheritdoc}
   */
  public function getRemainingCapacity(NodeInterface $event): ?int {
    return $this->capacityService->getRemaining($event);
  }

  /**
   * {@inheritdoc}
   */
  public function isSoldOut(NodeInterface $event): bool {
    return $this->capacityService->isSoldOut($event);
  }

  /**
   * {@inheritdoc}
   */
  public function getRevenue(NodeInterface $event): ?Price {
    $cacheKey = $this->getCacheKey($event, 'revenue');
    $cached = $this->cache->get($cacheKey);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    $eventId = (int) $event->id();
    $totalAmount = 0.0;
    // @todo Get from event or config.
    $currencyCode = 'AUD';

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventId,
      ]);

      foreach ($orderItems as $orderItem) {
        if (!$orderItem instanceof OrderItemInterface) {
          continue;
        }

        // Safely load the order entity to avoid getOrder() warnings.
        if (!$orderItem->hasField('order_id') || $orderItem->get('order_id')->isEmpty()) {
          continue;
        }
        $order_id = $orderItem->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $this->entityTypeManager
            ->getStorage('commerce_order')
            ->load($order_id);
          if (!$order || $order->getState()->getId() !== 'completed') {
            continue;
          }

          $totalPrice = $orderItem->getTotalPrice();
          if ($totalPrice) {
            $totalAmount += (float) $totalPrice->getNumber();
            $currencyCode = $totalPrice->getCurrencyCode();
          }
        }
        catch (\Exception $e) {
          continue;
        }
      }
    }
    catch (\Exception $e) {
      // Commerce not available or error.
    }

    // If no revenue, return NULL (e.g., for RSVP-only events).
    if ($totalAmount === 0.0) {
      $result = NULL;
    }
    else {
      $result = new Price((string) $totalAmount, $currencyCode);
    }

    $this->cache->set($cacheKey, $result, $this->time->getRequestTime() + 300, $this->getCacheTags($event));
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getTicketBreakdown(NodeInterface $event): array {
    $cacheKey = $this->getCacheKey($event, 'ticket_breakdown');
    $cached = $this->cache->get($cacheKey);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    $eventId = (int) $event->id();
    $breakdown = [];

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventId,
      ]);

      foreach ($orderItems as $orderItem) {
        if (!$orderItem instanceof OrderItemInterface) {
          continue;
        }

        // Safely load the order entity to avoid getOrder() warnings.
        if (!$orderItem->hasField('order_id') || $orderItem->get('order_id')->isEmpty()) {
          continue;
        }
        $order_id = $orderItem->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $this->entityTypeManager
            ->getStorage('commerce_order')
            ->load($order_id);
          if (!$order || $order->getState()->getId() !== 'completed') {
            continue;
          }

          $purchasedEntity = $orderItem->getPurchasedEntity();
          $label = $purchasedEntity ? $purchasedEntity->label() : 'Unknown';
          $quantity = (int) $orderItem->getQuantity();
          $totalPrice = $orderItem->getTotalPrice();

          if (!isset($breakdown[$label])) {
            $breakdown[$label] = [
              'label' => $label,
              'sold' => 0,
              'revenue' => NULL,
            ];
          }

          $breakdown[$label]['sold'] += $quantity;
          if ($totalPrice) {
            if ($breakdown[$label]['revenue'] === NULL) {
              $breakdown[$label]['revenue'] = $totalPrice;
            }
            else {
              $breakdown[$label]['revenue'] = $breakdown[$label]['revenue']->add($totalPrice);
            }
          }
        }
        catch (\Exception $e) {
          continue;
        }
      }
    }
    catch (\Exception $e) {
      // Commerce not available or error.
    }

    $result = array_values($breakdown);
    $this->cache->set($cacheKey, $result, $this->time->getRequestTime() + 300, $this->getCacheTags($event));
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getCheckInRate(NodeInterface $event): ?float {
    $total = $this->getAttendeeCount($event);
    if ($total === 0) {
      return NULL;
    }

    $checkedIn = $this->getCheckedInCount($event);
    return ($checkedIn / $total) * 100.0;
  }

  /**
   * Invalidates cache for an event.
   *
   * @param int $eventId
   *   The event node ID.
   */
  public function invalidateCache(int $eventId): void {
    $prefixes = ['attendee_count', 'checked_in_count', 'revenue', 'ticket_breakdown'];
    foreach ($prefixes as $prefix) {
      $this->cache->delete($this->getCacheKeyById($eventId, $prefix));
    }
    // Also invalidate capacity cache.
    if (method_exists($this->capacityService, 'invalidateCache')) {
      $this->capacityService->invalidateCache($eventId);
    }
  }

  /**
   * Gets cache key for an event metric.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $metric
   *   The metric name.
   *
   * @return string
   *   The cache key.
   */
  private function getCacheKey(NodeInterface $event, string $metric): string {
    return $this->getCacheKeyById((int) $event->id(), $metric);
  }

  /**
   * Gets cache key by event ID.
   *
   * @param int $eventId
   *   The event node ID.
   * @param string $metric
   *   The metric name.
   *
   * @return string
   *   The cache key.
   */
  private function getCacheKeyById(int $eventId, string $metric): string {
    return "myeventlane_metrics:{$eventId}:{$metric}";
  }

  /**
   * Gets cache tags for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of cache tags.
   */
  private function getCacheTags(NodeInterface $event): array {
    return [
      'node:' . $event->id(),
      'node_list',
      'rsvp_submission_list',
      'event_attendee_list',
      'commerce_order_list',
    ];
  }

}
