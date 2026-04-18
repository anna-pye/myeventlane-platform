<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\node\NodeInterface;

/**
 * Manages Commerce product creation and sync for MyEventLane events.
 *
 * This service provides intent-driven product synchronization:
 * - syncProducts() MUST be called with explicit intent ('publish' or 'sync').
 * - Product sync MUST NOT run during AJAX, wizard navigation, or draft saves.
 * - All operations are guarded against concurrent execution and invalid states.
 */
final class EventProductManager {

  use StringTranslationTrait;

  /**
   * Constructs EventProductManager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly MessengerInterface $messenger,
    private readonly LockBackendInterface $lock,
    private readonly TicketTypeManager $ticketTypeManager,
  ) {}

  /**
   * Ensures a linked ticket product's field_event matches this event (wizard publish guard).
   *
   * @return string|null
   *   A translated error message when invalid; NULL when OK or no product linked.
   */
  public function validateTicketProductEventIntegrity(NodeInterface $event): ?string {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return NULL;
    }
    $product = $event->get('field_product_target')->entity;
    if (!$product instanceof ProductInterface) {
      return (string) $this->t('The linked ticket product could not be loaded.');
    }
    if (!$product->hasField('field_event')) {
      return NULL;
    }
    if ($product->get('field_event')->isEmpty()) {
      return (string) $this->t('The ticket product is not linked to this event. Save the event or run a ticket sync, then try again.');
    }
    if ((int) $product->get('field_event')->target_id !== (int) $event->id()) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'Ticket product integrity failed: event @eid points to product @pid with field_event=@other.',
        [
          '@eid' => (string) $event->id(),
          '@pid' => (string) $product->id(),
          '@other' => (string) ($product->get('field_event')->target_id ?? '0'),
        ]
      );
      return (string) $this->t('The ticket product does not belong to this event. Remove the product link or contact support.');
    }
    return NULL;
  }

  /**
   * Syncs products for an event based on explicit intent.
   *
   * Allowed intents:
   * - 'publish': Event is being published (full sync).
   * - 'sync': Explicit manual sync request.
   *
   * This method MUST NOT be called:
   * - During AJAX requests.
   * - During wizard navigation.
   * - During draft saves.
   * - For unpublished events (unless intent is 'sync').
   * - For new (unsaved) nodes.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $intent
   *   The sync intent: 'publish' or 'sync'.
   *
   * @return bool
   *   TRUE if sync completed successfully, FALSE otherwise.
   */
  public function syncProducts(NodeInterface $event, string $intent): bool {
    // Guard: Only allow 'publish' or 'sync' intents.
    if (!in_array($intent, ['publish', 'sync'], TRUE)) {
      $this->loggerFactory->get('myeventlane_event')->warning(
        'Invalid sync intent "@intent" for event @eid. Allowed: publish, sync.',
        ['@intent' => $intent, '@eid' => $event->id() ?? 'new']
      );
      return FALSE;
    }

    // Guard: Must not be a new (unsaved) node.
    if ($event->isNew()) {
      $this->loggerFactory->get('myeventlane_event')->warning(
        'Cannot sync products for new (unsaved) event. Event must be saved first.'
      );
      return FALSE;
    }

    // Guard: For 'publish' intent, event must be published.
    if ($intent === 'publish' && !$event->isPublished()) {
      $this->loggerFactory->get('myeventlane_event')->warning(
        'Cannot sync products with "publish" intent for unpublished event @eid.',
        ['@eid' => $event->id()]
      );
      return FALSE;
    }

    // Guard: Prevent concurrent syncs using lock service.
    $lock_name = 'myeventlane_event:sync_products:' . $event->id();
    if (!$this->lock->acquire($lock_name, 30)) {
      $this->loggerFactory->get('myeventlane_event')->warning(
        'Product sync already in progress for event @eid. Skipping duplicate request.',
        ['@eid' => $event->id()]
      );
      return FALSE;
    }

    try {
      $result = $this->doSyncProducts($event, $intent);
      return $result;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Performs the actual product sync operation.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $intent
   *   The sync intent.
   *
   * @return bool
   *   TRUE if sync completed successfully.
   */
  private function doSyncProducts(NodeInterface $event, string $intent): bool {
    if ($event->bundle() !== 'event') {
      return FALSE;
    }

    $eventType = $event->get('field_event_type')->value ?? '';

    // RSVP events: ensure product exists and is linked.
    if ($eventType === 'rsvp') {
      return $this->syncRsvpProduct($event);
    }

    // Paid and hybrid events: mel_ticket_type is source of truth; mirror to Commerce.
    if (in_array($eventType, ['paid', 'both'], TRUE)) {
      $variation_sync = $this->ticketTypeManager->syncTicketTypesToVariations($event);
      if (!$variation_sync) {
        $this->loggerFactory->get('myeventlane_event')->notice(
          'Ticket variation sync returned FALSE for event @eid (verify field_event_type is paid/both and Commerce store exists).',
          ['@eid' => (string) $event->id()]
        );
      }

      $fresh = $this->entityTypeManager->getStorage('node')->load($event->id());
      if ($fresh instanceof NodeInterface) {
        $event = $fresh;
      }

      $has_ticket_types = $event->hasField('field_ticket_types')
        && !$event->get('field_ticket_types')->isEmpty();

      if ($event->get('field_product_target')->isEmpty() && !$has_ticket_types) {
        $this->messenger->addWarning(
          $this->t('Please add at least one ticket tier for this @type event, or link a ticket product.', [
            '@type' => $eventType === 'paid' ? 'paid' : 'hybrid',
          ])
        );
      }
    }

    return TRUE;
  }

  /**
   * Syncs RSVP product for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if sync completed successfully.
   */
  private function syncRsvpProduct(NodeInterface $event): bool {
    if (!$event->get('field_product_target')->isEmpty()) {
      $product = $event->get('field_product_target')->entity;
      if ($product instanceof ProductInterface && $product->isPublished()) {
        if ($this->ticketProductOwnsEvent($product, $event)) {
          $this->ensureProductFieldEventMatches($product, $event);
          return TRUE;
        }
        $this->loggerFactory->get('myeventlane_event')->warning(
          'RSVP event @eid referenced product @pid not owned by this event (field_event mismatch or missing). Clearing link; creating a dedicated product.',
          [
            '@eid' => (string) $event->id(),
            '@pid' => (string) $product->id(),
          ]
        );
        $event->set('field_product_target', []);
        EventNodeRevisionSave::prepare($event, 'Removed ticket product not owned by this event.');
        $event->save();
      }
    }

    // Create new RSVP product if it doesn't exist.
    $product = $this->createRsvpProduct($event);
    if (!$product) {
      return FALSE;
    }

    // Link event to product.
    $event->set('field_product_target', ['target_id' => $product->id()]);
    EventNodeRevisionSave::prepare($event, 'Linked RSVP product to event.');
    $event->save();

    $this->loggerFactory->get('myeventlane_event')->notice(
      'Created and linked RSVP product @pid for event @eid',
      ['@pid' => $product->id(), '@eid' => $event->id()]
    );

    return TRUE;
  }

  /**
   * TRUE when this product is the ticket product for the given event (field_event match or self-heal empty).
   */
  private function ticketProductOwnsEvent(ProductInterface $product, NodeInterface $event): bool {
    if (!$product->hasField('field_event')) {
      return FALSE;
    }
    if ($product->get('field_event')->isEmpty()) {
      $product->set('field_event', ['target_id' => $event->id()]);
      $product->save();
      $this->loggerFactory->get('myeventlane_event')->notice(
        'Set field_event on ticket product @pid to event @eid (was empty).',
        ['@pid' => (string) $product->id(), '@eid' => (string) $event->id()]
      );
      return TRUE;
    }
    return (int) $product->get('field_event')->target_id === (int) $event->id();
  }

  /**
   * Sets field_event when empty (never reassigns another event's product — mismatch is handled elsewhere).
   */
  private function ensureProductFieldEventMatches(ProductInterface $product, NodeInterface $event): void {
    if (!$product->hasField('field_event') || !$product->get('field_event')->isEmpty()) {
      return;
    }
    $product->set('field_event', ['target_id' => $event->id()]);
    $product->save();
  }

  /**
   * Creates a new RSVP product for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface|null
   *   The created product, or NULL on failure.
   */
  private function createRsvpProduct(NodeInterface $event): ?object {
    $store = $this->resolveEventStore($event);

    if (!$store) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'No Commerce store available for RSVP product creation.'
      );
      return NULL;
    }

    try {
      // Create variation first (required for product).
      $variation = ProductVariation::create([
        'type' => 'ticket_variation',
        'sku' => 'rsvp-' . $event->id() . '-' . time(),
        'title' => 'Free RSVP',
        'price' => new Price('0', 'USD'),
        'status' => 1,
        'field_event' => ['target_id' => $event->id()],
      ]);
      $variation->save();

      // Create product.
      $product = Product::create([
        'type' => 'ticket',
        'title' => $event->label() . ' - RSVP',
        'stores' => [$store->id()],
        'variations' => [$variation],
        'status' => 1,
        'field_event' => ['target_id' => $event->id()],
        'uid' => $event->getOwnerId(),
      ]);
      $product->save();
      $this->ensureProductFieldEventMatches($product, $event);

      $this->loggerFactory->get('myeventlane_event')->notice(
        'Created RSVP product @pid for event @eid (@title)',
        [
          '@pid' => $product->id(),
          '@eid' => $event->id(),
          '@title' => $event->label(),
        ]
      );

      return $product;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'Failed to create RSVP product: @message',
        ['@message' => $e->getMessage()]
      );
      return NULL;
    }
  }

  /**
   * Calculates product definitions for an event (pure function, no side effects).
   *
   * This method can be called safely to determine what products should exist
   * without actually creating them.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array<string, mixed>
   *   Array of product definitions, keyed by product type.
   */
  public function calculateProductDefinitions(NodeInterface $event): array {
    $definitions = [];

    if ($event->bundle() !== 'event') {
      return $definitions;
    }

    $eventType = $event->get('field_event_type')->value ?? '';

    if ($eventType === 'rsvp') {
      $definitions['rsvp'] = [
        'type' => 'ticket',
        'variation_type' => 'ticket_variation',
        'price' => new Price('0', 'USD'),
        'title' => $event->label() . ' - RSVP',
      ];
    }

    return $definitions;
  }

  /**
   * Checks if a product is an auto-generated RSVP product.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product entity.
   *
   * @return bool
   *   TRUE if this is an auto-generated RSVP product.
   */
  public function isAutoGeneratedRsvpProduct(object $product): bool {
    if ($product->bundle() !== 'ticket') {
      return FALSE;
    }

    $variations = $product->getVariations();
    if (count($variations) !== 1) {
      return FALSE;
    }

    $variation = reset($variations);
    $price = $variation->getPrice();

    return $price && $price->getNumber() === '0';
  }

  /**
   * Resolves the store for an event (vendor store or default).
   *
   * Uses field_event_store first; if empty, field_event_vendor → field_vendor_store.
   * Falls back to default Commerce store when the event has no store assigned.
   */
  private function resolveEventStore(NodeInterface $event): ?StoreInterface {
    $storeStorage = $this->entityTypeManager->getStorage('commerce_store');

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

    $stores = $storeStorage->loadByProperties(['is_default' => TRUE]);
    $store = reset($stores);
    if ($store instanceof StoreInterface) {
      return $store;
    }

    $stores = $storeStorage->loadMultiple();
    return reset($stores) ?: NULL;
  }

}
