<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Persists authoritative, expiring cart holds for finite operational add-ons.
 */
final class OperationalStockHoldStore implements OperationalStockHoldStoreInterface {

  private const TABLE = 'myeventlane_commerce_operational_stock_hold';

  private const DEFAULT_TTL = 900;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds the stable reservation key for one order and variation.
   */
  public static function reservationKey(int $orderId, int $variationId): string {
    return 'cart:' . $orderId . ':operational-variation:' . $variationId;
  }

  /**
   * {@inheritdoc}
   */
  public function upsert(int $orderId, int $variationId, int $quantity): void {
    if ($orderId < 1 || $variationId < 1 || $quantity < 1) {
      throw new \InvalidArgumentException('An operational stock hold requires a valid order, variation, and quantity.');
    }

    $now = $this->time->getRequestTime();
    $configuredTtl = (int) $this->configFactory
      ->get('myeventlane_commerce.operational_stock')
      ->get('hold_ttl');
    $ttl = $configuredTtl >= 60 ? $configuredTtl : self::DEFAULT_TTL;

    $this->database->merge(self::TABLE)
      ->key('reservation_key', self::reservationKey($orderId, $variationId))
      ->fields([
        'order_id' => $orderId,
        'variation_id' => $variationId,
        'quantity' => $quantity,
        'created' => $now,
        'expires' => $now + $ttl,
      ])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getHeldQuantity(int $variationId, ?string $excludedReservationKey = NULL): int {
    $query = $this->database->select(self::TABLE, 'h');
    $query->addExpression('COALESCE(SUM(quantity), 0)', 'held');
    $query
      ->condition('variation_id', $variationId)
      ->condition('expires', $this->time->getRequestTime(), '>');
    if ($excludedReservationKey !== NULL && $excludedReservationKey !== '') {
      $query->condition('reservation_key', $excludedReservationKey, '<>');
    }
    return (int) $query->execute()->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveQuantity(int $orderId, int $variationId): int {
    if ($orderId < 1 || $variationId < 1) {
      return 0;
    }
    return (int) ($this->database->select(self::TABLE, 'h')
      ->fields('h', ['quantity'])
      ->condition('reservation_key', self::reservationKey($orderId, $variationId))
      ->condition('expires', $this->time->getRequestTime(), '>')
      ->execute()
      ->fetchField() ?: 0);
  }

  /**
   * {@inheritdoc}
   */
  public function releaseStaleForOrder(int $orderId, array $validReservationKeys): void {
    if ($orderId < 1) {
      return;
    }
    $query = $this->database->delete(self::TABLE)
      ->condition('order_id', $orderId);
    if ($validReservationKeys !== []) {
      $query->condition('reservation_key', array_values($validReservationKeys), 'NOT IN');
    }
    $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function releaseOrder(int $orderId): void {
    if ($orderId < 1) {
      return;
    }
    $this->database->delete(self::TABLE)
      ->condition('order_id', $orderId)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function purgeExpired(): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('expires', $this->time->getRequestTime(), '<=')
      ->execute();
  }

}
