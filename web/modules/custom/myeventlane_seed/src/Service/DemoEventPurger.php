<?php

declare(strict_types=1);

namespace Drupal\myeventlane_seed\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\FileInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\user\Entity\User;

/**
 * Safely purges Anna-owned demo events identified by seed markers only.
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
   * Purges seeded demo events for the configured owner.
   *
   * Never deletes vendor, store, user, or non-seeded content.
   *
   * @param array<string, mixed> $options
   *   Optional overrides: owner (username), dry_run (bool).
   *
   * @return array<string, int>
   *   Purge statistics.
   */
  public function purge(array $options = []): array {
    $settings = $this->resolveSettings($options);
    $logger = $this->loggerFactory->get('myeventlane_seed');
    $dryRun = !empty($settings['dry_run']);

    $stats = [
      'events_deleted' => 0,
      'paragraphs_deleted' => 0,
      'ticket_types_deleted' => 0,
      'products_deleted' => 0,
      'variations_deleted' => 0,
      'files_deleted' => 0,
      'rsvps_deleted' => 0,
      'skipped_non_seed' => 0,
    ];

    $owner = $this->loadOwnerUser((string) $settings['owner_username']);
    if ($owner === NULL) {
      throw new \InvalidArgumentException(sprintf('Owner user "%s" was not found.', $settings['owner_username']));
    }

    $vendor = $this->currentVendorResolver->resolveFromUser($owner);
    if ($vendor === NULL) {
      throw new \InvalidArgumentException(sprintf('No vendor entity found for user "%s".', $settings['owner_username']));
    }

    $events = $this->loadSeedMarkedEvents($vendor, $settings);
    if ($events === []) {
      $logger->info('No seeded demo events found for vendor @vid (owner @user).', [
        '@vid' => (string) $vendor->id(),
        '@user' => $settings['owner_username'],
      ]);
      return $stats;
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $ticketStorage = $this->entityTypeManager->getStorage('mel_ticket_type');
    $productStorage = $this->entityTypeManager->getStorage('commerce_product');
    $variationStorage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $rsvpStorage = $this->entityTypeManager->hasDefinition('rsvp_submission')
      ? $this->entityTypeManager->getStorage('rsvp_submission')
      : NULL;
    $fileStorage = $this->entityTypeManager->getStorage('file');

    $eventIds = [];
    $productIds = [];
    $ticketIds = [];
    $paragraphIds = [];
    $fileIds = [];

    foreach ($events as $event) {
      if (!$this->isSeedOwnedEvent($event, $settings)) {
        $stats['skipped_non_seed']++;
        continue;
      }

      $eventIds[] = (int) $event->id();

      if ($event->hasField('field_event_highlights') && !$event->get('field_event_highlights')->isEmpty()) {
        foreach ($event->get('field_event_highlights')->referencedEntities() as $paragraph) {
          if ($paragraph instanceof ParagraphInterface) {
            $paragraphIds[] = (int) $paragraph->id();
          }
        }
      }

      if ($event->hasField('field_attendee_questions') && !$event->get('field_attendee_questions')->isEmpty()) {
        foreach ($event->get('field_attendee_questions')->referencedEntities() as $paragraph) {
          if ($paragraph instanceof ParagraphInterface) {
            $paragraphIds[] = (int) $paragraph->id();
          }
        }
      }

      if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
        foreach ($event->get('field_ticket_types')->referencedEntities() as $ticket) {
          if ($ticket instanceof TicketTypeInterface) {
            $ticketIds[] = (int) $ticket->id();
          }
        }
      }

      if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
        $product = $event->get('field_product_target')->entity;
        if ($product instanceof ProductInterface && $this->isProductOwnedBySeedEvent($product, $event)) {
          $productIds[] = (int) $product->id();
        }
      }

      if ($event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty()) {
        $file = $event->get('field_event_image')->entity;
        if ($file instanceof FileInterface && $this->isSeedImageFile($file, $settings)) {
          $fileIds[] = (int) $file->id();
        }
      }
    }

    $eventIds = array_values(array_unique($eventIds));
    $paragraphIds = array_values(array_unique($paragraphIds));
    $ticketIds = array_values(array_unique($ticketIds));
    $productIds = array_values(array_unique($productIds));
    $fileIds = array_values(array_unique($fileIds));

    if ($dryRun) {
      $logger->info('Dry-run purge would remove @events events, @paragraphs paragraphs, @tickets tickets, @products products for owner @user.', [
        '@events' => (string) count($eventIds),
        '@paragraphs' => (string) count($paragraphIds),
        '@tickets' => (string) count($ticketIds),
        '@products' => (string) count($productIds),
        '@user' => $settings['owner_username'],
      ]);
      return [
        'events_deleted' => count($eventIds),
        'paragraphs_deleted' => count($paragraphIds),
        'ticket_types_deleted' => count($ticketIds),
        'products_deleted' => count($productIds),
        'variations_deleted' => 0,
        'files_deleted' => count($fileIds),
        'rsvps_deleted' => 0,
        'skipped_non_seed' => $stats['skipped_non_seed'],
      ];
    }

    if ($rsvpStorage !== NULL && $eventIds !== []) {
      $rsvps = $rsvpStorage->loadByProperties(['event_id' => $eventIds]);
      foreach ($rsvps as $rsvp) {
        $rsvp->delete();
        $stats['rsvps_deleted']++;
      }
    }

    if ($productIds !== []) {
      $variations = $variationStorage->loadByProperties(['product_id' => $productIds]);
      foreach ($variations as $variation) {
        $variation->delete();
        $stats['variations_deleted']++;
      }
      foreach ($productStorage->loadMultiple($productIds) as $product) {
        $product->delete();
        $stats['products_deleted']++;
      }
    }

    foreach ($ticketStorage->loadMultiple($ticketIds) as $ticket) {
      $ticket->delete();
      $stats['ticket_types_deleted']++;
    }

    foreach ($nodeStorage->loadMultiple($eventIds) as $event) {
      $event->delete();
      $stats['events_deleted']++;
    }

    $paragraphStorage = $this->entityTypeManager->getStorage('paragraph');
    foreach ($paragraphStorage->loadMultiple($paragraphIds) as $paragraph) {
      $paragraph->delete();
      $stats['paragraphs_deleted']++;
    }

    foreach ($fileStorage->loadMultiple($fileIds) as $file) {
      $file->delete();
      $stats['files_deleted']++;
    }

    $logger->info('Purged seeded demo content for @user: @stats', [
      '@user' => $settings['owner_username'],
      '@stats' => json_encode($stats),
    ]);

    return $stats;
  }

  /**
   * @param array<string, mixed> $options
   *
   * @return array<string, mixed>
   */
  private function resolveSettings(array $options): array {
    $config = $this->configFactory->get('myeventlane_seed.settings');
    $settings = [
      'owner_username' => (string) ($options['owner'] ?? $config->get('owner_username') ?? 'anna'),
      'seed_key_prefix' => (string) ($config->get('seed_key_prefix') ?? 'mel_seed'),
      'title_prefix' => (string) ($config->get('title_prefix') ?? '[MEL TEST]'),
      'dry_run' => !empty($options['dry_run']) || (bool) ($config->get('dry_run') ?? FALSE),
      'image_directory' => (string) ($config->get('image_directory') ?? 'public://events'),
    ];
    return $settings;
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
   * @param array<string, mixed> $settings
   *
   * @return list<\Drupal\node\NodeInterface>
   */
  private function loadSeedMarkedEvents(Vendor $vendor, array $settings): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $prefix = (string) $settings['seed_key_prefix'];

    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('field_event_vendor', (int) $vendor->id())
      ->condition('field_event_summary', '%' . self::SEED_KEY_MARKER . ' ' . $prefix . '_%', 'LIKE')
      ->execute();
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

  /**
   * @param array<string, mixed> $settings
   */
  private function isSeedOwnedEvent(NodeInterface $event, array $settings): bool {
    $prefix = (string) $settings['seed_key_prefix'];
    if (!$event->hasField('field_event_summary') || $event->get('field_event_summary')->isEmpty()) {
      return FALSE;
    }
    $summary = (string) $event->get('field_event_summary')->value;
    return str_contains($summary, self::SEED_KEY_MARKER . ' ' . $prefix . '_');
  }

  /**
   * Only purge Commerce products created for this seeded event.
   */
  private function isProductOwnedBySeedEvent(ProductInterface $product, NodeInterface $event): bool {
    if ($product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
      return (int) $product->get('field_event')->target_id === (int) $event->id();
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $settings
   */
  private function isSeedImageFile(FileInterface $file, array $settings): bool {
    $uri = (string) $file->getFileUri();
    $directory = rtrim((string) $settings['image_directory'], '/');
    $prefix = (string) $settings['seed_key_prefix'];
    if (!str_starts_with($uri, $directory)) {
      return FALSE;
    }
    $basename = basename($uri);
    return str_starts_with($basename, $prefix . '_') || str_starts_with($basename, 'event_' . $prefix);
  }

}
