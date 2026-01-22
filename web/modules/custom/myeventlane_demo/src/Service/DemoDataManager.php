<?php

declare(strict_types=1);

namespace Drupal\myeventlane_demo\Service;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\Entity\Node;
use Psr\Log\LoggerInterface;

/**
 * Manages creation and cleanup of demo event data.
 *
 * This service creates a well-defined set of demo events to exercise
 * all key booking paths: RSVP-only, Tickets-only, Both, Coming Soon, and Past.
 */
final class DemoDataManager {

  /**
   * Prefix used to identify demo content.
   */
  public const DEMO_PREFIX = '[DEMO] ';

  /**
   * The logger channel.
   */
  private LoggerInterface $logger;

  /**
   * Constructs DemoDataManager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('myeventlane_demo');
  }

  /**
   * Gets the demo event definitions.
   *
   * @return array
   *   Array of demo event configurations.
   */
  public function getEventDefinitions(): array {
    $now = new \DateTimeImmutable('now', new \DateTimeZone('Australia/Sydney'));

    return [
      'community_meetup' => [
        'title' => self::DEMO_PREFIX . 'Community Meetup',
        'body' => 'Join us for a casual community gathering! Network with like-minded people, share ideas, and enjoy light refreshments. This is a free RSVP event with limited capacity.',
        'event_type' => 'rsvp',
        'start' => $now->modify('+14 days 18:00'),
        'end' => $now->modify('+14 days 21:00'),
        'venue' => 'Demo Community Hall',
        'capacity' => 50,
        'needs_product' => FALSE,
      ],
      'tech_conference' => [
        'title' => self::DEMO_PREFIX . 'Tech Conference 2025',
        'body' => 'The premier technology conference of the year! Featuring keynote speakers, workshops, and networking opportunities. General admission and VIP packages available.',
        'event_type' => 'paid',
        'start' => $now->modify('+30 days 09:00'),
        'end' => $now->modify('+30 days 17:00'),
        'venue' => 'Demo Convention Centre',
        'capacity' => 0,
        'needs_product' => TRUE,
        'variations' => [
          ['label' => 'General Admission', 'price' => '49.00'],
          ['label' => 'VIP Pass', 'price' => '149.00'],
        ],
      ],
      'charity_gala' => [
        'title' => self::DEMO_PREFIX . 'Charity Gala',
        'body' => 'An elegant evening supporting local charities. Includes dinner, entertainment, and silent auction. Both free RSVP spots and donation tickets are available.',
        'event_type' => 'rsvp',
        'start' => $now->modify('+21 days 19:00'),
        'end' => $now->modify('+21 days 23:00'),
        'venue' => 'Demo Grand Ballroom',
        'capacity' => 100,
        'needs_product' => TRUE,
        'variations' => [
          ['label' => 'Donation Ticket', 'price' => '25.00'],
        ],
      ],
      'mystery_event' => [
        'title' => self::DEMO_PREFIX . 'Mystery Event',
        'body' => 'Something exciting is coming! Details will be revealed soon. Stay tuned for more information about this upcoming event.',
        'event_type' => '',
        'start' => $now->modify('+60 days 10:00'),
        'end' => $now->modify('+60 days 16:00'),
        'venue' => 'To Be Announced',
        'capacity' => 0,
        'needs_product' => FALSE,
      ],
      'past_workshop' => [
        'title' => self::DEMO_PREFIX . 'Past Workshop',
        'body' => 'This hands-on workshop covered the fundamentals of event management. Participants learned best practices for planning and executing successful events.',
        'event_type' => 'rsvp',
        'start' => $now->modify('-7 days 10:00'),
        'end' => $now->modify('-7 days 15:00'),
        'venue' => 'Demo Training Room',
        'capacity' => 30,
        'needs_product' => FALSE,
      ],
    ];
  }

