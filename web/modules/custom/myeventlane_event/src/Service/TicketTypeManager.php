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
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\node\NodeInterface;

/**
 * Manages ticket type entities and syncs paid types to Commerce variations.
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
   * Syncs paid ticket types on the event to Commerce product variations.
   *
   * RSVP and external ticket kinds are ignored for Commerce.
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

    if (!in_array($eventType, ['paid', 'both'], TRUE)) {
      return FALSE;
    }

    $product = $this->getOrCreateTicketProduct($event);
    if (!$product) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'Failed to get or create product for event @eid',
        ['@eid' => $event->id()]
      );
      return FALSE;
    }

    $ticketTypes = $this->loadEventTicketTypes($event);
    $activeVariationUuids = [];

    foreach ($ticketTypes as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->getTicketKind() !== 'paid') {
        continue;
      }
      if (!$ticket->isPublished()) {
        continue;
      }
      $variation = $this->syncTicketTypeToVariation($ticket, $product, $event);
      if ($variation) {
        $activeVariationUuids[] = $variation->uuid();
      }
    }

    $this->removeOrphanedVariations($product, $activeVariationUuids);
    $this->syncProductTitle($product, $event);

    return TRUE;
  }

  /**
   * Loads ticket type entities attached to the event.
   *
   * @return \Drupal\mel_ticket\Entity\TicketTypeInterface[]
   *   Ticket entities keyed by id.
   */
  public function loadEventTicketTypes(NodeInterface $event): array {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return [];
    }
    $out = [];
    foreach ($event->get('field_ticket_types')->referencedEntities() as $entity) {
      if ($entity instanceof TicketTypeInterface) {
        $out[(int) $entity->id()] = $entity;
      }
    }
    return $out;
  }

  /**
   * Gets or creates the ticket product for an event.
   */
  private function getOrCreateTicketProduct(NodeInterface $event): ?object {
    if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
      $product = $event->get('field_product_target')->entity;
      if ($product && $product->bundle() === 'ticket') {
        return $product;
      }
    }

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

    $event->set('field_product_target', ['target_id' => $product->id()]);
    EventNodeRevisionSave::prepare($event, 'Linked ticket product to event.');
    $event->save();

    $this->loggerFactory->get('myeventlane_event')->notice(
      'Created ticket product @pid for event @eid',
      ['@pid' => $product->id(), '@eid' => $event->id()]
    );

    return $product;
  }

  /**
   * Syncs one paid ticket type to a product variation.
   */
  private function syncTicketTypeToVariation(TicketTypeInterface $ticket, object $product, NodeInterface $event): ?object {
    $label = $ticket->getTitle();
    if ($label === '') {
      $this->loggerFactory->get('myeventlane_event')->warning(
        'Ticket type @id has no title, skipping',
        ['@id' => $ticket->id()]
      );
      return NULL;
    }

    $price_obj = $ticket->toPriceValue();
    if (!$price_obj) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'Paid ticket @id has no price; cannot sync to Commerce.',
        ['@id' => $ticket->id()]
      );
      return NULL;
    }

    $price = new Price($price_obj->getNumber(), $price_obj->getCurrencyCode());
    $variationTitle = $label;

    $variation = NULL;
    if (!$ticket->get('commerce_variation')->isEmpty()) {
      $variation = $ticket->get('commerce_variation')->entity;
      if ($variation && (int) $variation->getProductId() !== (int) $product->id()) {
        $variation = NULL;
      }
    }

    if ($variation) {
      $variation->setTitle($variationTitle);
      $variation->setPrice($price);
      if ($variation->hasField('field_event')) {
        $variation->set('field_event', ['target_id' => $event->id()]);
      }
      $variation->save();

      $this->loggerFactory->get('myeventlane_event')->notice(
        'Updated variation @vid for ticket type "@label"',
        ['@vid' => $variation->id(), '@label' => $label]
      );
    }
    else {
      $sku = $this->generateSku($event, $label);

      $variation = ProductVariation::create([
        'type' => 'ticket_variation',
        'sku' => $sku,
        'title' => $variationTitle,
        'price' => $price,
        'status' => 1,
        'product_id' => $product->id(),
      ]);

      if ($variation->hasField('field_event')) {
        $variation->set('field_event', ['target_id' => $event->id()]);
      }

      $variation->save();

      $ticket->set('commerce_variation', ['target_id' => $variation->id()]);
      $ticket->save();

      $existing_variations = $product->getVariations();
      $variation_ids = [];
      foreach ($existing_variations as $existing_var) {
        $variation_ids[] = $existing_var->id();
      }
      $variation_ids[] = $variation->id();
      $product->set('variations', $variation_ids);
      $product->save();

      $this->loggerFactory->get('myeventlane_event')->notice(
        'Created variation @vid for ticket type "@label"',
        ['@vid' => $variation->id(), '@label' => $label]
      );
    }

    return $variation;
  }

  /**
   * Generates a unique SKU for a ticket variation.
   */
  private function generateSku(NodeInterface $event, string $label): string {
    $eventId = $event->id() ?? 'new';
    $labelSlug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $label));
    $labelSlug = trim($labelSlug, '-');
    return 'ticket-' . $eventId . '-' . $labelSlug . '-' . time();
  }

  /**
   * Unpublishes variations that are no longer tied to paid tickets on this event.
   *
   * @param array<string> $activeVariationUuids
   *   UUIDs that should remain published for this product.
   */
  private function removeOrphanedVariations(object $product, array $activeVariationUuids): void {
    $variations = $product->getVariations();
    foreach ($variations as $variation) {
      if (!in_array($variation->uuid(), $activeVariationUuids, TRUE)) {
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
   * Whether the event has an explicit vendor store (no default fallback).
   */
  public function hasVendorStore(NodeInterface $event): bool {
    return $this->resolveVendorStoreOnly($event) !== NULL;
  }

  /**
   * Default currency for ticket pricing for this event's store.
   */
  public function getDefaultCurrencyCodeForEvent(NodeInterface $event): string {
    $store = $this->resolveEventStore($event);
    if ($store instanceof StoreInterface) {
      return $store->getDefaultCurrencyCode();
    }
    return 'AUD';
  }

  /**
   * Resolves store for product creation (vendor store or default).
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
   * Resolves vendor store only via event fields.
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
