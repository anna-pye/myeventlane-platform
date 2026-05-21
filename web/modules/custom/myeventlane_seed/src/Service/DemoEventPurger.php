<?php

declare(strict_types=1);

namespace Drupal\myeventlane_seed\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use InvalidArgumentException;

/**
 * Purges deterministic [MEL TEST] demo events for a configured vendor owner.
 */
final class DemoEventPurger {

  private const SEED_KEY_MARKER = 'mel_seed_key:';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CurrentVendorResolverInterface $currentVendorResolver,
  ) {}

  /**
   * Purges seeded demo events for a configured or supplied owner.
   *
   * @param array<string, mixed> $options
   *   Supported keys: owner (username), dry_run (bool).
   *
   * @return array{events_deleted: int, ticket_types_deleted: int, products_deleted: int, dry_run: bool}
   */
  public function purge(array $options = []): array {
    $config = $this->configFactory->get('myeventlane_seed.settings');
    $owner = (string) ($options['owner'] ?? $config->get('owner_username') ?? 'anna');
    if ($owner === '') {
      throw new InvalidArgumentException('owner_username is not configured.');
    }

    $dryRun = !empty($options['dry_run']);
    $user = $this->loadOwnerUser($owner);
    if ($user === NULL) {
      throw new InvalidArgumentException(sprintf('Owner user "%s" was not found.', $owner));
    }

    $vendor = $this->currentVendorResolver->resolveFromUser($user);
    if (!$vendor instanceof Vendor) {
      throw new InvalidArgumentException(sprintf('No vendor entity found for user "%s".', $owner));
    }

    $events = $this->loadSeedEventsForVendor($vendor);
    $stats = [
      'events_deleted' => 0,
      'ticket_types_deleted' => 0,
      'products_deleted' => 0,
      'dry_run' => $dryRun,
    ];

    if ($events === []) {
      return $stats;
    }

    $logger = $this->loggerFactory->get('myeventlane_seed');
    if ($dryRun) {
      $logger->info('Dry-run: would purge @count seeded events for @user.', [
        '@count' => (string) count($events),
        '@user' => $owner,
      ]);
      return $stats;
    }

    $ticketStorage = $this->entityTypeManager->getStorage('mel_ticket_type');
    $productStorage = $this->entityTypeManager->getStorage('commerce_product');
    $variationStorage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $rsvpStorage = $this->entityTypeManager->getStorage('rsvp_submission');

    $productIds = [];
    $ticketIds = [];
    $eventIds = [];

    foreach ($events as $event) {
      $eventIds[] = (int) $event->id();

      if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
        foreach ($event->get('field_ticket_types')->referencedEntities() as $ticket) {
          if ($ticket instanceof TicketTypeInterface) {
            $ticketIds[] = (int) $ticket->id();
          }
        }
      }

      if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
        $product = $event->get('field_product_target')->entity;
        if ($product instanceof ProductInterface) {
          $productIds[] = (int) $product->id();
        }
      }
    }

    if ($eventIds !== [] && $rsvpStorage !== NULL) {
      $rsvps = $rsvpStorage->loadByProperties(['event_id' => $eventIds]);
      foreach ($rsvps as $rsvp) {
        $rsvp->delete();
      }
    }

    if ($productIds !== []) {
      $variations = $variationStorage->loadByProperties(['product_id' => array_values(array_unique($productIds))]);
      foreach ($variations as $variation) {
        $variation->delete();
      }
    }

    if ($ticketIds !== []) {
      $tickets = $ticketStorage->loadMultiple(array_values(array_unique($ticketIds)));
      foreach ($tickets as $ticket) {
        $ticket->delete();
        $stats['ticket_types_deleted']++;
      }
    }

    if ($productIds !== []) {
      $products = $productStorage->loadMultiple(array_values(array_unique($productIds)));
      foreach ($products as $product) {
        $product->delete();
        $stats['products_deleted']++;
      }
    }

    foreach ($events as $event) {
      $event->delete();
      $stats['events_deleted']++;
    }

    $logger->info('Purged seeded demo content for @user: @stats', [
      '@user' => $owner,
      '@stats' => json_encode($stats, JSON_THROW_ON_ERROR),
    ]);

    return $stats;
  }

  private function loadOwnerUser(string $username): ?User {
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $username]);
    if ($users === []) {
      return NULL;
    }
    $user = reset($users);
    return $user instanceof User ? $user : NULL;
  }

  /**
   * @return list<NodeInterface>
   */
  private function loadSeedEventsForVendor(Vendor $vendor): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $titlePrefix = (string) ($this->configFactory->get('myeventlane_seed.settings')->get('title_prefix') ?? '[MEL TEST]');

    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('field_event_vendor', (int) $vendor->id())
      ->condition('field_event_summary', '%' . self::SEED_KEY_MARKER . '%', 'LIKE')
      ->execute();

    if ($nids === []) {
      $nids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('field_event_vendor', (int) $vendor->id())
        ->condition('title', $titlePrefix . '%', 'LIKE')
        ->execute();
    }

    if ($nids === []) {
      return [];
    }

    $events = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      if ($node instanceof NodeInterface) {
        $events[] = $node;
      }
    }
    return $events;
  }

}
