<?php

declare(strict_types=1);

namespace Drupal\myeventlane_metrics\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_analytics\Service\OrderItemClassifier;
use Drupal\myeventlane_attendee\Service\AttendeeRepositoryResolver;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container; used to resolve the attendee repository resolver on
   *   demand to avoid a circular dependency (metrics → resolver → ticket
   *   repository → vendor stack ↔ metrics).
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\myeventlane_analytics\Service\OrderItemClassifier $orderItemClassifier
   *   The order item classifier.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventCapacityServiceInterface $capacityService,
    private readonly ContainerInterface $container,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly OrderItemClassifier $orderItemClassifier,
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

    $repository = $this->attendeeRepositoryResolver()->getRepository($event);
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

    $repository = $this->attendeeRepositoryResolver()->getRepository($event);
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

        // Exclude Boost and donations (platform revenue only).
        if (!$this->orderItemClassifier->isVendorRevenueEligible($orderItem)) {
          continue;
        }

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

        // Exclude Boost and donations (platform revenue only).
        if (!$this->orderItemClassifier->isVendorRevenueEligible($orderItem)) {
          continue;
        }

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
          if (!$purchasedEntity) {
            continue;
          }

          $variationTitle = $purchasedEntity->label();
          $label = $variationTitle;
          if (strpos($variationTitle, ' – ') !== FALSE) {
            $parts = explode(' – ', $variationTitle, 2);
            $label = $parts[1] ?? $variationTitle;
          }

          $quantity = (int) $orderItem->getQuantity();
          $totalPrice = $orderItem->getTotalPrice();

          $stock = 'Unlimited';
          if ($purchasedEntity->hasField('field_stock') && !$purchasedEntity->get('field_stock')->isEmpty()) {
            $stock = (int) $purchasedEntity->get('field_stock')->value;
          }

          if (!isset($breakdown[$label])) {
            $breakdown[$label] = [
              'label' => $label,
              'sold' => 0,
              'revenue_raw' => 0.0,
              'stock' => $stock,
            ];
          }

          $breakdown[$label]['sold'] += $quantity;
          if ($totalPrice) {
            $breakdown[$label]['revenue_raw'] += (float) $totalPrice->getNumber();
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

    $formatted = [];
    foreach ($breakdown as $item) {
      $available = is_int($item['stock']) ? max(0, $item['stock'] - $item['sold']) : $item['stock'];
      $revenueFormatted = '$' . number_format($item['revenue_raw'], 2);

      $formatted[] = [
        'label' => $item['label'],
        'sold' => $item['sold'],
        'available' => $available,
        'revenue' => $revenueFormatted,
      ];
    }

    $this->cache->set($cacheKey, $formatted, $this->time->getRequestTime() + 300, $this->getCacheTags($event));
    return $formatted;
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
    if (method_exists($this->capacityService, 'invalidateCache')) {
      $this->capacityService->invalidateCache($eventId);
    }
  }

  /**
   * Gets cache key for an event metric.
   */
  /**
   * Resolves the attendee repository resolver when attendee metrics are needed.
   */
  private function attendeeRepositoryResolver(): AttendeeRepositoryResolver {
    if (!$this->container->has('myeventlane_attendee.repository_resolver')) {
      throw new \RuntimeException('Required service myeventlane_attendee.repository_resolver is not registered.');
    }
    $resolver = $this->container->get('myeventlane_attendee.repository_resolver');
    if (!$resolver instanceof AttendeeRepositoryResolver) {
      throw new \RuntimeException('Service myeventlane_attendee.repository_resolver must be an instance of AttendeeRepositoryResolver.');
    }
    return $resolver;
  }

  private function getCacheKey(NodeInterface $event, string $metric): string {
    return $this->getCacheKeyById((int) $event->id(), $metric);
  }

  /**
   * Gets cache key by event ID.
   */
  private function getCacheKeyById(int $eventId, string $metric): string {
    return "myeventlane_metrics:{$eventId}:{$metric}";
  }

  /**
   * Gets cache tags for an event.
   *
   * @return array<string>
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