  /**
   * Seeds all demo events.
   *
   * @return array
   *   Array with 'events' and 'products' keys containing created entity IDs.
   *
   * @throws \RuntimeException
   *   If no Commerce store exists.
   */
  public function seedEvents(): array {
    $results = [
      'events' => [],
      'products' => [],
    ];

    // Get the first available store for products.
    $store = $this->getStore();

    // Get taxonomy terms for categories and accessibility.
    $categoryTerm = $this->getFirstTerm('categories');
    $accessibilityTerm = $this->getFirstTerm('accessibility');

    // Get the organizer (current user or admin).
    $organizerUid = $this->getOrganizerId();

    foreach ($this->getEventDefinitions() as $definition) {
      // Create product first if needed.
      $product = NULL;
      if ($definition['needs_product'] && $store) {
        $product = $this->createDemoProduct($definition, $store);
        if ($product) {
          $results['products'][] = $product->id();
          $this->logger->notice('Created demo product: @title (ID: @id)', [
            '@title' => $product->getTitle(),
            '@id' => $product->id(),
          ]);
        }
      }

      // Create the event node.
      $event = $this->createDemoEvent($definition, $organizerUid, $categoryTerm, $accessibilityTerm, $product);
      $results['events'][] = $event->id();

      $this->logger->notice('Created demo event: @title (ID: @id)', [
        '@title' => $event->getTitle(),
        '@id' => $event->id(),
      ]);

      // Link product back to event if created.
      if ($product && $product->hasField('field_event')) {
        $product->set('field_event', ['target_id' => $event->id()]);
        $product->save();
      }
    }

    return $results;
  }

  /**
   * Deletes all demo events and their associated products.
   *
   * @return array
   *   Array with counts of deleted entities.
   */
  public function cleanup(): array {
    $results = [
      'events_deleted' => 0,
      'products_deleted' => 0,
    ];

    // Find all demo events.
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $eventIds = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('title', self::DEMO_PREFIX, 'STARTS_WITH')
      ->execute();

    if (empty($eventIds)) {
      return $results;
    }

    $events = $nodeStorage->loadMultiple($eventIds);

    // Collect product IDs to delete.
    $productIdsToDelete = [];
    foreach ($events as $event) {
      if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
        $product = $event->get('field_product_target')->entity;
        if ($product && str_starts_with($product->getTitle(), self::DEMO_PREFIX)) {
          $productIdsToDelete[$product->id()] = $product->id();
        }
      }
    }

    // Delete demo products first.
    if (!empty($productIdsToDelete)) {
      $productStorage = $this->entityTypeManager->getStorage('commerce_product');
      $products = $productStorage->loadMultiple($productIdsToDelete);
      foreach ($products as $product) {
        $this->logger->notice('Deleting demo product: @title (ID: @id)', [
          '@title' => $product->getTitle(),
          '@id' => $product->id(),
        ]);
        $product->delete();
        $results['products_deleted']++;
      }
    }

    // Delete demo events.
    foreach ($events as $event) {
      $this->logger->notice('Deleting demo event: @title (ID: @id)', [
        '@title' => $event->getTitle(),
        '@id' => $event->id(),
      ]);
      $event->delete();
      $results['events_deleted']++;
    }

