<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Cart-backed extras for the paid event book page "Your selection" sidebar.
 */
final class EventBookingSummaryCartExtrasBuilder {

  public function __construct(
    private readonly CartProviderInterface $cartProvider,
    private readonly EventOperationalAddonBuilder $addonBuilder,
    private readonly OperationalOrderItemDisplayBuilder $orderItemDisplayBuilder,
    private readonly OperationalExtraVisualPresenter $visualPresenter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds cart-backed extra lines for the booking summary sidebar.
   *
   * @return list<array{
   *   merge_key: string,
   *     product_id: int,
   *     size_key: string,
   *     title: string,
   *     qty: int,
   *     unit_number: string,
   *     currency: string,
   *   }>
   *   Sanitized extra lines keyed for client-side merge with in-form preview.
   */
  public function buildForEvent(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      return [];
    }

    $event_id = (int) $event->id();
    $stores = $this->collectStoresForEvent($event);
    if ($stores === []) {
      return [];
    }

    $lines = [];
    $seen_order_items = [];
    foreach ($stores as $store) {
      $cart = $this->cartProvider->getCart('default', $store);
      if (!$cart instanceof OrderInterface) {
        continue;
      }
      foreach ($cart->getItems() as $order_item) {
        if (!$order_item instanceof OrderItemInterface) {
          continue;
        }
        $oid = (int) $order_item->id();
        if ($oid > 0 && isset($seen_order_items[$oid])) {
          continue;
        }
        $line = $this->buildLineFromOrderItem($order_item, $event_id);
        if ($line === NULL) {
          continue;
        }
        if ($oid > 0) {
          $seen_order_items[$oid] = TRUE;
        }
        $lines[] = $line;
      }
    }

    return $lines;
  }

  /**
   * Collects stores for ticket and operational products on the event.
   *
   * @return array<string, \Drupal\commerce_store\Entity\StoreInterface>
   *   Store entities keyed by store ID.
   */
  private function collectStoresForEvent(NodeInterface $event): array {
    /** @var array<string, StoreInterface> $stores */
    $stores = [];

    if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
      $product = $event->get('field_product_target')->entity;
      if ($product instanceof ProductInterface) {
        foreach ($product->getStores() as $store) {
          if ($store instanceof StoreInterface) {
            $stores[(string) $store->id()] = $store;
          }
        }
      }
    }

    $built = $this->addonBuilder->buildForEvent($event);
    $product_ids = is_array($built['product_ids'] ?? NULL) ? $built['product_ids'] : [];
    if ($product_ids === []) {
      return $stores;
    }

    $storage = $this->entityTypeManager->getStorage('commerce_product');
    foreach ($product_ids as $pid) {
      $pid = (int) $pid;
      if ($pid < 1) {
        continue;
      }
      $product = $storage->load($pid);
      if (!$product instanceof ProductInterface) {
        continue;
      }
      foreach ($product->getStores() as $store) {
        if ($store instanceof StoreInterface) {
          $stores[(string) $store->id()] = $store;
        }
      }
    }

    return $stores;
  }

  /**
   * Builds one summary line from a cart order item for this event.
   *
   * @return array{
   *   merge_key: string,
   *     product_id: int,
   *     size_key: string,
   *     title: string,
   *     qty: int,
   *     unit_number: string,
   *     currency: string,
   *   }|null
   *   Summary line document, or NULL when the item is not an event extra.
   */
  private function buildLineFromOrderItem(OrderItemInterface $order_item, int $event_id): ?array {
    $display = $this->orderItemDisplayBuilder->buildForOrderItem($order_item);
    if ($display === NULL) {
      return NULL;
    }
    if ((int) ($display['event_id'] ?? 0) !== $event_id) {
      return NULL;
    }

    $purchased = $order_item->getPurchasedEntity();
    if (!$purchased instanceof ProductVariationInterface) {
      return NULL;
    }
    $product = $purchased->getProduct();
    if (!$product instanceof ProductInterface) {
      return NULL;
    }

    $qty = (int) round((float) $order_item->getQuantity());
    if ($qty < 1) {
      return NULL;
    }

    $unit_price = $order_item->getUnitPrice() ?? $purchased->getPrice();
    if ($unit_price === NULL) {
      return NULL;
    }

    $title = trim((string) ($display['title'] ?? ''));
    if ($title === '') {
      $title = trim((string) $product->label());
    }

    $size = $this->visualPresenter->resolveVariationSize($purchased);
    $size_key = (string) ($size['size_key'] ?? '');
    $size_label = trim((string) ($display['size_label'] ?? ''));
    if ($size_label === '' && $size_key !== '') {
      $size_label = (string) ($size['size_label'] ?? strtoupper($size_key));
    }
    if ($size_label !== '') {
      $title = $title . ' (' . $size_label . ')';
    }

    $product_id = (int) $product->id();
    $merge_key = $product_id . ':' . ($size_key !== '' ? $size_key : 'default');

    return [
      'merge_key' => $merge_key,
      'product_id' => $product_id,
      'size_key' => $size_key,
      'title' => $title,
      'qty' => $qty,
      'unit_number' => (string) $unit_price->getNumber(),
      'currency' => (string) $unit_price->getCurrencyCode(),
    ];
  }

}
