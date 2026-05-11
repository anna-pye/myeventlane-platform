<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_event\Value\EventCommercePurchasableType;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves existing Commerce entities linked to an event without mutating them.
 *
 * This service centralizes read-only resolution only. Product sync, cart
 * mutation, checkout, payments, fulfilment, and ticket issuance stay with their
 * existing owning services.
 */
final class EventCommerceResolver {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    private readonly EventCommerceClassificationRegistry $classificationRegistry,
  ) {}

  /**
   * Resolves the canonical ticket product referenced by an event.
   */
  public function resolveTicketProduct(NodeInterface $event): ?ProductInterface {
    if ($event->bundle() !== 'event') {
      $this->logger->warning('EventCommerceResolver received non-event node @nid for ticket product resolution.', [
        '@nid' => (string) ($event->id() ?? 'new'),
      ]);
      return NULL;
    }
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return NULL;
    }
    $product = $event->get('field_product_target')->entity;
    return $product instanceof ProductInterface ? $product : NULL;
  }

  /**
   * Resolves products that carry field_event for this event.
   *
   * @param list<string> $bundles
   *   Optional product bundle allow-list supplied by the owning domain.
   *
   * @return list<\Drupal\commerce_product\Entity\ProductInterface>
   */
  public function resolveEventLinkedProducts(NodeInterface $event, array $bundles = [], int $limit = 50): array {
    if ($event->bundle() !== 'event' || $event->id() === NULL) {
      $this->logger->warning('EventCommerceResolver cannot query event-linked products for invalid event node.');
      return [];
    }

    try {
      $query = $this->entityTypeManager
        ->getStorage('commerce_product')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_event', (int) $event->id())
        ->range(0, max(1, $limit));

      if ($bundles !== []) {
        $query->condition('type', $bundles, 'IN');
      }

      $ids = $query->execute();
      if ($ids === []) {
        return [];
      }

      $products = $this->entityTypeManager->getStorage('commerce_product')->loadMultiple($ids);
    }
    catch (\Throwable $exception) {
      $this->logger->error('EventCommerceResolver failed to resolve products for event @eid: @message', [
        '@eid' => (string) $event->id(),
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }

    return array_values(array_filter($products, static fn ($product): bool => $product instanceof ProductInterface));
  }

  /**
   * Resolves variations attached to the event ticket product.
   *
   * @return list<\Drupal\commerce_product\Entity\ProductVariationInterface>
   */
  public function resolveTicketLinkedPurchasables(NodeInterface $event): array {
    $product = $this->resolveTicketProduct($event);
    if (!$product instanceof ProductInterface || !method_exists($product, 'getVariations')) {
      return [];
    }

    return array_values(array_filter($product->getVariations(), static fn ($variation): bool => $variation instanceof ProductVariationInterface));
  }

  /**
   * Classifies a product using explicit owning-domain bundle metadata.
   *
   * @param array<string, string> $product_bundle_classifications
   *   Map of Commerce product bundle id to canonical classification id.
   */
  public function classifyProduct(ProductInterface $product, array $product_bundle_classifications = []): string {
    return $this->classificationRegistry->classifyProduct($product, $product_bundle_classifications);
  }

  /**
   * Classifies a purchasable using explicit owning-domain bundle metadata.
   *
   * @param array<string, string> $variation_bundle_classifications
   *   Map of Commerce variation bundle id to canonical classification id.
   * @param array<string, string> $product_bundle_classifications
   *   Map of Commerce product bundle id to canonical classification id.
   */
  public function classifyPurchasable(
    ProductVariationInterface $variation,
    array $variation_bundle_classifications = [],
    array $product_bundle_classifications = [],
  ): string {
    return $this->classificationRegistry->classifyPurchasable(
      $variation,
      $variation_bundle_classifications,
      $product_bundle_classifications,
    );
  }

  /**
   * Resolves event-linked products grouped by canonical classification.
   *
   * Products are classified only from explicit bundle maps supplied by the
   * owning domain. Unmapped products are intentionally left out.
   *
   * @param array<string, string> $product_bundle_classifications
   *   Map of Commerce product bundle id to canonical classification id.
   *
   * @return array<string, list<\Drupal\commerce_product\Entity\ProductInterface>>
   */
  public function resolveClassifiedEventLinkedProducts(NodeInterface $event, array $product_bundle_classifications = [], int $limit = 50): array {
    $groups = $this->emptyProductGroups();
    if ($product_bundle_classifications === []) {
      return $this->normalizeProductGroups($groups);
    }

    foreach ($this->resolveEventLinkedProducts($event, array_keys($product_bundle_classifications), $limit) as $product) {
      $classification = $this->classifyProduct($product, $product_bundle_classifications);
      if ($classification === '') {
        continue;
      }
      $groups[$classification][(int) $product->id()] = $product;
    }

    return $this->normalizeProductGroups($groups);
  }

  /**
   * Resolves grouped event-commerce relationships for operational surfaces.
   *
   * The ticket product referenced by the event is always grouped as a ticket.
   * Other products and purchasables are included only when the owning domain
   * supplies explicit classification maps.
   *
   * @param array<string, string> $product_bundle_classifications
   *   Map of Commerce product bundle id to canonical classification id.
   * @param array<string, string> $variation_bundle_classifications
   *   Map of Commerce variation bundle id to canonical classification id.
   *
   * @return array<string, array{products: list<\Drupal\commerce_product\Entity\ProductInterface>, purchasables: list<\Drupal\commerce_product\Entity\ProductVariationInterface>}>
   */
  public function resolveEventCommerceRelationships(
    NodeInterface $event,
    array $product_bundle_classifications = [],
    array $variation_bundle_classifications = [],
    int $limit = 50,
  ): array {
    $groups = $this->emptyRelationshipGroups();
    $ticket_product = $this->resolveTicketProduct($event);
    $ticket_product_id = $ticket_product instanceof ProductInterface && $ticket_product->id() !== NULL
      ? (int) $ticket_product->id()
      : NULL;

    if ($ticket_product instanceof ProductInterface) {
      $this->addProductToRelationshipGroup($groups, EventCommercePurchasableType::TICKET, $ticket_product);
      if (method_exists($ticket_product, 'getVariations')) {
        foreach ($ticket_product->getVariations() as $variation) {
          if ($variation instanceof ProductVariationInterface) {
            $this->addPurchasableToRelationshipGroup($groups, EventCommercePurchasableType::TICKET, $variation);
          }
        }
      }
    }

    if ($product_bundle_classifications === []) {
      return $this->normalizeRelationshipGroups($groups);
    }

    foreach ($this->resolveEventLinkedProducts($event, array_keys($product_bundle_classifications), $limit) as $product) {
      if ($ticket_product_id !== NULL && (int) $product->id() === $ticket_product_id) {
        continue;
      }

      $product_classification = $this->classifyProduct($product, $product_bundle_classifications);
      if ($product_classification === '') {
        continue;
      }

      $this->addProductToRelationshipGroup($groups, $product_classification, $product);
      if (!method_exists($product, 'getVariations')) {
        continue;
      }

      foreach ($product->getVariations() as $variation) {
        if (!$variation instanceof ProductVariationInterface) {
          continue;
        }

        $variation_classification = $this->classifyPurchasable(
          $variation,
          $variation_bundle_classifications,
          $product_bundle_classifications,
        ) ?: $product_classification;
        $this->addPurchasableToRelationshipGroup($groups, $variation_classification, $variation);
      }
    }

    return $this->normalizeRelationshipGroups($groups);
  }

  /**
   * Resolves an event node from an order item using the canonical MEL order.
   */
  public function resolveEventFromOrderItem(OrderItemInterface $order_item): ?NodeInterface {
    $purchased_entity = $order_item->getPurchasedEntity();
    if ($purchased_entity && $purchased_entity->hasField('field_event') && !$purchased_entity->get('field_event')->isEmpty()) {
      $node = $purchased_entity->get('field_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    $product = $purchased_entity !== NULL && method_exists($purchased_entity, 'getProduct')
      ? $purchased_entity->getProduct()
      : NULL;
    if ($product && $product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
      $node = $product->get('field_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    if ($order_item->hasField('field_target_event') && !$order_item->get('field_target_event')->isEmpty()) {
      $node = $order_item->get('field_target_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    return NULL;
  }

  /**
   * Resolves the unique events represented by a mixed set of order items.
   *
   * @param iterable<\Drupal\commerce_order\Entity\OrderItemInterface> $order_items
   *
   * @return array<int, \Drupal\node\NodeInterface>
   *   Event nodes keyed by event id.
   */
  public function resolveEventsFromOrderItems(iterable $order_items): array {
    $events = [];
    foreach ($order_items as $order_item) {
      if (!$order_item instanceof OrderItemInterface) {
        continue;
      }
      $event = $this->resolveEventFromOrderItem($order_item);
      if ($event instanceof NodeInterface && $event->id() !== NULL) {
        $events[(int) $event->id()] = $event;
      }
    }
    return $events;
  }

  /**
   * Builds empty product groups keyed by canonical classification.
   *
   * @return array<string, array<int, \Drupal\commerce_product\Entity\ProductInterface>>
   */
  private function emptyProductGroups(): array {
    $groups = [];
    foreach (array_keys($this->classificationRegistry->definitions()) as $classification) {
      $groups[$classification] = [];
    }
    return $groups;
  }

  /**
   * Builds empty relationship groups keyed by canonical classification.
   *
   * @return array<string, array{products: array<int, \Drupal\commerce_product\Entity\ProductInterface>, purchasables: array<int, \Drupal\commerce_product\Entity\ProductVariationInterface>}>
   */
  private function emptyRelationshipGroups(): array {
    $groups = [];
    foreach (array_keys($this->classificationRegistry->definitions()) as $classification) {
      $groups[$classification] = [
        'products' => [],
        'purchasables' => [],
      ];
    }
    return $groups;
  }

  /**
   * Adds a product to a relationship group when the classification is known.
   *
   * @param array<string, array{products: array<int, \Drupal\commerce_product\Entity\ProductInterface>, purchasables: array<int, \Drupal\commerce_product\Entity\ProductVariationInterface>}> $groups
   */
  private function addProductToRelationshipGroup(array &$groups, string $classification, ProductInterface $product): void {
    if (!$this->classificationRegistry->isKnown($classification) || $product->id() === NULL) {
      return;
    }
    $groups[$classification]['products'][(int) $product->id()] = $product;
  }

  /**
   * Adds a purchasable to a relationship group when the classification is known.
   *
   * @param array<string, array{products: array<int, \Drupal\commerce_product\Entity\ProductInterface>, purchasables: array<int, \Drupal\commerce_product\Entity\ProductVariationInterface>}> $groups
   */
  private function addPurchasableToRelationshipGroup(array &$groups, string $classification, ProductVariationInterface $variation): void {
    if (!$this->classificationRegistry->isKnown($classification) || $variation->id() === NULL) {
      return;
    }
    $groups[$classification]['purchasables'][(int) $variation->id()] = $variation;
  }

  /**
   * Normalizes product groups to list values.
   *
   * @param array<string, array<int, \Drupal\commerce_product\Entity\ProductInterface>> $groups
   *
   * @return array<string, list<\Drupal\commerce_product\Entity\ProductInterface>>
   */
  private function normalizeProductGroups(array $groups): array {
    foreach ($groups as $classification => $products) {
      $groups[$classification] = array_values($products);
    }
    return $groups;
  }

  /**
   * Normalizes relationship groups to list values.
   *
   * @param array<string, array{products: array<int, \Drupal\commerce_product\Entity\ProductInterface>, purchasables: array<int, \Drupal\commerce_product\Entity\ProductVariationInterface>}> $groups
   *
   * @return array<string, array{products: list<\Drupal\commerce_product\Entity\ProductInterface>, purchasables: list<\Drupal\commerce_product\Entity\ProductVariationInterface>}>
   */
  private function normalizeRelationshipGroups(array $groups): array {
    foreach ($groups as $classification => $group) {
      $groups[$classification] = [
        'products' => array_values($group['products']),
        'purchasables' => array_values($group['purchasables']),
      ];
    }
    return $groups;
  }

}
