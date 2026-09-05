<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce\Context;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_stock\StockLocationInterface;
use Drupal\commerce_stock\StockServiceConfigInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Limits extras to their owning store; never falls back to all locations.
 */
final class OperationalStockLocations implements StockServiceConfigInterface {

  public function __construct(
    private readonly StockServiceConfigInterface $inner,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly LockBackendInterface $lock,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function getTransactionLocation(Context $context, PurchasableEntityInterface $entity, $quantity) {
    if (!$this->applies($entity)) {
      return $this->inner->getTransactionLocation($context, $entity, $quantity);
    }
    $this->assertStore($context, $entity);
    return $this->ensureLocation($context->getStore());
  }

  public function getAvailabilityLocations(Context $context, PurchasableEntityInterface $entity) {
    if (!$this->applies($entity)) {
      return $this->inner->getAvailabilityLocations($context, $entity);
    }
    $this->assertStore($context, $entity);
    $id = $this->keyValue->get('myeventlane.operational_stock_locations')->get((string) $context->getStore()->id());
    $location = $id ? $this->entityTypeManager->getStorage('commerce_stock_location')->load($id) : NULL;
    return $location instanceof StockLocationInterface && $location->isActive() ? [$id => $location] : [];
  }

  /**
   * Creates a store's location on first stock entry, not on customer reads.
   */
  public function ensureLocation(StoreInterface $store): StockLocationInterface {
    $storeId = (int) $store->id();
    if ($storeId < 1) {
      throw new \LogicException('Stock requires a saved organiser store.');
    }
    $name = 'myeventlane_stock_location:' . $storeId;
    if (!$this->lock->acquire($name, 30)) {
      throw new \RuntimeException('The organiser stock location is being updated. Try again.');
    }
    try {
      $map = $this->keyValue->get('myeventlane.operational_stock_locations');
      $storage = $this->entityTypeManager->getStorage('commerce_stock_location');
      $id = $map->get((string) $storeId);
      if ($id) {
        $location = $storage->load($id);
        if (!$location instanceof StockLocationInterface || !$location->isActive()) {
          throw new \RuntimeException('The organiser stock location is missing or disabled.');
        }
        return $location;
      }
      $transaction = $this->database->startTransaction();
      try {
        $location = $storage->create([
          'type' => 'default',
          'name' => 'Inventory — store ' . $storeId,
          'status' => TRUE,
          'uid' => $store->getOwnerId(),
        ]);
        $location->save();
        $map->set((string) $storeId, (int) $location->id());
        return $location;
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
    }
    finally {
      $release = fn() => $this->lock->release($name);
      if ($this->database->inTransaction()) {
        $this->database->transactionManager()->addPostTransactionCallback($release);
      }
      else {
        $release();
      }
    }
  }

  private function applies(PurchasableEntityInterface $entity): bool {
    return (bool) $this->configFactory->get('myeventlane_commerce.operational_stock')->get('paid_stock_enabled')
      && in_array($entity->bundle(), OperationalVariationStockResolver::OPERATIONAL_VARIATION_BUNDLES, TRUE);
  }

  private function assertStore(Context $context, PurchasableEntityInterface $entity): void {
    $stores = $entity->getStores();
    if (count($stores) !== 1 || (int) reset($stores)->id() !== (int) $context->getStore()->id()) {
      throw new \LogicException('An extra must belong to exactly one organiser store and match the order store.');
    }
  }

}
