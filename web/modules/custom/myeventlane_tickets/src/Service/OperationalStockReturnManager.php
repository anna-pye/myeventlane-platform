<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_stock\StockServiceManager;
use Drupal\commerce_stock\StockTransactionsInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\myeventlane_commerce\Service\OperationalVariationStockResolver;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Reconciles add-on refunds and cancellations through one capped return ledger.
 */
final class OperationalStockReturnManager {

  private const TABLE = 'myeventlane_commerce_operational_stock_return';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly ?StockServiceManager $stockServiceManager,
    private readonly LockBackendInterface $lock,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Reconciles one confirmed refund idempotently.
   */
  public function reconcile(OrderInterface $order, NodeInterface $event, array $log): int {
    if ($this->stockServiceManager === NULL) {
      return 0;
    }

    $refundLogId = (int) ($log['id'] ?? 0);
    $quantities = $this->decodeQuantities((string) ($log['operational_item_quantities_json'] ?? ''));
    if ($refundLogId < 1 || $quantities === []) {
      return 0;
    }

    $items = $this->indexOrderItems($order);
    $reconciled = 0;
    foreach ($quantities as $orderItemId => $quantity) {
      $item = $items[$orderItemId] ?? NULL;
      if (!$item instanceof OrderItemInterface || !$this->itemIsSafeTarget($item, $event, $quantity)) {
        $this->logger->error('Skipped add-on return for refund @refund item @item: the persisted line selection is invalid.', [
          '@refund' => $refundLogId,
          '@item' => $orderItemId,
        ]);
        continue;
      }

      $sourceKey = 'refund:' . $refundLogId . ':item:' . $orderItemId;
      if (!$this->acquire((int) $item->id())) {
        continue;
      }
      try {
        if ($this->alreadyReconciled($sourceKey)) {
          continue;
        }
        $entitlements = $this->loadEntitlements($order, $item);
        $alreadyReturned = $this->returnedQuantity((int) $item->id());
        if (!$this->refundEntitlementsAreComplete($entitlements, $quantity)) {
          $this->logger->error('Add-on reconciliation blocked for refund @refund item @item: entitlement issuance is incomplete.', [
            '@refund' => $refundLogId,
            '@item' => $orderItemId,
          ]);
          continue;
        }

        $returnable = array_filter(
          $entitlements,
          fn(ContentEntityInterface $entitlement): bool => $this->entitlementCanReturnToStock($entitlement),
        );
        $remaining = max(0, $quantity - $alreadyReturned);
        $returnQuantity = min(count($returnable), $remaining);
        $this->executeReturn(
          $order,
          $item,
          $entitlements,
          Ticket::STATUS_REFUNDED,
          $returnQuantity,
          $sourceKey,
          'refund',
          $refundLogId,
        );
        $reconciled += $quantity;
      }
      catch (\Throwable $e) {
        // The money is already confirmed refunded. Fail closed: do not make
        // stock available unless entitlement and ledger updates both succeed.
        $this->logger->error('Add-on stock return failed for refund @refund item @item: @message', [
          '@refund' => $refundLogId,
          '@item' => $orderItemId,
          '@message' => $e->getMessage(),
        ]);
      }
      finally {
        $this->releaseAfterCommit($this->lockName((int) $item->id()));
      }
    }
    return $reconciled;
  }

  /**
   * Returns unfulfilled units when a previously placed order is cancelled.
   */
  public function reconcileCancellation(OrderInterface $order, string $fromState): int {
    if ($this->stockServiceManager === NULL) {
      return 0;
    }

    if ($fromState === 'draft') {
      return 0;
    }

    $reconciled = 0;
    foreach ($order->getItems() as $item) {
      if (!$item instanceof OrderItemInterface || !$this->isOperationalItem($item)) {
        continue;
      }
      $quantity = max(0, (int) round((float) $item->getQuantity()));
      if ($quantity < 1) {
        continue;
      }
      $sourceKey = 'cancel:' . (int) $order->id() . ':item:' . (int) $item->id();
      if (!$this->acquire((int) $item->id())) {
        continue;
      }
      try {
        if ($this->alreadyReconciled($sourceKey)) {
          continue;
        }
        $entitlements = $this->loadEntitlements($order, $item);
        if ($entitlements !== [] && count($entitlements) !== $quantity) {
          $this->logger->error('Add-on cancellation return blocked for item @item: entitlement count is ambiguous.', [
            '@item' => (int) $item->id(),
          ]);
          continue;
        }
        $eligible = array_values(array_filter(
          $entitlements,
          fn(ContentEntityInterface $entitlement): bool => $this->entitlementCanReturnToStock($entitlement),
        ));
        $eligibleQuantity = $entitlements === [] ? $quantity : count($eligible);
        $remaining = max(0, $quantity - $this->returnedQuantity((int) $item->id()));
        $returnQuantity = min($eligibleQuantity, $remaining);
        $returnQuantity = $this->executeReturn(
          $order,
          $item,
          $eligible,
          Ticket::STATUS_VOID,
          $returnQuantity,
          $sourceKey,
          'cancel',
          (int) $order->id(),
        );
        $reconciled += $returnQuantity;
      }
      catch (\Throwable $e) {
        $this->logger->error('Add-on stock return failed for cancelled order @order item @item: @message', [
          '@order' => (int) $order->id(),
          '@item' => (int) $item->id(),
          '@message' => $e->getMessage(),
        ]);
      }
      finally {
        $this->releaseAfterCommit($this->lockName((int) $item->id()));
      }
    }
    return $reconciled;
  }

