<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_core\Utility\EntityLoadIds;

/**
 * Completed-order quantity per Commerce variation and event (shared query).
 */
final class TicketVariationSoldService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Completed-order quantity for this variation and event.
   */
  public function countCompletedSoldForVariation(int $eventId, int $variationId): int {
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
    $ids = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_target_event', $eventId)
      ->condition('purchased_entity', $variationId)
      ->execute();
    if (!$ids) {
      return 0;
    }
    $ids = array_values(array_unique(array_map(
      static fn ($id): int => (int) $id,
      array_filter(
        EntityLoadIds::normalizeForLoadMultiple($ids),
        static fn ($id): bool => is_numeric($id) && (int) $id > 0,
      )
    )));
    if ($ids === []) {
      return 0;
    }
    $items = $orderItemStorage->loadMultiple($ids);
    $count = 0;
    foreach ($items as $item) {
      if ($item->get('order_id')->isEmpty()) {
        continue;
      }
      $order = $orderStorage->load($item->get('order_id')->target_id);
      if ($order && $order->getState()->getId() === 'completed') {
        $count += (int) $item->getQuantity();
      }
    }
    return $count;
  }

  /**
   * Batch completed-order quantities for several variations at one event.
   *
   * @param list<int> $variationIds
   *
   * @return array<int, int>
   *   Variation ID => sold quantity.
   */
  public function countCompletedSoldForVariations(int $eventId, array $variationIds): array {
    $variationIds = array_values(array_unique(array_filter(array_map('intval', $variationIds))));
    $out = [];
    foreach ($variationIds as $vid) {
      $out[$vid] = 0;
    }
    if ($variationIds === []) {
      return $out;
    }

    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
    $ids = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_target_event', $eventId)
      ->condition('purchased_entity', $variationIds, 'IN')
      ->execute();
    if (!$ids) {
      return $out;
    }
    $ids = array_values(array_unique(array_map(
      static fn ($id): int => (int) $id,
      array_filter(
        EntityLoadIds::normalizeForLoadMultiple($ids),
        static fn ($id): bool => is_numeric($id) && (int) $id > 0,
      )
    )));
    if ($ids === []) {
      return $out;
    }
    $items = $orderItemStorage->loadMultiple($ids);
    foreach ($items as $item) {
      if ($item->get('order_id')->isEmpty()) {
        continue;
      }
      $order = $orderStorage->load($item->get('order_id')->target_id);
      if (!$order || $order->getState()->getId() !== 'completed') {
        continue;
      }
      $purchased = $item->getPurchasedEntity();
      $vid = $purchased ? (int) $purchased->id() : 0;
      if ($vid > 0 && isset($out[$vid])) {
        $out[$vid] += (int) $item->getQuantity();
      }
    }
    return $out;
  }

  /**
   * Sums line totals from completed orders for this variation and event.
   *
   * @return array{number: string, currency_code: string}|null
   *   NULL when no completed revenue exists.
   */
  public function sumCompletedRevenueForVariation(int $eventId, int $variationId): ?array {
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
    $ids = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_target_event', $eventId)
      ->condition('purchased_entity', $variationId)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $ids = array_values(array_unique(array_map(
      static fn ($id): int => (int) $id,
      array_filter(
        EntityLoadIds::normalizeForLoadMultiple($ids),
        static fn ($id): bool => is_numeric($id) && (int) $id > 0,
      )
    )));
    if ($ids === []) {
      return NULL;
    }
    $items = $orderItemStorage->loadMultiple($ids);
    $currency = '';
    $total = '0';
    foreach ($items as $item) {
      if ($item->get('order_id')->isEmpty()) {
        continue;
      }
      $order = $orderStorage->load($item->get('order_id')->target_id);
      if (!$order || $order->getState()->getId() !== 'completed') {
        continue;
      }
      $linePrice = $item->getTotalPrice();
      if (!$linePrice || !method_exists($linePrice, 'getCurrencyCode') || !method_exists($linePrice, 'getNumber')) {
        continue;
      }
      $code = strtoupper((string) $linePrice->getCurrencyCode());
      if ($currency === '') {
        $currency = $code;
      }
      elseif ($currency !== $code) {
        return NULL;
      }
      $total = function_exists('bcadd')
        ? bcadd($total, (string) $linePrice->getNumber(), 6)
        : (string) ((float) $total + (float) $linePrice->getNumber());
    }
    if ($currency === '') {
      return NULL;
    }
    return [
      'number' => $total,
      'currency_code' => $currency,
    ];
  }

}
