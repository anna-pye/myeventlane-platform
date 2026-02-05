<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Manages ticket type configurations and syncs them to Commerce Variations.
 *
 * This service handles the creation and synchronization of Commerce Product
 * Variations based on ticket type definitions stored as Paragraphs on Event nodes.
 */
final class TicketTypeManager {

  use StringTranslationTrait;

  /**
   * Constructs TicketTypeManager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Syncs ticket type paragraphs to Commerce Product Variations.
   *
   * For each ticket type paragraph on the event:
   * - Creates or updates the corresponding Product Variation
   * - Links the paragraph to the variation via UUID
   * - Removes variations that no longer have corresponding paragraphs.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if sync was successful, FALSE otherwise.
   */
  public function syncTicketTypesToVariations(NodeInterface $event): bool {
    if ($event->bundle() !== 'event') {
      return FALSE;
    }

    $eventType = $event->get('field_event_type')->value ?? '';

    // Only sync for paid and both event types.
    if (!in_array($eventType, ['paid', 'both'], TRUE)) {
      return FALSE;
    }

    // Get or create the product for this event.
    $product = $this->getOrCreateTicketProduct($event);
    if (!$product) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'Failed to get or create product for event @eid',
        ['@eid' => $event->id()]
      );
      return FALSE;
    }

    // Load all ticket type paragraphs.
    $ticketTypes = [];
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      foreach ($event->get('field_ticket_types')->referencedEntities() as $paragraph) {
        if ($paragraph instanceof ParagraphInterface && $paragraph->bundle() === 'ticket_type_config') {
          $ticketTypes[] = $paragraph;
        }
      }
    }

    // Track which variation UUIDs are still in use.
    $activeVariationUuids = [];

    // Sync each ticket type to a variation.
    foreach ($ticketTypes as $paragraph) {
      $variation = $this->syncTicketTypeToVariation($paragraph, $product, $event);
      if ($variation) {
        $activeVariationUuids[] = $variation->uuid();

        // Store the variation UUID in the paragraph for future lookups.
        if ($paragraph->hasField('field_ticket_variation_uuid')) {
          $paragraph->set('field_ticket_variation_uuid', $variation->uuid());
          $paragraph->save();
        }
      }
    }

    // Remove variations that no longer have corresponding paragraphs.
    $this->removeOrphanedVariations($product, $activeVariationUuids);

    // Update product title if event title changed.
    $this->syncProductTitle($product, $event);

    return TRUE;
  }

  /**
   * Gets or creates the ticket product for an event.
   *
   * Uses the event's vendor store so orders are placed on the correct store.
   * Falls back to default store only when the event has no store assigned.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface|null
   *   The product entity, or NULL on failure.
   */
  private function getOrCreateTicketProduct(NodeInterface $event): ?object {
    // Check if product is already linked.
    if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
      $product = $event->get('field_product_target')->entity;
      if ($product && $product->bundle() === 'ticket') {
        return $product;
      }
    }

    // Resolve store: event's vendor store first, then default.
    $store = $this->resolveEventStore($event);

    if (!$store) {
      $this->loggerFactory->get('myeventlane_event')->error('No Commerce store available for ticket product creation.');
      return NULL;
    }

    $product = Product::create([
      'type' => 'ticket',
      'title' => $event->label(),
      'stores' => [$store->id()],
      'status' => 1,
      'field_event' => ['target_id' => $event->id()],
      'uid' => $event->getOwnerId(),
    ]);
    $product->save();

    // Link the product to the event.
    $event->set('field_product_target', ['target_id' => $product->id()]);
    $event->save();

    $this->loggerFactory->get('myeventlane_event')->notice(
      'Created ticket product @pid for event @eid',
      ['@pid' => $product->id(), '@eid' => $event->id()]
    );

    return $product;
  }

  /**
   * Syncs a single ticket type paragraph to a Commerce Variation.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The ticket type paragraph.
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product entity.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface|null
   *   The variation entity, or NULL on failure.
   */
  private function syncTicketTypeToVariation(ParagraphInterface $paragraph, object $product, NodeInterface $event): ?object {
    // Get the ticket label.
    $label = $this->getTicketLabel($paragraph);
    if (empty($label)) {
      $this->loggerFactory->get('myeventlane_event')->warning(
        'Ticket type paragraph @pid has no label, skipping',
        ['@pid' => $paragraph->id()]
      );
      return NULL;
    }

    // Get price.
    $priceValue = $paragraph->get('field_ticket_price')->value ?? '0.00';
    // Get currency from the product's store, default to AUD if not available.
    $stores = $product->getStores();
    // Default to AUD for Australian site.
    $currency = 'AUD';
    if (!empty($stores)) {
      $store = reset($stores);
      $currency = $store->getDefaultCurrencyCode();
    }
    $price = new Price($priceValue, $currency);

    // Variation title MUST be the ticket type label only.
    // (Do not prefix with event/product title.)
    $variationTitle = $label;

    // Check if variation already exists via UUID mapping.
    $variation = NULL;
    if ($paragraph->hasField('field_ticket_variation_uuid') && !$paragraph->get('field_ticket_variation_uuid')->isEmpty()) {
      $uuid = $paragraph->get('field_ticket_variation_uuid')->value;
      $variationStorage = $this->entityTypeManager->getStorage('commerce_product_variation');
      $variations = $variationStorage->loadByProperties(['uuid' => $uuid]);
      if (!empty($variations)) {
        $variation = reset($variations);
        // Verify it belongs to this product.
        if ($variation->getProductId() != $product->id()) {
          $variation = NULL;
        }
      }
    }

    // Create or update variation.
    if ($variation) {
      // Update existing variation.
      $variation->setTitle($variationTitle);
      $variation->setPrice($price);

      // Update capacity if field exists (using stock or custom field).
      // For now, we'll store capacity in a note or custom field if needed.
      $variation->save();

      $this->loggerFactory->get('myeventlane_event')->notice(
        'Updated variation @vid for ticket type "@label"',
        ['@vid' => $variation->id(), '@label' => $label]
      );
    }
    else {
      // Create new variation.
      $sku = $this->generateSku($event, $label);

      $variation = ProductVariation::create([
        'type' => 'ticket_variation',
        'sku' => $sku,
        'title' => $variationTitle,
        'price' => $price,
        'status' => 1,
        'product_id' => $product->id(),
        'field_event' => ['target_id' => $event->id()],
      ]);
      $variation->save();

      // Add variation to product's variations collection.
      // Get existing variations and add the new one.
      $existing_variations = $product->getVariations();
      $variation_ids = [];
      foreach ($existing_variations as $existing_var) {
        $variation_ids[] = $existing_var->id();
      }
      $variation_ids[] = $variation->id();
      $product->set('variations', $variation_ids);
      $product->save();

      // Store UUID in paragraph.
      if ($paragraph->hasField('field_ticket_variation_uuid')) {
        $paragraph->set('field_ticket_variation_uuid', $variation->uuid());
        $paragraph->save();
      }

      $this->loggerFactory->get('myeventlane_event')->notice(
        'Created variation @vid for ticket type "@label"',
        ['@vid' => $variation->id(), '@label' => $label]
      );
    }

    return $variation;
  }

  /**
   * Gets the ticket label from a paragraph.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The ticket type paragraph.
   *
   * @return string
   *   The ticket label.
   */
  private function getTicketLabel(ParagraphInterface $paragraph): string {
    $labelMode = $paragraph->get('field_ticket_label_mode')->value ?? 'preset';

    if ($labelMode === 'custom') {
      $customLabel = $paragraph->get('field_ticket_label_custom')->value ?? '';
      if (!empty($customLabel)) {
        return $customLabel;
      }
    }

    // Use preset label.
    $presetValue = $paragraph->get('field_ticket_label_preset')->value ?? '';
    if (!empty($presetValue)) {
      $presetLabels = [
        'full_price' => 'Full Price',
        'concession' => 'Concession',
        'child' => 'Child',
        'member' => 'Member',
        'free' => 'Free',
        'student' => 'Student',
        'senior' => 'Senior',
        'early_bird' => 'Early Bird',
        'vip' => 'VIP',
      ];
      return $presetLabels[$presetValue] ?? ucfirst(str_replace('_', ' ', $presetValue));
    }

    return 'Ticket';
  }

  /**
   * Generates a unique SKU for a ticket variation.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $label
   *   The ticket label.
   *
   * @return string
   *   The SKU.
   */
  private function generateSku(NodeInterface $event, string $label): string {
    $eventId = $event->id() ?? 'new';
    $labelSlug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $label));
    $labelSlug = trim($labelSlug, '-');
    return 'ticket-' . $eventId . '-' . $labelSlug . '-' . time();
  }

  /**
   * Removes variations that no longer have corresponding paragraphs.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product entity.
   * @param array $activeVariationUuids
   *   Array of UUIDs that should be kept.
   */
  private function removeOrphanedVariations(object $product, array $activeVariationUuids): void {
    $variations = $product->getVariations();
    foreach ($variations as $variation) {
      if (!in_array($variation->uuid(), $activeVariationUuids, TRUE)) {
        // Only remove if it's not the default variation and has no orders.
        // For safety, we'll just unpublish it rather than delete.
        $variation->setPublished(FALSE);
        $variation->save();

        $this->loggerFactory->get('myeventlane_event')->notice(
          'Unpublished orphaned variation @vid',
          ['@vid' => $variation->id()]
        );
      }
    }
  }

  /**
   * Syncs product title to match event title.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product entity.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   */
  private function syncProductTitle(object $product, NodeInterface $event): void {
    $eventTitle = $event->label();
    if ($product->label() !== $eventTitle) {
      $product->setTitle($eventTitle);
      $product->save();

      $this->loggerFactory->get('myeventlane_event')->notice(
        'Updated product title to match event title "@title"',
        ['@title' => $eventTitle]
      );
    }
  }

  /**
   * Checks if the event has a resolvable vendor store (no default fallback).
   *
   * Returns TRUE only when field_event_store or field_event_vendor →
   * field_vendor_store is set. Used for validation before ticket creation.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if the event has an explicit vendor store.
   */
  public function hasVendorStore(NodeInterface $event): bool {
    return $this->resolveVendorStoreOnly($event) !== NULL;
  }

  /**
   * Resolves the store for an event (vendor store or default).
   *
   * Uses field_event_store first; if empty, field_event_vendor → field_vendor_store.
   * Falls back to default Commerce store when the event has no store assigned.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface|null
   *   The store entity, or NULL if none available.
   */
  private function resolveEventStore(NodeInterface $event): ?StoreInterface {
    $vendorStore = $this->resolveVendorStoreOnly($event);
    if ($vendorStore) {
      return $vendorStore;
    }

    $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
    $stores = $storeStorage->loadByProperties(['is_default' => TRUE]);
    $store = reset($stores);
    if ($store instanceof StoreInterface) {
      return $store;
    }

    $stores = $storeStorage->loadMultiple();
    return reset($stores) ?: NULL;
  }

  /**
   * Resolves the vendor store only (no default fallback).
   *
   * Returns the store from field_event_store or field_event_vendor →
   * field_vendor_store. Returns NULL if neither is set.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface|null
   *   The vendor store, or NULL if not assigned.
   */
  private function resolveVendorStoreOnly(NodeInterface $event): ?StoreInterface {
    if ($event->hasField('field_event_store') && !$event->get('field_event_store')->isEmpty()) {
      $store = $event->get('field_event_store')->entity;
      if ($store instanceof StoreInterface) {
        return $store;
      }
    }

    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
        $store = $vendor->get('field_vendor_store')->entity;
        if ($store instanceof StoreInterface) {
          return $store;
        }
      }
    }

    return NULL;
  }

}
