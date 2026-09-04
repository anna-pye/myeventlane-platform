<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_stock\StockServiceManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;

/**
 * Moves balances using audited transfers; never rewrites stock or order history.
 */
final class OperationalStockMigration {

  public function __construct(
    private readonly StockServiceManager $stock,
    private readonly OperationalStockLocations $locations,
    private readonly OperationalStockHoldManager $holds,
    private readonly OperationalStockSaleManager $sales,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyValueFactoryInterface $keyValue,
  ) {}

  public function migrate(): string {
    $marker = $this->keyValue->get('myeventlane.operational_stock_migrations');
    if ($marker->get('paid_stock_v1')) {
      return 'Stock hardening was already applied; no stock or orders changed.';
    }
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', OperationalVariationStockResolver::OPERATIONAL_VARIATION_BUNDLES, 'IN')
      ->sort('variation_id')->execute();
    $variations = $storage->loadMultiple($ids);
    // Validate ownership before any location, stock, or receipt is changed.
    foreach ($variations as $variation) {
      if (count($variation->getStores()) !== 1) {
        throw new \RuntimeException('Stock migration stopped: an extra has no unique organiser store.');
      }
    }
    $locks = $this->holds->acquireLocks(array_map('intval', array_values($ids)));
    $transaction = $this->database->startTransaction();
    try {
      $allLocations = $this->entityTypeManager->getStorage('commerce_stock_location')->loadMultiple();
      $moves = 0;
      foreach ($variations as $variation) {
        $stores = $variation->getStores();
        $target = $this->locations->ensureLocation(reset($stores));
        $checker = $this->stock->getService($variation)->getStockChecker();
        foreach ($allLocations as $id => $source) {
          if ((int) $id === (int) $target->getId()) {
            continue;
          }
          $quantity = (float) $checker->getTotalStockLevel($variation, [$id => $source]);
          if ($quantity == 0) {
            continue;
          }
          if ($quantity < 0 || floor($quantity) !== $quantity || !$source->isActive()) {
            throw new \RuntimeException('Stock migration stopped: negative, fractional, or disabled-location stock needs review.');
          }
          $this->stock->moveStock(
            $variation, $id, $target->getId(), '', '', (int) $quantity, NULL, NULL,
            'MyEventLane organiser stock isolation v1',
          );
          $moves++;
        }
      }
      // Existing paid orders are never charged stock again, even if an unlimited
      // extra later becomes finite. Record receipts without saving the orders.
      $orderIds = [];
      if ($ids !== []) {
        $items = $this->entityTypeManager->getStorage('commerce_order_item');
        $itemIds = $items->getQuery()->accessCheck(FALSE)
          ->condition('purchased_entity', array_values($ids), 'IN')->execute();
        foreach ($items->loadMultiple($itemIds) as $item) {
          if ($item->getOrderId()) {
            $orderIds[(int) $item->getOrderId()] = (int) $item->getOrderId();
          }
        }
      }
      $receipts = 0;
      foreach ($this->entityTypeManager->getStorage('commerce_order')->loadMultiple($orderIds) as $order) {
        if ($order->isPaid() && $this->holds->extractQuantities($order) !== []) {
          $this->sales->recordReceipt($order, 'legacy');
          $receipts++;
        }
      }
      $this->configFactory->getEditable('commerce_stock.core_stock_events')
        ->set('core_stock_events_order_complete_event_type', 'disabled')
        ->set('core_stock_events_order_cancel', FALSE)
        ->set('core_stock_events_order_updates', FALSE)->save();
      $this->configFactory->getEditable('commerce_stock_local.transactions')
        ->set('transactions_retention', 'keep')->save();
      $this->configFactory->getEditable('myeventlane_commerce.operational_stock')
        ->set('paid_stock_enabled', TRUE)->save();
      $marker->set('paid_stock_v1', TRUE);
      return sprintf('Stock hardening applied: %d audited transfers and %d legacy paid-order receipts. Original stock transactions and orders preserved.', $moves, $receipts);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      // Do not leave an in-memory active flag after a database rollback.
      $this->configFactory->reset();
      throw $e;
    }
    finally {
      $this->holds->releaseLocks($locks);
    }
  }

}
