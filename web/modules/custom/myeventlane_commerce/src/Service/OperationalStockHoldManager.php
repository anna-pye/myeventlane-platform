<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_stock\StockServiceManager;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\myeventlane_commerce\Exception\OperationalStockUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * Reserves finite add-on stock while keeping Commerce Stock authoritative.
 */
final class OperationalStockHoldManager {

  private const LOCK_TIMEOUT = 30;

  public function __construct(
    private readonly ?StockServiceManager $stockServiceManager,
    private readonly OperationalStockHoldStoreInterface $holdStore,
    private readonly LockBackendInterface $lock,
    private readonly LoggerInterface $logger,
    private readonly ?Connection $database = NULL,
  ) {}

  /**
   * Creates or refreshes all finite operational holds for a cart.
   */
  public function refresh(OrderInterface $cart): void {
    if ($this->stockServiceManager === NULL) {
      return;
    }

    $orderId = (int) $cart->id();
    if ($orderId < 1) {
      throw new \LogicException('The cart must be saved before operational stock can be held.');
    }

    $quantities = $this->extractQuantities($cart);
    if ($quantities === []) {
      $this->holdStore->releaseOrder($orderId);
      return;
    }

    $variationIds = array_keys($quantities);
    sort($variationIds, SORT_NUMERIC);
    $locks = $this->acquireLocks($variationIds);

    try {
      $validKeys = [];
      $validatedHolds = [];
      foreach ($variationIds as $variationId) {
        $variation = $quantities[$variationId]['variation'];
        $quantity = $quantities[$variationId]['quantity'];
        if ($this->isAlwaysInStock($variation)) {
          continue;
        }

        $key = OperationalStockHoldStore::reservationKey($orderId, $variationId);
        $heldByOthers = $this->holdStore->getHeldQuantity($variationId, $key);
        $stockLevel = max(0, (int) floor((float) $this->stockServiceManager->getStockLevel($variation)));
        $available = max(0, $stockLevel - $heldByOthers);
        if ($quantity > $available) {
          throw new OperationalStockUnavailableException(sprintf(
            'Only %d of this add-on remain available.',
            $available,
          ));
        }

        $validKeys[] = $key;
        $validatedHolds[] = [$variationId, $quantity];
      }
      // Do not extend any hold until the full cart passes validation.
      foreach ($validatedHolds as [$variationId, $quantity]) {
        $this->holdStore->upsert($orderId, $variationId, $quantity);
      }
      $this->holdStore->releaseStaleForOrder($orderId, $validKeys);
    }
    finally {
      $this->releaseLocks($locks);
    }
  }

  /**
   * Releases all operational stock holds for an order.
   */
  public function release(OrderInterface $order): void {
    $this->holdStore->releaseOrder((int) $order->id());
  }

  /**
   * Releases stock locks previously acquired for placement.
   *
   * Revalidates under locks and returns lock names for the caller to retain.
   *
   * @return string[]
   *   Acquired lock names. The caller must release them.
   */
  public function lockAndValidatePlacement(OrderInterface $order): array {
    if ($this->stockServiceManager === NULL) {
      return [];
    }

    $orderId = (int) $order->id();
    $quantities = $this->extractQuantities($order);
    if ($quantities === []) {
      return [];
    }

    $variationIds = array_keys($quantities);
    sort($variationIds, SORT_NUMERIC);
    $locks = $this->acquireLocks($variationIds);
    try {
      foreach ($variationIds as $variationId) {
        $variation = $quantities[$variationId]['variation'];
        if ($this->isAlwaysInStock($variation)) {
          continue;
        }
        $key = OperationalStockHoldStore::reservationKey($orderId, $variationId);
        $heldByOthers = $this->holdStore->getHeldQuantity($variationId, $key);
        $stockLevel = max(0, (int) floor((float) $this->stockServiceManager->getStockLevel($variation)));
        $available = max(0, $stockLevel - $heldByOthers);
        if ($quantities[$variationId]['quantity'] > $available) {
          throw new OperationalStockUnavailableException(sprintf(
            'Only %d of this add-on remain available.',
            $available,
          ));
        }
      }
      return $locks;
    }
    catch (\Throwable $e) {
      $this->releaseLocks($locks);
      throw $e;
    }
  }

  /**
   * Releases stock locks previously acquired for placement.
   *
   * @param string[] $locks
   *   Lock names returned by lockAndValidatePlacement().
   */
  public function releaseLocks(array $locks): void {
    $release = function () use ($locks): void {
      foreach ($locks as $name) {
        $this->lock->release($name);
      }
    };
    if ($this->database?->inTransaction()) {
      $this->database->transactionManager()->addPostTransactionCallback($release);
    }
    else {
      $release();
    }
  }

  /**
   * Extracts operational variation quantities from an order.
   *
   * @return array<int, array{variation: ProductVariationInterface, quantity: int}>
   *   Quantities keyed by variation ID.
   */
  public function extractQuantities(OrderInterface $order): array {
    $quantities = [];
    foreach ($order->getItems() as $item) {
      $variation = $item->getPurchasedEntity();
      if (!$variation instanceof ProductVariationInterface
        || !in_array($variation->bundle(), OperationalVariationStockResolver::OPERATIONAL_VARIATION_BUNDLES, TRUE)) {
        continue;
      }
      $variationId = (int) $variation->id();
      if ($variationId < 1) {
        continue;
      }
      if (!isset($quantities[$variationId])) {
        $quantities[$variationId] = [
          'variation' => $variation,
          'quantity' => 0,
        ];
      }
      $quantity = (float) $item->getQuantity();
      if (!is_finite($quantity) || $quantity < 1 || floor($quantity) !== $quantity) {
        throw new OperationalStockUnavailableException('Extra quantities must be positive whole numbers.');
      }
      $quantities[$variationId]['quantity'] += (int) $quantity;
    }
    return array_filter(
      $quantities,
      static fn(array $entry): bool => $entry['quantity'] > 0,
    );
  }

  /**
   * Checks whether Commerce Stock treats the variation as unlimited.
   */
  private function isAlwaysInStock(ProductVariationInterface $variation): bool {
    $service = $this->stockServiceManager->getService($variation);
    return $service->getStockChecker()->getIsAlwaysInStock($variation);
  }

  /**
   * Acquires operational stock locks in stable variation order.
   *
   * @param int[] $variationIds
   *   Variation IDs in stable order.
   *
   * @return string[]
   *   Acquired lock names.
   */
  public function acquireLocks(array $variationIds): array {
    $acquired = [];
    foreach ($variationIds as $variationId) {
      $name = 'myeventlane_operational_stock:' . $variationId;
      if (!$this->lock->acquire($name, self::LOCK_TIMEOUT)) {
        $this->releaseLocks($acquired);
        $this->logger->warning('Operational stock lock not acquired for variation @variation.', [
          '@variation' => $variationId,
        ]);
        throw new OperationalStockUnavailableException('This add-on is being purchased by someone else. Please try again.');
      }
      $acquired[] = $name;
    }
    return $acquired;
  }

}
