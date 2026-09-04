<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_stock\StockServiceManager;
use Drupal\commerce_stock\StockTransactionsInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_commerce\Exception\OperationalStockUnavailableException;

/**
 * Writes paid sales once, using the existing Commerce Stock transaction ledger.
 *
 * Historical placement sales are recognised, never recreated or rewritten.
 */
final class OperationalStockSaleManager {

  private const TABLE = 'myeventlane_commerce_operational_stock_sale';

  public function __construct(
    private readonly ?StockServiceManager $stock,
    private readonly OperationalStockHoldManager $holds,
    private readonly OperationalStockHoldStoreInterface $holdStore,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ) {}

  public function isEnabled(): bool {
    return (bool) $this->configFactory->get('myeventlane_commerce.operational_stock')->get('paid_stock_enabled');
  }

  public function commitPaid(OrderInterface $order): void {
    if (!$this->isEnabled()) {
      return;
    }
    if (!$order->isPaid() || $order->getState()->getId() === 'canceled') {
      throw new OperationalStockUnavailableException('Extras can only be issued for a paid, active order.');
    }
    $lines = $this->holds->extractQuantities($order);
    if ($lines === []) {
      return;
    }
    $events = $this->configFactory->get('commerce_stock.core_stock_events');
    if ($events->get('core_stock_events_order_complete_event_type') !== 'disabled'
      || $events->get('core_stock_events_order_cancel')
      || $events->get('core_stock_events_order_updates')) {
      throw new OperationalStockUnavailableException('Automatic stock events conflict with paid extras. An administrator must review the stock configuration.');
    }
    if ($this->stock === NULL || (int) $order->id() < 1) {
      throw new OperationalStockUnavailableException('Stock confirmation is unavailable. Contact the organiser.');
    }
    $ids = array_keys($lines);
    sort($ids, SORT_NUMERIC);
    $locks = $this->holds->acquireLocks($ids);
    $transaction = $this->database->startTransaction();
    try {
      $signature = $this->signature($order);
      $receipt = $this->database->select(self::TABLE, 's')->fields('s', ['signature'])
        ->condition('order_id', (int) $order->id())->execute()->fetchField();
      if ($receipt !== FALSE) {
        if (!hash_equals((string) $receipt, $signature)) {
          throw new OperationalStockUnavailableException('Paid extras cannot be changed. Refund the original items and create a new order.');
        }
        return;
      }
      $pending = [];
      foreach ($ids as $id) {
        $variation = $lines[$id]['variation'];
        if ($this->entityTypeManager !== NULL) {
          $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->loadUnchanged($id);
          if (!$variation instanceof ProductVariationInterface) {
            throw new OperationalStockUnavailableException('This extra is no longer available.');
          }
        }
        $quantity = $lines[$id]['quantity'];
        $stores = $variation->getStores();
        if (count($stores) !== 1 || (int) reset($stores)->id() !== (int) $order->getStoreId()) {
          throw new OperationalStockUnavailableException('This extra does not belong to the order organiser.');
        }
        $service = $this->stock->getService($variation);
        if ($service->getId() !== 'local_stock') {
          throw new OperationalStockUnavailableException('The extra stock service is misconfigured. An administrator must review it.');
        }
        $sold = $this->soldQuantity($order, $variation);
        if ($sold > 0) {
          if ($sold !== $quantity) {
            throw new OperationalStockUnavailableException('This order has changed since its stock allocation. Manual review is required.');
          }
          continue;
        }
        if ($service->getStockChecker()->getIsAlwaysInStock($variation)) {
          continue;
        }
        $context = StockServiceManager::createContextFromOrder($order);
        $locations = $service->getConfiguration()->getAvailabilityLocations($context, $variation);
        $level = (int) floor((float) $service->getStockChecker()->getTotalStockLevel($variation, $locations));
        $held = $this->holdStore->getHeldQuantity($id, OperationalStockHoldStore::reservationKey((int) $order->id(), $id));
        if ($quantity > max(0, $level - $held)) {
          // Do not issue a pass or invent inventory after a late payment.
          throw new OperationalStockUnavailableException('Payment needs stock review: this extra is no longer available. Contact the organiser.');
        }
        $location = $service->getConfiguration()->getTransactionLocation($context, $variation, -$quantity);
        if ($location === NULL || !isset($locations[$location->getId()])) {
          throw new OperationalStockUnavailableException('The organiser stock location is unavailable.');
        }
        $pending[] = [$variation, $quantity, $service, $location];
      }
      // Validate every line before writing any sale.
      foreach ($pending as [$variation, $quantity, $service, $location]) {
        $service->getStockUpdater()->createTransaction(
          $variation, $location->getId(), '', -$quantity, NULL,
          $order->getTotalPrice()?->getCurrencyCode(), StockTransactionsInterface::STOCK_SALE,
          [
            'related_oid' => (int) $order->id(),
            'related_uid' => (int) $order->getCustomerId(),
            'data' => ['message' => 'MyEventLane confirmed paid extras', 'source' => 'mel_paid_stock_v1'],
          ],
        );
      }
      $this->holds->release($order);
      $this->recordReceipt($order, 'paid');
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    finally {
      // Includes the enclosing Commerce order/payment save, not just this savepoint.
      $this->holds->releaseLocks($locks);
    }
  }

  /**
   * Registers an existing paid order during migration without a new deduction.
   */
  public function recordReceipt(OrderInterface $order, string $source): void {
    if ($this->holds->extractQuantities($order) === []) {
      return;
    }
    $this->database->merge(self::TABLE)->key('order_id', (int) $order->id())
      ->insertFields(['order_id' => (int) $order->id(), 'signature' => $this->signature($order), 'source' => $source])->execute();
  }

  public function signature(OrderInterface $order): string {
    $items = [];
    foreach ($order->getItems() as $item) {
      $variation = $item->getPurchasedEntity();
      if ($variation instanceof ProductVariationInterface
        && in_array($variation->bundle(), OperationalVariationStockResolver::OPERATIONAL_VARIATION_BUNDLES, TRUE)) {
        $items[(int) $item->id()] = [(int) $variation->id(), (string) $item->getQuantity()];
      }
    }
    ksort($items, SORT_NUMERIC);
    return hash('sha256', json_encode([(int) $order->getStoreId(), $items], JSON_THROW_ON_ERROR));
  }

  public function soldQuantity(OrderInterface $order, ProductVariationInterface $variation): int {
    $query = $this->database->select('commerce_stock_transaction', 't');
    $query->addExpression('COALESCE(SUM(qty), 0)', 'quantity');
    return (int) -$query
      ->condition('entity_type', 'commerce_product_variation')
      ->condition('entity_id', (int) $variation->id())
      ->condition('related_oid', (int) $order->id())
      ->condition('transaction_type_id', StockTransactionsInterface::STOCK_SALE)
      ->execute()->fetchField();
  }

}
