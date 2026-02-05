<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Commands;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for migrating legacy ticket products to correct stores.
 *
 * Updates ticket/RSVP products so they use the event's vendor store instead
 * of the default store, fixing vendor dashboard order visibility.
 */
final class MigrateLegacyTicketStoresCommands extends DrushCommands {

  /**
   * Constructs the commands.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Migrates legacy ticket products to the correct vendor store.
   *
   * Updates products where the event has a valid store but the product's
   * store does not match. Uses field_event_store or field_event_vendor →
   * field_vendor_store to resolve the event's store.
   */
  #[CLI\Command(name: 'myeventlane:migrate-ticket-stores', aliases: ['mel-migrate-ticket-stores'])]
  #[CLI\Option(name: 'dry', description: 'Simulate changes without saving')]
  #[CLI\Option(name: 'event', description: 'Limit to a specific event node ID (e.g. 754)')]
  #[CLI\Usage(name: 'drush myeventlane:migrate-ticket-stores', description: 'Migrate all eligible ticket products')]
  #[CLI\Usage(name: 'drush myeventlane:migrate-ticket-stores --dry', description: 'Simulate without saving')]
  #[CLI\Usage(name: 'drush myeventlane:migrate-ticket-stores --event=754', description: 'Only migrate products for event 754')]
  public function migrateTicketStores(): void {
    $dryRun = (bool) $this->input()->getOption('dry');
    $eventOpt = $this->input()->getOption('event');
    $eventFilter = $eventOpt !== NULL && $eventOpt !== '' ? (int) $eventOpt : NULL;

    $this->io()->title('Migrate Legacy Ticket Products to Correct Store');

    if ($dryRun) {
      $this->io()->note('DRY RUN — no changes will be saved.');
    }

    $productStorage = $this->entityTypeManager->getStorage('commerce_product');
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $query = $productStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'ticket')
      ->exists('field_event');

    if ($eventFilter !== NULL) {
      $query->condition('field_event', $eventFilter);
    }

    $productIds = $query->execute();
    if (empty($productIds)) {
      $this->io()->success('No ticket products found.');
      return;
    }

    $updated = 0;
    $skipped = 0;
    $skippedReasons = [];

    foreach ($productStorage->loadMultiple($productIds) as $product) {
      if (!$product instanceof ProductInterface) {
        continue;
      }

      $eventId = $product->hasField('field_event') && !$product->get('field_event')->isEmpty()
        ? (int) $product->get('field_event')->target_id
        : NULL;

      if (!$eventId) {
        $skipped++;
        $skippedReasons['no_event'] = ($skippedReasons['no_event'] ?? 0) + 1;
        continue;
      }

      $event = $nodeStorage->load($eventId);
      if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
        $skipped++;
        $skippedReasons['event_not_found'] = ($skippedReasons['event_not_found'] ?? 0) + 1;
        $this->logger()->debug('Product @pid: event @nid not found or not event node.', [
          '@pid' => $product->id(),
          '@nid' => $eventId,
        ]);
        continue;
      }

      $eventStore = $this->resolveEventStore($event);
      if (!$eventStore) {
        $skipped++;
        $skippedReasons['no_event_store'] = ($skippedReasons['no_event_store'] ?? 0) + 1;
        $this->logger()->debug('Product @pid: event @nid has no resolvable store.', [
          '@pid' => $product->id(),
          '@nid' => $eventId,
        ]);
        continue;
      }

      $productStores = $product->getStores();
      $productStoreIds = array_map(
        fn (StoreInterface $s) => (int) $s->id(),
        $productStores
      );

      if (in_array((int) $eventStore->id(), $productStoreIds, TRUE)) {
        $skipped++;
        $skippedReasons['already_correct'] = ($skippedReasons['already_correct'] ?? 0) + 1;
        continue;
      }

      $oldStoreId = !empty($productStoreIds) ? (string) $productStoreIds[0] : 'none';
      $newStoreId = (string) $eventStore->id();

      $this->logger()->info(
        'Product @pid "@title": store @old → @new',
        [
          '@pid' => $product->id(),
          '@title' => $product->label(),
          '@old' => $oldStoreId,
          '@new' => $newStoreId,
        ]
      );

      if (!$dryRun) {
        $product->setStores([$eventStore]);
        $product->save();
      }

      $updated++;
    }

    $this->io()->success(
      $dryRun
        ? sprintf('Dry run complete: %d product(s) would be updated, %d skipped.', $updated, $skipped)
        : sprintf('Migration complete: %d product(s) updated, %d skipped.', $updated, $skipped)
    );

    if (!empty($skippedReasons)) {
      $this->io()->text('Skipped reasons:');
      foreach ($skippedReasons as $reason => $count) {
        $this->io()->text(sprintf('  - %s: %d', $reason, $count));
      }
    }
  }

  /**
   * Resolves the store for an event (vendor store or default).
   *
   * Uses field_event_store first; if empty, field_event_vendor → field_vendor_store.
   * Returns NULL only when the event has no store assigned (does not fall back
   * to default for migration — we want the vendor store).
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface|null
   *   The store entity, or NULL if none assigned.
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

    return NULL;
  }

}
