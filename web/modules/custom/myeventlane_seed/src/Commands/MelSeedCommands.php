<?php

declare(strict_types=1);

namespace Drupal\myeventlane_seed\Commands;

use Drupal\myeventlane_seed\Service\DemoPurger;
use Drupal\myeventlane_seed\Service\DemoSeeder;
use Drupal\myeventlane_seed\Service\EventResetService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for MyEventLane seed data management.
 */
final class MelSeedCommands extends DrushCommands {

  /**
   * Constructs MelSeedCommands.
   *
   * @param \Drupal\myeventlane_seed\Service\EventResetService $eventResetService
   *   The event reset service.
   * @param \Drupal\myeventlane_seed\Service\DemoSeeder $demoSeeder
   *   The demo seeder service.
   * @param \Drupal\myeventlane_seed\Service\DemoPurger $demoPurger
   *   The demo purger service.
   */
  public function __construct(
    private readonly EventResetService $eventResetService,
    private readonly DemoSeeder $demoSeeder,
    private readonly DemoPurger $demoPurger,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_seed.event_reset'),
      $container->get('myeventlane_seed.demo_seeder'),
      $container->get('myeventlane_seed.demo_purger')
    );
  }

  /**
   * Deletes ALL Event nodes (bundle 'event').
   */
  #[CLI\Command(name: 'mel:reset-events', aliases: ['mel-reset-events'])]
  #[CLI\Usage(name: 'drush mel:reset-events', description: 'Deletes all event nodes and logs counts per bundle.')]
  public function resetEvents(): void {
    $this->io()->title('Resetting Events');
    $this->io()->note('This will delete ALL event nodes. Use with caution.');

    $stats = $this->eventResetService->deleteAllEvents();

    $this->io()->success(sprintf(
      'Deleted %d event node(s).',
      $stats['deleted']
    ));

    if (!empty($stats['bundles'])) {
      foreach ($stats['bundles'] as $bundle => $count) {
        $this->io()->text(sprintf('  Bundle "%s": %d deleted', $bundle, $count));
      }
    }
  }

  /**
   * Seeds deterministic demo data (vendors, events, products, RSVPs).
   *
   * Runs reset-events first, then seeds:
   * - 2 vendor users (vendor2, vendor3)
   * - Vendor entities with stores
   * - 6 events total (2 ticketed + 1 RSVP per vendor)
   * - Ticket products and variations.
   */
  #[CLI\Command(name: 'mel:seed-demo', aliases: ['mel-seed-demo'])]
  #[CLI\Usage(name: 'drush mel:seed-demo', description: 'Resets events and seeds demo data.')]
  public function seedDemo(): void {
    $this->io()->title('Seeding Demo Data');

    // First reset events.
    $this->io()->note('Resetting all existing events...');
    $this->eventResetService->deleteAllEvents();

    // Then seed.
    $this->io()->note('Seeding demo data...');
    $summary = $this->demoSeeder->seedDemo();

    if (empty($summary)) {
      $this->io()->error('Demo data seeding failed.');
      return;
    }

    $this->io()->success('Demo data seeded successfully.');

    // Output summary table.
    $rows = [];
    foreach ($summary as $vendorData) {
      foreach ($vendorData['events'] as $event) {
        $rows[] = [
          'vendor' => $vendorData['vendor'],
          'user_id' => $vendorData['user_id'],
          'vendor_id' => $vendorData['vendor_id'],
          'store_id' => $vendorData['store_id'],
          'event_nid' => $event['event_nid'] ?? 'N/A',
          'type' => $event['type'] ?? 'N/A',
          'product_id' => $event['product_id'] ?? 'N/A',
          'variations' => isset($event['variation_ids']) ? count($event['variation_ids']) : 'N/A',
        ];
      }
    }

    $this->io()->table(
      ['Vendor', 'User ID', 'Vendor ID', 'Store ID', 'Event NID', 'Type', 'Product ID', 'Variations'],
      $rows
    );
  }

  /**
   * Purges all seeded demo data (vendors, users, events, products, RSVPs).
   *
   * Removes only content created by mel:seed-demo:
   * - Users: vendor2, vendor3
   * - Their vendor entities
   * - Their stores
   * - Their events and products
   * - RSVP submissions for their events.
   *
   * Does NOT delete unrelated content.
   */
  #[CLI\Command(name: 'mel:purge-demo', aliases: ['mel-purge-demo'])]
  #[CLI\Usage(name: 'drush mel:purge-demo', description: 'Removes all seeded demo data.')]
  public function purgeDemo(): void {
    $this->io()->title('Purging Demo Data');
    $this->io()->note('This will remove seeded vendors, users, events, products, and RSVPs.');

    $stats = $this->demoPurger->purgeDemo();

    $this->io()->success('Demo data purge complete.');
    $this->io()->text(sprintf('Deleted: %d users, %d vendors, %d stores, %d events, %d products, %d variations, %d RSVPs',
      $stats['users_deleted'],
      $stats['vendors_deleted'],
      $stats['stores_deleted'],
      $stats['events_deleted'],
      $stats['products_deleted'],
      $stats['variations_deleted'],
      $stats['rsvps_deleted']
    ));
  }

}
