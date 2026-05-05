<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityPublishedInterface;
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
    private readonly TicketProductEventOwnershipService $ticketProductEventOwnership,
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

    $ticketTypes = $this->loadAllEventTicketTypes($event);
    if ($this->hasMixedPublishedPaidTicketCurrencies($ticketTypes, $event)) {
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

    $activeVariationUuids = [];

    foreach ($ticketTypes as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->getTicketKind() !== 'paid') {
        continue;
      }
      if ($ticket->isArchived()) {
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

    $orphanOk = $this->removeOrphanedVariations($product, $activeVariationUuids);
    if (!$orphanOk) {
      $this->loggerFactory->get('myeventlane_event')->warning(
        'Ticket sync for event @eid: one or more orphaned variations could not be verified unpublished after save.',
        ['@eid' => (string) $event->id()],
      );
    }

    $this->syncProductTitle($product, $event);

    $this->rebuildProductVariationReferences($event);

    $productIdForNormalize = (int) $product->id();
    $this->entityTypeManager->getStorage('commerce_product')->resetCache([$productIdForNormalize]);
    $productFresh = $this->entityTypeManager->getStorage('commerce_product')->load($productIdForNormalize);
    if ($productFresh instanceof ProductInterface) {
      $this->normalizeProductVariationIds($productFresh);
    }

    Cache::invalidateTags([
      'commerce_product:' . (string) $productIdForNormalize,
      'node:' . (string) $event->id(),
    ]);

    return $orphanOk;
  }

  /**
   * Rebuilds commerce_product.variations from field_ticket_types tier mappings.
   *
   * Safe for CLI repair and post-sync reconciliation: never deletes variation
   * entities; preserves IDs referenced by Commerce order items on this product.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE when rebuild ran or was a no-op; FALSE when paid/both event lacks a
   *   valid ticket product reference.
   */
  public function syncProductVariationReferencesForEvent(NodeInterface $event): bool {
    return $this->rebuildProductVariationReferences($event);
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
   * Loads ticket type entities attached to the event by field or inverse ref.
   *
   * @return \Drupal\mel_ticket\Entity\TicketTypeInterface[]
   *   Ticket entities keyed by id.
   */
  public function loadAllEventTicketTypes(NodeInterface $event): array {
    $out = $this->loadEventTicketTypes($event);
    $event_id = $event->id();
    if ($event_id === NULL || !$this->entityTypeManager->hasDefinition('mel_ticket_type')) {
      return $out;
    }

    $ticket_storage = $this->entityTypeManager->getStorage('mel_ticket_type');
    $inverse_ids = array_values(array_map(
      'intval',
      array_values($ticket_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', (int) $event_id)
        ->execute()),
    ));
    if ($inverse_ids === []) {
      return $out;
    }

    foreach ($ticket_storage->loadMultiple($inverse_ids) as $entity) {
      if ($entity instanceof TicketTypeInterface) {
        $out[(int) $entity->id()] = $entity;
      }
    }

    ksort($out);
    return $out;
  }

  /**
   * Loads non-archived event tickets in buyer-facing display order.
   *
   * The explicit node field order stays authoritative; inverse-only rows are
   * appended so older data still resolves consistently.
   *
   * @return \Drupal\mel_ticket\Entity\TicketTypeInterface[]
   *   Ticket entities keyed by id.
   */
  public function loadEventTicketTypesForDisplay(NodeInterface $event): array {
    $ordered = [];
    foreach ($this->loadEventTicketTypes($event) as $ticket) {
      if (!$ticket->isArchived()) {
        $ordered[(int) $ticket->id()] = $ticket;
      }
    }

    foreach ($this->loadAllEventTicketTypes($event) as $ticket) {
      $ticketId = (int) $ticket->id();
      if (!$ticket->isArchived() && !isset($ordered[$ticketId])) {
        $ordered[$ticketId] = $ticket;
      }
    }

    return $ordered;
  }

  /**
   * Resolves the organiser-recommended default ticket for an event.
   *
   * Falls back to the lowest published paid tier, then the first published tier
   * in event display order.
   */
  public function getDefaultTicket(NodeInterface $event): ?TicketTypeInterface {
    $tickets = array_values(array_filter(
      $this->loadEventTicketTypesForDisplay($event),
      static fn (TicketTypeInterface $ticket): bool => $ticket->isPublished()
    ));
    if ($tickets === []) {
      return NULL;
    }

    foreach ($tickets as $ticket) {
      if ($ticket->hasField('field_is_default_ticket') && $ticket->isDefaultTicket()) {
        return $ticket;
      }
    }

    $lowest = NULL;
    foreach ($tickets as $ticket) {
      if ($ticket->getTicketKind() !== 'paid') {
        continue;
      }
      $price = $ticket->toPriceValue();
      if (!$price instanceof Price) {
        continue;
      }
      if (!$lowest instanceof TicketTypeInterface) {
        $lowest = $ticket;
        continue;
      }
      $lowestPrice = $lowest->toPriceValue();
      if ($lowestPrice instanceof Price && $price->compareTo($lowestPrice) < 0) {
        $lowest = $ticket;
      }
    }

    return $lowest instanceof TicketTypeInterface ? $lowest : $tickets[0];
  }

  /**
   * Returns event tickets with the resolved default first, preserving order after.
   *
   * @return \Drupal\mel_ticket\Entity\TicketTypeInterface[]
   *   Ticket entities keyed by id.
   */
  public function loadEventTicketTypesDefaultFirst(NodeInterface $event): array {
    $tickets = $this->loadEventTicketTypesForDisplay($event);
    $default = NULL;
    foreach ($tickets as $ticket) {
      if ($ticket->hasField('field_is_default_ticket') && $ticket->isDefaultTicket()) {
        $default = $ticket;
        break;
      }
    }
    $default ??= $this->getDefaultTicket($event);
    if (!$default instanceof TicketTypeInterface) {
      return $tickets;
    }

    $defaultId = (int) $default->id();
    if (!isset($tickets[$defaultId])) {
      return $tickets;
    }

    return [$defaultId => $tickets[$defaultId]] + array_diff_key($tickets, [$defaultId => TRUE]);
  }

  /**
   * Returns paid ticket currencies for an event, keyed by ticket id.
   *
   * @return array<int, string>
   *   Uppercase currency code keyed by ticket id.
   */
  public function getPaidTicketCurrenciesByTicketIdForEvent(NodeInterface $event, ?int $excludeTicketId = NULL): array {
    $currencies = [];
    foreach ($this->loadAllEventTicketTypes($event) as $ticket) {
      $ticket_id = (int) $ticket->id();
      if ($excludeTicketId !== NULL && $ticket_id === $excludeTicketId) {
        continue;
      }
      if ($ticket->isArchived()) {
        continue;
      }
      if ($ticket->getTicketKind() !== 'paid') {
        continue;
      }

      $price = $ticket->toPriceValue();
      if ($price instanceof Price) {
        $currencies[$ticket_id] = strtoupper($price->getCurrencyCode());
      }
    }

    return $currencies;
  }

  /**
   * Validates a proposed paid ticket currency against existing event tickets.
   */
  public function paidTicketCurrencyMatchesEvent(NodeInterface $event, string $currencyCode, ?int $excludeTicketId = NULL): bool {
    $new_currency = strtoupper(trim($currencyCode));
    if ($new_currency === '') {
      return TRUE;
    }

    $existing_currencies = array_values(array_unique($this->getPaidTicketCurrenciesByTicketIdForEvent($event, $excludeTicketId)));
    if ($existing_currencies === []) {
      return TRUE;
    }

    foreach ($existing_currencies as $existing_currency) {
      if ($existing_currency !== $new_currency) {
        $this->loggerFactory->get('myeventlane_event')->error(
          'Ticket currency mismatch blocked for event @eid: existing=@existing, attempted=@attempted, excluded_ticket=@excluded.',
          [
            '@eid' => (string) $event->id(),
            '@existing' => implode(', ', $existing_currencies),
            '@attempted' => $new_currency,
            '@excluded' => $excludeTicketId !== NULL ? (string) $excludeTicketId : 'none',
          ]
        );
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Loads published paid ticket prices attached to the event.
   *
   * @return \Drupal\commerce_price\Price[]
   *   Paid ticket prices.
   */
  public function loadPublishedPaidTicketPrices(NodeInterface $event): array {
    $prices = [];
    foreach ($this->loadAllEventTicketTypes($event) as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->getTicketKind() !== 'paid') {
        continue;
      }
      if ($ticket->isArchived()) {
        continue;
      }
      if (!$ticket->isPublished()) {
        continue;
      }

      $price = $ticket->toPriceValue();
      if ($price instanceof Price) {
        $prices[] = $price;
      }
    }

    return $prices;
  }

  /**
   * Returns TRUE when an event has published paid tiers in multiple currencies.
   */
  public function eventHasMixedPublishedPaidTicketCurrencies(NodeInterface $event): bool {
    return $this->hasMixedPublishedPaidTicketCurrencies($this->loadAllEventTicketTypes($event), $event);
  }

  /**
   * Enforces one recommended ticket per event.
   *
   * When a specific ticket was just selected, it wins. Otherwise corrupted
   * multi-default data is corrected deterministically to the highest ticket ID.
   */
  public function normalizeDefaultTicketSelection(NodeInterface $event, ?TicketTypeInterface $selectedTicket = NULL): void {
    $tickets = $this->loadEventTicketTypesForDisplay($event);
    if ($tickets === []) {
      return;
    }

    $keepId = NULL;
    if ($selectedTicket instanceof TicketTypeInterface
      && !$selectedTicket->isArchived()
      && $selectedTicket->hasField('field_is_default_ticket')
      && $selectedTicket->isDefaultTicket()) {
      $selectedId = (int) $selectedTicket->id();
      if (isset($tickets[$selectedId])) {
        $keepId = $selectedId;
      }
    }

    $defaultIds = [];
    foreach ($tickets as $ticketId => $ticket) {
      if ($ticket->hasField('field_is_default_ticket') && $ticket->isDefaultTicket()) {
        $defaultIds[] = (int) $ticketId;
      }
    }

    if ($keepId === NULL && $defaultIds !== []) {
      $keepId = max($defaultIds);
    }

    if ($keepId === NULL || $defaultIds === [$keepId]) {
      return;
    }

    if (count($defaultIds) > 1) {
      $this->loggerFactory->get('myeventlane_event')->warning(
        'Multiple recommended tickets found for event @eid; keeping ticket @keep and clearing @ids.',
        [
          '@eid' => (string) $event->id(),
          '@keep' => (string) $keepId,
          '@ids' => implode(', ', array_map('strval', $defaultIds)),
        ]
      );
    }

    foreach ($tickets as $ticketId => $ticket) {
      if (!$ticket->hasField('field_is_default_ticket') || (int) $ticketId === $keepId) {
        continue;
      }
      if (!$ticket->isDefaultTicket()) {
        continue;
      }
      $ticket->set('field_is_default_ticket', FALSE);
      $ticket->save();
    }
  }

  /**
   * Enforces one best-value ticket per event.
   *
   * When a specific ticket was just selected, it wins. Otherwise corrupted
   * multi-best-value data is corrected deterministically to the first ticket in
   * event display order, matching the public buyer fail-safe.
   */
  public function normalizeBestValueTicketSelection(NodeInterface $event, ?TicketTypeInterface $selectedTicket = NULL): void {
    $tickets = $this->loadEventTicketTypesForDisplay($event);
    if ($tickets === []) {
      return;
    }

    $keepId = NULL;
    if ($selectedTicket instanceof TicketTypeInterface
      && !$selectedTicket->isArchived()
      && $selectedTicket->hasField('field_is_best_value')
      && $selectedTicket->isBestValueTicket()) {
      $selectedId = (int) $selectedTicket->id();
      if (isset($tickets[$selectedId])) {
        $keepId = $selectedId;
      }
    }

    $bestValueIds = [];
    foreach ($tickets as $ticketId => $ticket) {
      if ($ticket->hasField('field_is_best_value') && $ticket->isBestValueTicket()) {
        $bestValueIds[] = (int) $ticketId;
      }
    }

    if ($keepId === NULL && $bestValueIds !== []) {
      $keepId = $bestValueIds[0];
    }

    if ($keepId === NULL || $bestValueIds === [$keepId]) {
      return;
    }

    if (count($bestValueIds) > 1) {
      $this->loggerFactory->get('myeventlane_event')->warning(
        'Multiple best-value tickets found for event @eid; keeping ticket @keep and clearing @ids.',
        [
          '@eid' => (string) $event->id(),
          '@keep' => (string) $keepId,
          '@ids' => implode(', ', array_map('strval', $bestValueIds)),
        ]
      );
    }

    foreach ($tickets as $ticketId => $ticket) {
      if (!$ticket->hasField('field_is_best_value') || (int) $ticketId === $keepId) {
        continue;
      }
      if (!$ticket->isBestValueTicket()) {
        continue;
      }
      $ticket->set('field_is_best_value', FALSE);
      $ticket->save();
    }
  }

  /**
   * Checks loaded ticket types for mixed published paid currencies.
   *
   * @param \Drupal\mel_ticket\Entity\TicketTypeInterface[] $ticketTypes
   *   Ticket type entities.
   */
  private function hasMixedPublishedPaidTicketCurrencies(array $ticketTypes, NodeInterface $event): bool {
    $currencies = [];
    foreach ($ticketTypes as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->getTicketKind() !== 'paid') {
        continue;
      }
      if ($ticket->isArchived()) {
        continue;
      }
      if (!$ticket->isPublished()) {
        continue;
      }

      $price = $ticket->toPriceValue();
      if ($price instanceof Price) {
        $currencyCode = strtoupper($price->getCurrencyCode());
        $currencies[$currencyCode] = $currencyCode;
      }
    }

    if (count($currencies) <= 1) {
      return FALSE;
    }

    $this->loggerFactory->get('myeventlane_event')->error(
      'Mixed paid ticket currencies found for event @eid: @currencies.',
      [
        '@eid' => (string) $event->id(),
        '@currencies' => implode(', ', array_values($currencies)),
      ]
    );
    return TRUE;
  }

  /**
   * Gets or creates the ticket product for an event.
   */
  private function getOrCreateTicketProduct(NodeInterface $event): ?object {
    if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
      $product = $event->get('field_product_target')->entity;
      if ($product instanceof ProductInterface && $product->bundle() === 'ticket') {
        if ($this->ticketProductEventOwnership->ticketProductOwnsEvent($product, $event)) {
          return $product;
        }
        $this->loggerFactory->get('myeventlane_event')->warning(
          'Paid sync: event @eid had field_product_target product @pid not owned by this event; clearing and creating a dedicated product.',
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
    $ticketId = (int) $ticket->id();
    if ($ticketId > 0 && $this->entityTypeManager->hasDefinition('mel_ticket_type')) {
      $ticketStorage = $this->entityTypeManager->getStorage('mel_ticket_type');
      $ticketStorage->resetCache([$ticketId]);
      $freshTicket = $ticketStorage->load($ticketId);
      if ($freshTicket instanceof TicketTypeInterface) {
        $ticket = $freshTicket;
      }
    }

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

      $this->ensureVariationReferencedOnProduct($product, $variation);

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
      // Projection-only mutation allowed. Business logic must go through TicketTierLifecycleService.
      $ticket->save();

      $existing_variations = $product->getVariations();
      $variation_ids = [];
      foreach ($existing_variations as $existing_var) {
        $variation_ids[(int) $existing_var->id()] = (int) $existing_var->id();
      }
      $variation_ids[(int) $variation->id()] = (int) $variation->id();
      $product->set('variations', array_values($variation_ids));
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
   * Ensures a variation ID is present on the parent Commerce product reference field.
   */
  private function ensureVariationReferencedOnProduct(ProductInterface $product, ProductVariationInterface $variation): void {
    if ((int) $variation->getProductId() !== (int) $product->id()) {
      return;
    }
    $pid = (int) $product->id();
    $productStorage = $this->entityTypeManager->getStorage('commerce_product');
    $productStorage->resetCache([$pid]);
    $freshProduct = $productStorage->load($pid);
    if (!$freshProduct instanceof ProductInterface || !method_exists($freshProduct, 'getVariationIds')) {
      return;
    }
    $ids = $freshProduct->getVariationIds();
    $intIds = is_array($ids) ? array_values(array_unique(array_map(static fn ($id): int => (int) $id, $ids))) : [];
    $vid = (int) $variation->id();
    if (in_array($vid, $intIds, TRUE)) {
      return;
    }
    $intIds[] = $vid;
    $freshProduct->set('variations', $intIds);
    $freshProduct->save();
  }

  /**
   * Rebuilds product.variations from field_ticket_types + order-item preservation rules.
   *
   * @return bool
   *   TRUE on success or no-op; FALSE when event type or ticket product invalid.
   */
  private function rebuildProductVariationReferences(NodeInterface $event): bool {
    if ($event->bundle() !== 'event') {
      return FALSE;
    }

    $eventType = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';
    if (!in_array($eventType, ['paid', 'both'], TRUE)) {
      return TRUE;
    }

    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return FALSE;
    }

    $targetId = (int) $event->get('field_product_target')->target_id;
    if ($targetId < 1) {
      return FALSE;
    }

    $productStorage = $this->entityTypeManager->getStorage('commerce_product');
    $productStorage->resetCache([$targetId]);
    $product = $productStorage->load($targetId);
    if (!$product instanceof ProductInterface || $product->bundle() !== 'ticket') {
      return FALSE;
    }

    $productId = (int) $product->id();
    $required = [];

    foreach ($this->loadEventTicketTypes($event) as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->isArchived() || !$ticket->isPublished()) {
        continue;
      }
      if ($ticket->get('commerce_variation')->isEmpty()) {
        continue;
      }
      $mapped = $ticket->get('commerce_variation')->entity;
      if (!$mapped instanceof ProductVariationInterface || $mapped->bundle() !== 'ticket_variation') {
        continue;
      }
      if ((int) $mapped->getProductId() !== $productId) {
        continue;
      }
      $required[(int) $mapped->id()] = (int) $mapped->id();
    }

    $productStorage->resetCache([$productId]);
    $freshProduct = $productStorage->load($productId);
    if (!$freshProduct instanceof ProductInterface || !method_exists($freshProduct, 'getVariationIds')) {
      return TRUE;
    }

    $rawIds = $freshProduct->getVariationIds();
    $currentIds = is_array($rawIds)
      ? array_values(array_unique(array_map(static fn ($id): int => (int) $id, $rawIds)))
      : [];

    $preserve = [];
    $varStorage = $this->entityTypeManager->getStorage('commerce_product_variation');
    foreach ($currentIds as $vid) {
      if ($vid < 1) {
        continue;
      }
      $varStorage->resetCache([$vid]);
      $existing = $varStorage->load($vid);
      if (!$existing instanceof ProductVariationInterface) {
        continue;
      }
      if ((int) $existing->getProductId() !== $productId) {
        continue;
      }
      if ($this->variationHasAnyCommerceOrderItems($vid)) {
        $preserve[$vid] = $vid;
      }
    }

    $final = array_values(array_unique(array_merge(array_values($required), array_values($preserve))));
    sort($final, SORT_NUMERIC);

    $beforeSort = $currentIds;
    sort($beforeSort, SORT_NUMERIC);
    if ($final === $beforeSort) {
      return TRUE;
    }

    $freshProduct->set('variations', $final);
    $freshProduct->save();

    foreach ($required as $reqVid) {
      if (!in_array($reqVid, $final, TRUE)) {
        $this->loggerFactory->get('myeventlane_event')->error(
          'Product variation rebuild for event @eid: required variation @vid missing from commerce_product @pid reference list after save.',
          [
            '@eid' => (string) $event->id(),
            '@vid' => (string) $reqVid,
            '@pid' => (string) $productId,
          ],
        );
      }
    }

    $this->loggerFactory->get('myeventlane_event')->notice(
      'Rebuilt commerce_product @pid variation references for event @eid: @refs.',
      [
        '@pid' => (string) $productId,
        '@eid' => (string) $event->id(),
        '@refs' => (string) json_encode($final),
      ],
    );

    Cache::invalidateTags([
      'commerce_product:' . (string) $productId,
      'node:' . (string) $event->id(),
    ]);

    return TRUE;
  }

  /**
   * TRUE when any commerce order item references this variation (any order state).
   */
  private function variationHasAnyCommerceOrderItems(int $variationId): bool {
    $itemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $found = $itemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('purchased_entity', $variationId)
      ->range(0, 1)
      ->execute();
    return !empty($found);
  }

  /**
   * Removes duplicate variation target IDs on the Commerce product (fixes booking UI drift).
   */
  private function normalizeProductVariationIds(ProductInterface $product): void {
    $raw = $product->getVariationIds();
    if (!is_array($raw) || $raw === []) {
      return;
    }
    $unique = array_values(array_unique(array_map(static fn ($id) => (int) $id, $raw)));
    if (count($raw) === count($unique)) {
      return;
    }
    $product->set('variations', $unique);
    $product->save();
    $this->loggerFactory->get('myeventlane_event')->notice(
      'Deduped Commerce variation references on product @pid (was @before, now @after).',
      [
        '@pid' => (string) $product->id(),
        '@before' => (string) json_encode($raw),
        '@after' => (string) json_encode($unique),
      ]
    );
  }

  /**
   * Unpublishes variations that are no longer tied to paid tickets on this event.
   *
   * @param array<string> $activeVariationUuids
   *   UUIDs that should remain published for this product.
   *
   * @return bool
   *   FALSE if any orphan was still published after save + storage reload.
   */
  private function removeOrphanedVariations(object $product, array $activeVariationUuids): bool {
    if (!$product instanceof ProductInterface || !method_exists($product, 'getVariationIds')) {
      return TRUE;
    }

    $pid = (int) $product->id();
    $productStorage = $this->entityTypeManager->getStorage('commerce_product');
    $productStorage->resetCache([$pid]);
    $freshProduct = $productStorage->load($pid);
    if (!$freshProduct instanceof ProductInterface) {
      return TRUE;
    }

    $ids = $freshProduct->getVariationIds();
    if (!is_array($ids) || $ids === []) {
      return TRUE;
    }

    $varStorage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $allSucceeded = TRUE;

    foreach ($ids as $rawId) {
      $vid = (int) $rawId;
      if ($vid < 1) {
        continue;
      }

      $varStorage->resetCache([$vid]);
      $variation = $varStorage->load($vid);
      if (!$variation instanceof ProductVariationInterface) {
        continue;
      }

      if (in_array($variation->uuid(), $activeVariationUuids, TRUE)) {
        continue;
      }

      if (!$variation->isPublished()) {
        continue;
      }

      if ($variation instanceof EntityPublishedInterface) {
        $variation->setUnpublished();
      }
      elseif ($variation->hasField('status')) {
        $variation->set('status', FALSE);
      }
      $variation->save();

      $varStorage->resetCache([$vid]);
      $reloaded = $varStorage->load($vid);

      if (!$reloaded instanceof ProductVariationInterface || $reloaded->isPublished()) {
        $allSucceeded = FALSE;
        $this->loggerFactory->get('myeventlane_event')->warning(
          'Orphan variation @vid remained published after unpublish attempt; manual review required.',
          ['@vid' => (string) $vid],
        );
      }
      else {
        $this->loggerFactory->get('myeventlane_event')->notice(
          'Unpublished orphaned variation @vid',
          ['@vid' => (string) $vid],
        );
      }
    }

    return $allSucceeded;
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