    return $results;
  }

  /**
   * Checks if demo data already exists.
   *
   * @return bool
   *   TRUE if any demo events exist.
   */
  public function demoDataExists(): bool {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $count = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('title', self::DEMO_PREFIX, 'STARTS_WITH')
      ->count()
      ->execute();

    return $count > 0;
  }

  /**
   * Gets the first available Commerce store.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface|null
   *   The store entity or NULL if none exists.
   */
  public function getStore(): ?object {
    $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
    $stores = $storeStorage->loadMultiple();

    if (empty($stores)) {
      return NULL;
    }

    return reset($stores);
  }

  /**
   * Creates a demo event node.
   */
  private function createDemoEvent(
    array $definition,
    int $organizerUid,
    ?object $categoryTerm,
    ?object $accessibilityTerm,
    ?object $product,
  ): object {
    $values = [
      'type' => 'event',
      'title' => $definition['title'],
      'body' => [
        'value' => $definition['body'],
        'format' => 'basic_html',
      ],
      'status' => 1,
      'uid' => $organizerUid,
    ];

    // Event type (booking mode).
    if (!empty($definition['event_type'])) {
      $values['field_event_type'] = $definition['event_type'];
    }

    // Dates.
    if ($definition['start'] instanceof \DateTimeInterface) {
      $values['field_event_start'] = $definition['start']->format('Y-m-d\TH:i:s');
    }
    if ($definition['end'] instanceof \DateTimeInterface) {
      $values['field_event_end'] = $definition['end']->format('Y-m-d\TH:i:s');
    }

    // Venue.
    if (!empty($definition['venue'])) {
      $values['field_venue_name'] = $definition['venue'];
    }

    // Location (Australian address).
    $values['field_location'] = [
      'country_code' => 'AU',
      'address_line1' => '123 Demo Street',
      'locality' => 'Sydney',
      'administrative_area' => 'NSW',
      'postal_code' => '2000',
    ];

    // Capacity.
    if (!empty($definition['capacity'])) {
      $values['field_capacity'] = $definition['capacity'];
    }

    // Vendor (find or create vendor for organizer user).
    $vendor = $this->getOrCreateVendorForUser($organizerUid);
    if ($vendor) {
      $values['field_event_vendor'] = ['target_id' => $vendor->id()];
    }

    // Category.
    if ($categoryTerm) {
      $values['field_category'] = ['target_id' => $categoryTerm->id()];
    }

    // Accessibility.
    if ($accessibilityTerm) {
      $values['field_accessibility'] = ['target_id' => $accessibilityTerm->id()];
    }

    // Link to product.
    if ($product) {
      $values['field_product_target'] = ['target_id' => $product->id()];
    }

    $node = Node::create($values);
    $node->save();

    return $node;
  }

  /**
   * Creates a demo Commerce product with variations.
   */
  private function createDemoProduct(array $definition, object $store): ?object {
    if (empty($definition['variations'])) {
      return NULL;
    }

    $productTitle = self::DEMO_PREFIX . 'Tickets for ' . str_replace(self::DEMO_PREFIX, '', $definition['title']);

    // Create variations first.
    $variations = [];
    foreach ($definition['variations'] as $index => $varDef) {
      $sku = 'DEMO-' . strtoupper(substr(md5($productTitle . $varDef['label']), 0, 8)) . '-' . $index;

      $variation = ProductVariation::create([
        'type' => 'default',
        'sku' => $sku,
        'title' => $varDef['label'],
        'price' => new Price($varDef['price'], 'AUD'),
        'status' => 1,
      ]);
      $variation->save();
      $variations[] = $variation;
    }

    // Create product.
    $product = Product::create([
      'type' => 'default',
      'title' => $productTitle,
      'stores' => [$store->id()],
      'variations' => $variations,
      'status' => 1,
    ]);
    $product->save();

    return $product;
  }

  /**
   * Gets the first term from a vocabulary.
   */
  private function getFirstTerm(string $vocabulary): ?object {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $termStorage->loadByProperties(['vid' => $vocabulary]);

    if (empty($terms)) {
      return NULL;
    }

    return reset($terms);
  }

  /**
   * Gets the organizer user ID.
   */
  private function getOrganizerId(): int {
    $uid = (int) $this->currentUser->id();

    // Fall back to admin user if anonymous.
    if ($uid === 0) {
      $uid = 1;
    }

    return $uid;
  }

  /**
   * Gets or creates a vendor entity for a user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if unable to create.
   */
  private function getOrCreateVendorForUser(int $uid): ?object {
    $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');

    // Try to find existing vendor owned by this user.
    $vendors = $vendorStorage->loadByProperties(['uid' => $uid]);
    if (!empty($vendors)) {
      return reset($vendors);
    }

    // Try to find vendor where user is in field_vendor_users.
    $query = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_vendor_users', $uid)
      ->range(0, 1);
    $vendorIds = $query->execute();
    if (!empty($vendorIds)) {
      return $vendorStorage->load(reset($vendorIds));
    }

    // Create a new vendor for this user.
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user) {
      return NULL;
    }

    $vendor = $vendorStorage->create([
      'name' => $user->getDisplayName() . "'s Events",
      'uid' => $uid,
    ]);
    $vendor->save();

    return $vendor;
  }

}
