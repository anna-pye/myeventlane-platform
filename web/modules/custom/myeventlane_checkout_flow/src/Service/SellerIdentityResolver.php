<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves and snapshots the organiser identity shown on buyer documents.
 */
final class SellerIdentityResolver {

  public const ORDER_DATA_KEY = 'mel_seller_identity_snapshot';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Captures seller identity before the order is placed.
   *
   * @return array{seller_name: string, seller_abn: string, store_id: int, vendor_id: int}
   */
  public function capture(OrderInterface $order): array {
    $existing = $this->snapshot($order);
    if ($existing !== NULL) {
      return $existing;
    }

    $identity = $this->resolveCurrent($order);
    if ($identity['seller_name'] !== '') {
      $order->setData(self::ORDER_DATA_KEY, $identity);
    }
    return $identity;
  }

  /**
   * Returns the immutable snapshot, with current data for legacy orders.
   *
   * @return array{seller_name: string, seller_abn: string, store_id: int, vendor_id: int}
   */
  public function resolve(OrderInterface $order): array {
    return $this->snapshot($order) ?? $this->resolveCurrent($order);
  }

  /**
   * @return array{seller_name: string, seller_abn: string, store_id: int, vendor_id: int}|null
   */
  private function snapshot(OrderInterface $order): ?array {
    $snapshot = $order->getData(self::ORDER_DATA_KEY);
    if (!is_array($snapshot) || trim((string) ($snapshot['seller_name'] ?? '')) === '') {
      return NULL;
    }

    return [
      'seller_name' => trim((string) $snapshot['seller_name']),
      'seller_abn' => trim((string) ($snapshot['seller_abn'] ?? '')),
      'store_id' => (int) ($snapshot['store_id'] ?? 0),
      'vendor_id' => (int) ($snapshot['vendor_id'] ?? 0),
    ];
  }

  /**
   * @return array{seller_name: string, seller_abn: string, store_id: int, vendor_id: int}
   */
  private function resolveCurrent(OrderInterface $order): array {
    $store = $order->getStore();
    if (!$store instanceof StoreInterface) {
      return $this->emptyIdentity();
    }

    $vendor = $this->loadVendorForStore((int) $store->id());
    $sellerName = $this->fieldValue($vendor, 'field_business_name');
    if ($sellerName === '' && $vendor instanceof ContentEntityInterface) {
      $sellerName = trim((string) $vendor->label());
    }
    if ($sellerName === '') {
      $address = $store->getAddress();
      $sellerName = $address ? trim((string) $address->getOrganization()) : '';
    }
    if ($sellerName === '') {
      $sellerName = trim((string) $store->label());
      $sellerName = preg_replace('/\s+Store$/i', '', $sellerName) ?? $sellerName;
    }

    return [
      'seller_name' => $sellerName,
      'seller_abn' => $this->abn($vendor) ?: $this->abn($store),
      'store_id' => (int) $store->id(),
      'vendor_id' => $vendor instanceof ContentEntityInterface ? (int) $vendor->id() : 0,
    ];
  }

  private function loadVendorForStore(int $storeId): ?ContentEntityInterface {
    if ($storeId <= 0 || !$this->entityTypeManager->hasDefinition('myeventlane_vendor')) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_vendor_store', $storeId)
      ->sort('id', 'ASC')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }

    $vendor = $storage->load(reset($ids));
    return $vendor instanceof ContentEntityInterface ? $vendor : NULL;
  }

  private function abn(?ContentEntityInterface $entity): string {
    return $this->fieldValue($entity, 'field_abn');
  }

  private function fieldValue(?ContentEntityInterface $entity, string $field): string {
    if (!$entity || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return '';
    }
    return trim($entity->get($field)->getString());
  }

  /**
   * @return array{seller_name: string, seller_abn: string, store_id: int, vendor_id: int}
   */
  private function emptyIdentity(): array {
    return [
      'seller_name' => '',
      'seller_abn' => '',
      'store_id' => 0,
      'vendor_id' => 0,
    ];
  }

}