  /**
   * Updates entitlements, Commerce Stock, and idempotency in one transaction.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The sold order.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The operational order item.
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $entitlements
   *   Operational entitlements to cancel.
   * @param string $entitlementStatus
   *   Final entitlement status.
   * @param int $returnQuantity
   *   Quantity to return to finite stock.
   * @param string $sourceKey
   *   Globally unique return source key.
   * @param string $sourceType
   *   Return source type.
   * @param int $sourceId
   *   Refund log or order ID.
   */
  private function executeReturn(
    OrderInterface $order,
    OrderItemInterface $item,
    array $entitlements,
    string $entitlementStatus,
    int $returnQuantity,
    string $sourceKey,
    string $sourceType,
    int $sourceId,
  ): int {
    $variation = $item->getPurchasedEntity();
    if (!$variation instanceof ProductVariationInterface) {
      throw new \RuntimeException('Operational order item has no product variation.');
    }
    $stockLock = 'myeventlane_operational_stock:' . (int) $variation->id();
    if (!$this->lock->acquire($stockLock, 30.0)) {
      throw new \RuntimeException('Stock is being updated; return reconciliation must be retried.');
    }
    $transaction = $this->database->startTransaction();
    $this->releaseAfterCommit($stockLock);
    $service = $this->stockServiceManager->getService($variation);
    $alwaysInStock = $service->getStockChecker()->getIsAlwaysInStock($variation);
    // Cancellation of an unpaid order must never manufacture inventory.
    // Include previous returns from any source, not only the MEL return table.
    $query = $this->database->select('commerce_stock_transaction', 't');
    $query->addExpression('COALESCE(SUM(qty), 0)', 'net_sold');
    $netSold = -(float) $query
      ->condition('entity_type', 'commerce_product_variation')
      ->condition('entity_id', (int) $variation->id())
      ->condition('related_oid', (int) $order->id())
      ->condition('transaction_type_id', [StockTransactionsInterface::STOCK_SALE, StockTransactionsInterface::STOCK_RETURN], 'IN')
      ->execute()->fetchField();
    // A later catalogue switch to unlimited must not erase a finite sale.
    if ($netSold > 0) {
      $alwaysInStock = FALSE;
    }
    if (!$alwaysInStock) {
      $returnQuantity = min($returnQuantity, max(0, (int) floor($netSold)));
    }
    $location = NULL;
    if ($returnQuantity > 0 && !$alwaysInStock) {
      $location = $this->stockServiceManager->getTransactionLocation(
        StockServiceManager::createContextFromOrder($order),
        $variation,
        $returnQuantity,
      );
      if ($location === NULL) {
        throw new \RuntimeException('Commerce Stock did not resolve a return location.');
      }
    }

    try {
      foreach ($entitlements as $entitlement) {
        $cancelFulfilment = $this->entitlementCanReturnToStock($entitlement);
        $entitlement->set('status', $entitlementStatus);
        if ($cancelFulfilment) {
          $entitlement->set('fulfilment_status', Ticket::FULFILMENT_CANCELLED);
        }
        $entitlement->save();
      }
      if ($returnQuantity > 0 && !$alwaysInStock) {
        $service->getStockUpdater()->createTransaction(
          $variation,
          $location->getId(),
          '',
          $returnQuantity,
          NULL,
          $item->getTotalPrice()?->getCurrencyCode(),
          StockTransactionsInterface::STOCK_RETURN,
          [
            'related_oid' => (int) $order->id(),
            'related_uid' => (int) $order->getCustomerId(),
            'data' => ['message' => 'MyEventLane add-on stock return: ' . $sourceKey],
          ],
        );
      }
      $this->database->insert(self::TABLE)
        ->fields([
          'source_key' => $sourceKey,
          'source_type' => $sourceType,
          'source_id' => $sourceId,
          'order_id' => (int) $order->id(),
          'order_item_id' => (int) $item->id(),
          'variation_id' => (int) $variation->id(),
          // Record the logical quantity even when the variation is unlimited.
          // This keeps refund and cancellation reconciliation idempotent.
          'quantity' => $returnQuantity,
          'created' => time(),
        ])
        ->execute();
      return $returnQuantity;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Loads operational entitlements for one order item in stable order.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   Entitlements for the order item.
   */
  private function loadEntitlements(OrderInterface $order, OrderItemInterface $item): array {
    $storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', (int) $order->id())
      ->condition('order_item_id', (int) $item->id())
      ->condition('entitlement_type', Ticket::ENTITLEMENT_TICKET, '<>')
      ->sort('id', 'ASC')
      ->execute();
    $storage->resetCache(array_values($ids));
    return array_values($storage->loadMultiple($ids));
  }

  /**
   * Checks that every sold add-on unit has an entitlement to invalidate.
   */
  private function refundEntitlementsAreComplete(array $entitlements, int $quantity): bool {
    if (count($entitlements) !== $quantity) {
      return FALSE;
    }
    foreach ($entitlements as $entitlement) {
      if (!$entitlement instanceof ContentEntityInterface) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Checks whether an entitlement still represents returnable physical stock.
   */
  private function entitlementCanReturnToStock(ContentEntityInterface $entitlement): bool {
    $status = (string) ($entitlement->get('status')->value ?? '');
    $fulfilment = (string) ($entitlement->get('fulfilment_status')->value ?? '');
    return !in_array($status, [Ticket::STATUS_REFUNDED, Ticket::STATUS_VOID], TRUE)
      && in_array($fulfilment, [
        Ticket::FULFILMENT_PENDING,
        Ticket::FULFILMENT_PREPARING,
        Ticket::FULFILMENT_READY,
      ], TRUE);
  }

  /**
   * Validates that a refund target is the full operational line for its event.
   */
  private function itemIsSafeTarget(OrderItemInterface $item, NodeInterface $event, int $quantity): bool {
    return $this->isOperationalItem($item)
      && $quantity === max(0, (int) round((float) $item->getQuantity()))
      && $item->hasField('field_target_event')
      && !$item->get('field_target_event')->isEmpty()
      && (int) $item->get('field_target_event')->target_id === (int) $event->id();
  }

  /**
   * Checks whether an order item is a managed operational add-on.
   */
  private function isOperationalItem(OrderItemInterface $item): bool {
    $variation = $item->getPurchasedEntity();
    return $variation instanceof ProductVariationInterface
      && in_array($variation->bundle(), OperationalVariationStockResolver::OPERATIONAL_VARIATION_BUNDLES, TRUE);
  }

  /**
   * Indexes an order's items by ID.
   *
   * @return array<int, \Drupal\commerce_order\Entity\OrderItemInterface>
   *   Order items keyed by ID.
   */
  private function indexOrderItems(OrderInterface $order): array {
    $items = [];
    foreach ($order->getItems() as $item) {
      $items[(int) $item->id()] = $item;
    }
    return $items;
  }

  /**
   * Gets stock already returned for one sold order item from any source.
   */
  private function returnedQuantity(int $orderItemId): int {
    $query = $this->database->select(self::TABLE, 'r');
    $query->addExpression('COALESCE(SUM(quantity), 0)', 'returned');
    return (int) $query
      ->condition('order_item_id', $orderItemId)
      ->execute()
      ->fetchField();
  }

  /**
   * Checks the idempotency ledger for a return source.
   */
  private function alreadyReconciled(string $sourceKey): bool {
    return (bool) $this->database->select(self::TABLE, 'r')
      ->condition('source_key', $sourceKey)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Acquires the lock shared by issuance, refunds, and cancellation.
   */
  private function acquire(int $orderItemId): bool {
    $name = $this->lockName($orderItemId);
    $acquired = $this->lock->acquire($name, 30.0);
    if (!$acquired) {
      $this->lock->wait($name, 5);
      $acquired = $this->lock->acquire($name, 30.0);
    }
    if (!$acquired) {
      $this->logger->warning('Skipped concurrent add-on stock return for order item @item.', [
        '@item' => $orderItemId,
      ]);
    }
    return $acquired;
  }

  /**
   * Builds the shared operational order-item lock name.
   */
  private function lockName(int $orderItemId): string {
    return 'myeventlane_operational_item:' . $orderItemId;
  }

  private function releaseAfterCommit(string $name): void {
    $release = fn() => $this->lock->release($name);
    if ($this->database->inTransaction()) {
      $this->database->transactionManager()->addPostTransactionCallback($release);
    }
    else {
      $release();
    }
  }

  /**
   * Decodes positive order-item quantities from persisted JSON.
   *
   * @return array<int, int>
   *   Quantities keyed by order item ID.
   */
  private function decodeQuantities(string $json): array {
    $decoded = json_decode($json, TRUE);
    if (!is_array($decoded)) {
      return [];
    }
    $quantities = [];
    foreach ($decoded as $itemId => $quantity) {
      $itemId = (int) $itemId;
      $quantity = (int) $quantity;
      if ($itemId > 0 && $quantity > 0) {
        $quantities[$itemId] = $quantity;
      }
    }
    return $quantities;
  }

}
