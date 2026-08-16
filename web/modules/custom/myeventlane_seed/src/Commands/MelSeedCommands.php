<?php

declare(strict_types=1);

namespace Drupal\myeventlane_seed\Commands;

use Drupal\myeventlane_seed\Service\DemoEventPurger;
use Drupal\myeventlane_seed\Service\DemoEventSeeder;
use Drupal\myeventlane_seed\Service\DemoPurger;
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
   * @param \Drupal\myeventlane_seed\Service\DemoPurger $demoPurger
   *   The legacy demo purger.
   * @param \Drupal\myeventlane_seed\Service\DemoEventSeeder $demoEventSeeder
   *   The configurable Anna [MEL TEST] event seeder.
   * @param \Drupal\myeventlane_seed\Service\DemoEventPurger $demoEventPurger
   *   The Anna seed-key event purger.
   */
  public function __construct(
    private readonly DemoPurger $demoPurger,
    private readonly DemoEventSeeder $demoEventSeeder,
    private readonly DemoEventPurger $demoEventPurger,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_seed.demo_purger'),
      $container->get('myeventlane_seed.demo_event_seeder'),
      $container->get('myeventlane_seed.demo_event_purger'),
    );
  }

  /**
   * Seeds deterministic [MEL TEST] demo events for the configured vendor owner.
   */
  #[CLI\Command(name: 'mel:seed-events', aliases: ['mel-seed-events'])]
  #[CLI\Option(name: 'use-settings', description: 'Read generation options from myeventlane_seed.settings config.')]
  #[CLI\Option(name: 'count', description: 'Override event count.')]
  #[CLI\Option(name: 'owner', description: 'Override owner username (default anna).')]
  #[CLI\Option(name: 'dry-run', description: 'Log planned actions without saving entities.')]
  #[CLI\Usage(name: 'drush mel:seed-events', description: 'Seeds 8 legacy-default [MEL TEST] events for anna.')]
  #[CLI\Usage(name: 'drush mel:seed-events --use-settings --dry-run', description: 'Dry-run using admin config.')]
  public function seedEvents(
    array $options = [
      'use-settings' => FALSE,
      'count' => NULL,
      'owner' => NULL,
      'dry-run' => FALSE,
    ],
  ): void {
    $this->io()->title('Seeding [MEL TEST] Demo Events');

    $cliOptions = [
      'use-settings' => !empty($options['use-settings']),
      'dry-run' => !empty($options['dry-run']),
    ];
    if ($options['count'] !== NULL && $options['count'] !== '') {
      $cliOptions['count'] = (int) $options['count'];
    }
    if (!empty($options['owner'])) {
      $cliOptions['owner'] = (string) $options['owner'];
    }

    try {
      $summary = $this->demoEventSeeder->seed($cliOptions);
    }
    catch (\Throwable $e) {
      $this->io()->error($e->getMessage());
      return;
    }

    if (!empty($summary['dry_run'])) {
      $this->io()->success(sprintf('Dry-run: %d event(s) planned for %s.', count($summary['events']), $summary['owner_username']));
    }
    else {
      $this->io()->success(sprintf('Seeded %d event(s) for %s.', count($summary['events']), $summary['owner_username']));
    }

    $rows = [];
    foreach ($summary['events'] as $event) {
      $rows[] = [
        $event['seed_key'] ?? 'N/A',
        $event['type'] ?? ($event['action'] ?? 'N/A'),
        $event['event_nid'] ?? ($event['title'] ?? 'N/A'),
        $event['title'] ?? 'N/A',
      ];
    }

    if ($rows !== []) {
      $this->io()->table(['Seed key', 'Type', 'NID / action', 'Title'], $rows);
    }
  }

  /**
   * Purges seeded [MEL TEST] demo events for the configured owner only.
   */
  #[CLI\Command(name: 'mel:purge-events', aliases: ['mel-purge-events'])]
  #[CLI\Option(name: 'owner', description: 'Override owner username.')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would be purged without deleting.')]
  #[CLI\Usage(name: 'drush mel:purge-events', description: 'Removes Anna-owned seeded demo events and related seed-owned entities.')]
  public function purgeEvents(
    array $options = [
      'owner' => NULL,
      'dry-run' => FALSE,
    ],
  ): void {
    $this->io()->title('Purging [MEL TEST] Demo Events');
    $this->io()->note('Only seed-marked events for the configured owner are removed. Vendor, store, and user are never deleted.');

    $purgeOptions = ['dry_run' => !empty($options['dry-run'])];
    if (!empty($options['owner'])) {
      $purgeOptions['owner'] = (string) $options['owner'];
    }

    try {
      $stats = $this->demoEventPurger->purge($purgeOptions);
    }
    catch (\Throwable $e) {
      $this->io()->error($e->getMessage());
      return;
    }

    $this->io()->success('Demo event purge complete.');
    $this->io()->text(sprintf(
      'Deleted: %d events, %d paragraphs, %d ticket types, %d products, %d variations, %d files, %d RSVPs (skipped non-seed: %d)',
      $stats['events_deleted'],
      $stats['paragraphs_deleted'],
      $stats['ticket_types_deleted'],
      $stats['products_deleted'],
      $stats['variations_deleted'],
      $stats['files_deleted'],
      $stats['rsvps_deleted'],
      $stats['skipped_non_seed'],
    ));
  }

  /**
   * Purges all seeded demo data (vendors, users, events, products, RSVPs).
   *
   * Removes only content created by the legacy vendor2/vendor3 seed process:
   * - Users: vendor2, vendor3
   * - Their vendor entities
   * - Their stores
   * - Their events and products
   * - RSVP submissions for their events.
   *
   * Does NOT delete unrelated content.
   */
  #[CLI\Command(name: 'mel:purge-demo', aliases: ['mel-purge-demo'])]
  #[CLI\Usage(name: 'drush mel:purge-demo', description: 'Removes all seeded vendor2/vendor3 demo data.')]
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
