<?php

declare(strict_types=1);

namespace Drupal\myeventlane_seed\Service;

use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Image\ImageFactory as CoreImageFactory;
use Drupal\myeventlane_seed\Util\ImageFactory;
use Drupal\user\Entity\User;

/**
 * Service for seeding deterministic demo data.
 */
final class DemoSeeder {

  /**
   * Seed data markers for tracking created content.
   */
  private const MARKER_PREFIX = 'mel_seed_';

  /**
   * Constructs a DemoSeeder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Image\ImageFactory $imageFactory
   *   The core image factory.
   * @param \Drupal\myeventlane_seed\Util\ImageFactory $imageFactoryUtil
   *   The image factory utility.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly FileSystemInterface $fileSystem,
    private readonly CoreImageFactory $imageFactory,
    private readonly ImageFactory $imageFactoryUtil,
  ) {}

  /**
   * Seeds all demo data.
   *
   * @return array
   *   Summary table data: ['vendor' => string, 'user_id' => int, 'vendor_id' => int, 'store_id' => int, 'events' => [...]]
   */
  public function seedDemo(): array {
    $logger = $this->loggerFactory->get('myeventlane_seed');
    $logger->info('Starting demo data seed...');

    // Track all created content.
    $summary = [];

    // Seed Vendor 2 and Vendor 3.
    $vendor2 = $this->seedVendor('vendor2', 'Vendor 2', 'vendor2@example.com', 'Vendor 2 Demo Account');
    $vendor3 = $this->seedVendor('vendor3', 'Vendor 3', 'vendor3@example.com', 'Vendor 3 Demo Account');

    if (!$vendor2 || !$vendor3) {
      $logger->error('Failed to create vendor users.');
      return [];
    }

    $summary[] = $this->seedVendorEvents($vendor2, 'Vendor 2');
    $summary[] = $this->seedVendorEvents($vendor3, 'Vendor 3');

    $logger->info('Demo data seed complete.');
    return $summary;
  }

  /**
   * Creates or loads a vendor user account.
   *
   * @param string $username
   *   Username.
   * @param string $name
   *   Display name.
   * @param string $email
   *   Email address.
   * @param string $description
   *   User description.
   *
   * @return \Drupal\user\Entity\User|null
   *   The user entity, or NULL on failure.
   */
  private function seedVendor(string $username, string $name, string $email, string $description): ?User {
    $storage = $this->entityTypeManager->getStorage('user');

    // Check if user already exists.
    $existing = $storage->loadByProperties(['name' => $username]);
    if (!empty($existing)) {
      /** @var \Drupal\user\Entity\User $user */
      $user = reset($existing);
      // Ensure vendor role is assigned.
      if (!$user->hasRole('vendor')) {
        $user->addRole('vendor');
        $user->save();
      }
      return $user;
    }

    /** @var \Drupal\user\Entity\User $user */
    $user = $storage->create([
      'name' => $username,
      'mail' => $email,
      'status' => 1,
      'roles' => ['authenticated', 'vendor'],
      'pass' => 'password',
    ]);
    $user->save();

    return $user;
  }

  /**
   * Seeds events for a vendor.
   *
   * @param \Drupal\user\Entity\User $vendorUser
   *   The vendor user.
   * @param string $vendorLabel
   *   Vendor label for logging.
   *
   * @return array
   *   Summary data for this vendor.
   */
  private function seedVendorEvents(User $vendorUser, string $vendorLabel): array {
    $logger = $this->loggerFactory->get('myeventlane_seed');
    $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $storeStorage = $this->entityTypeManager->getStorage('commerce_store');

    // Get or create vendor entity.
    $vendors = $vendorStorage->loadByProperties(['uid' => $vendorUser->id()]);
    if (empty($vendors)) {
      /** @var \Drupal\myeventlane_vendor\Entity\Vendor $vendor */
      $vendor = $vendorStorage->create([
        'name' => $vendorLabel,
        'uid' => $vendorUser->id(),
      ]);
      $vendor->save();
    }
    else {
      $vendor = reset($vendors);
    }

    // Get or create store.
    $store = NULL;
    if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
    }

    if (!$store) {
      // Create store if it doesn't exist.
      $store = $storeStorage->create([
        'type' => 'online',
        'uid' => $vendorUser->id(),
        'name' => $vendorLabel . ' Store',
        'mail' => $vendorUser->getEmail(),
        'default_currency' => 'AUD',
        'timezone' => 'Australia/Sydney',
        'address' => [
          'country_code' => 'AU',
          'locality' => 'Sydney',
          'address_line1' => '123 Demo Street',
          'postal_code' => '2000',
        ],
        'billing_countries' => ['AU'],
        'is_default' => FALSE,
        'status' => TRUE,
      ]);
      $store->set('field_vendor_reference', $vendor);
      $store->save();

      $vendor->set('field_vendor_store', $store);
      $vendor->save();
    }

    // Create vendor logo image.
    if ($vendor->hasField('field_vendor_logo') && $vendor->get('field_vendor_logo')->isEmpty()) {
      $logo = $this->imageFactoryUtil->createPlaceholderImage(
        'vendor_logo_' . $vendor->id(),
        300,
        300,
        substr($vendorLabel, 0, 10),
        'vendor_logos'
      );
      if ($logo) {
        $vendor->set('field_vendor_logo', [
          'target_id' => $logo->id(),
          'alt' => $vendorLabel . ' Logo',
        ]);
        $vendor->save();
      }
    }

    // Seed events: 2 ticketed + 1 RSVP.
    $events = [];
    $now = new \DateTime('now', new \DateTimeZone('Australia/Sydney'));

    // Event 1: Ticketed event.
    $event1 = $this->createTicketedEvent($vendor, $store, $vendorUser, $vendorLabel, 1, $now);
    if ($event1) {
      $events[] = $event1;
    }

    // Event 2: Ticketed event.
    $event2 = $this->createTicketedEvent($vendor, $store, $vendorUser, $vendorLabel, 2, $now);
    if ($event2) {
      $events[] = $event2;
    }

    // Event 3: RSVP event.
    $event3 = $this->createRsvpEvent($vendor, $store, $vendorUser, $vendorLabel, 3, $now);
    if ($event3) {
      $events[] = $event3;
    }

    return [
      'vendor' => $vendorLabel,
      'user_id' => $vendorUser->id(),
      'vendor_id' => $vendor->id(),
      'store_id' => $store->id(),
      'events' => $events,
    ];
  }

  /**
   * Creates a ticketed (paid) event.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store entity.
   * @param \Drupal\user\Entity\User $vendorUser
   *   The vendor user.
   * @param string $vendorLabel
   *   Vendor label for naming.
   * @param int $eventNum
   *   Event number (1, 2, etc.).
   * @param \DateTime $baseTime
   *   Base time for calculating event dates.
   *
   * @return array|null
   *   Event summary data, or NULL on failure.
   */
  private function createTicketedEvent($vendor, $store, User $vendorUser, string $vendorLabel, int $eventNum, \DateTime $baseTime): ?array {
    $logger = $this->loggerFactory->get('myeventlane_seed');
    $sydneyLocations = [
      ['name' => 'Sydney Opera House', 'street' => 'Bennelong Point', 'city' => 'Sydney', 'postcode' => '2000'],
      ['name' => 'Darling Harbour', 'street' => 'Darling Harbour', 'city' => 'Sydney', 'postcode' => '2000'],
      ['name' => 'Bondi Beach', 'street' => 'Bondi Beach', 'city' => 'Bondi', 'postcode' => '2026'],
      ['name' => 'The Rocks', 'street' => 'George Street', 'city' => 'The Rocks', 'postcode' => '2000'],
      ['name' => 'Royal Botanic Gardens', 'street' => 'Mrs Macquaries Rd', 'city' => 'Sydney', 'postcode' => '2000'],
      ['name' => 'Hyde Park', 'street' => 'Elizabeth Street', 'city' => 'Sydney', 'postcode' => '2000'],
    ];
    $location = $sydneyLocations[($vendor->id() * 10 + $eventNum) % count($sydneyLocations)];

    // Event starts in 30-90 days from now.
    $daysAhead = 30 + ($eventNum * 15);
    $eventStart = clone $baseTime;
    $eventStart->modify("+{$daysAhead} days");
    // 6 PM
    $eventStart->setTime(18, 0);

    $eventEnd = clone $eventStart;
    $eventEnd->modify('+3 hours');

    $title = "$vendorLabel Event $eventNum - Ticketed";
    $nid = $this->createEventNode($vendor, $store, $vendorUser, $title, $location, $eventStart, $eventEnd, 'paid', 100);
    if (!$nid) {
      return NULL;
    }

    /** @var \Drupal\node\NodeInterface $event */
    $event = $this->entityTypeManager->getStorage('node')->load($nid);

    // Create product and variations.
    $product = $this->createTicketProduct($event, $store, $vendorUser, $title);
    if (!$product) {
      $event->delete();
      return NULL;
    }

    // Create at least 2 variations.
    $variations = [];
    $variation1 = $this->createTicketVariation($product, 'General Admission', 75.00, 'AUD', 100);
    $variation2 = $this->createTicketVariation($product, 'VIP', 150.00, 'AUD', 50);
    if ($variation1) {
      $variations[] = $variation1->id();
    }
    if ($variation2) {
      $variations[] = $variation2->id();
    }

    // Link product to event.
    $event->set('field_product_target', ['target_id' => $product->id()]);
    $event->save();

    return [
      'event_nid' => $nid,
      'type' => 'paid',
      'product_id' => $product->id(),
      'variation_ids' => $variations,
    ];
  }

  /**
   * Creates an RSVP (free) event.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store entity.
   * @param \Drupal\user\Entity\User $vendorUser
   *   The vendor user.
   * @param string $vendorLabel
   *   Vendor label for naming.
   * @param int $eventNum
   *   Event number (3 for RSVP).
   * @param \DateTime $baseTime
   *   Base time for calculating event dates.
   *
   * @return array|null
   *   Event summary data, or NULL on failure.
   */
  private function createRsvpEvent($vendor, $store, User $vendorUser, string $vendorLabel, int $eventNum, \DateTime $baseTime): ?array {
    $sydneyLocations = [
      ['name' => 'Sydney Opera House', 'street' => 'Bennelong Point', 'city' => 'Sydney', 'postcode' => '2000'],
      ['name' => 'Darling Harbour', 'street' => 'Darling Harbour', 'city' => 'Sydney', 'postcode' => '2000'],
      ['name' => 'Bondi Beach', 'street' => 'Bondi Beach', 'city' => 'Bondi', 'postcode' => '2026'],
    ];
    $location = $sydneyLocations[($vendor->id() * 10 + $eventNum) % count($sydneyLocations)];

    $daysAhead = 30 + ($eventNum * 15);
    $eventStart = clone $baseTime;
    $eventStart->modify("+{$daysAhead} days");
    // 2 PM
    $eventStart->setTime(14, 0);

    $eventEnd = clone $eventStart;
    $eventEnd->modify('+4 hours');

    $title = "$vendorLabel Event $eventNum - RSVP";
    $nid = $this->createEventNode($vendor, $store, $vendorUser, $title, $location, $eventStart, $eventEnd, 'rsvp', 50);
    if (!$nid) {
      return NULL;
    }

    /** @var \Drupal\node\NodeInterface $event */
    $event = $this->entityTypeManager->getStorage('node')->load($nid);

    // RSVP events don't need products, but we can create a $0 product for consistency if needed.
    // For now, we'll leave product empty for RSVP events.
    return [
      'event_nid' => $nid,
      'type' => 'rsvp',
      'product_id' => NULL,
    ];
  }

  /**
   * Creates an event node.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store entity.
   * @param \Drupal\user\Entity\User $vendorUser
   *   The vendor user.
   * @param string $title
   *   Event title.
   * @param array $location
   *   Location data: ['name' => string, 'street' => string, 'city' => string, 'postcode' => string].
   * @param \DateTime $start
   *   Event start date/time.
   * @param \DateTime $end
   *   Event end date/time.
   * @param string $eventType
   *   Event type: 'paid' or 'rsvp'.
   * @param int $capacity
   *   Event capacity.
   *
   * @return int|null
   *   The node ID, or NULL on failure.
   */
  private function createEventNode($vendor, $store, User $vendorUser, string $title, array $location, \DateTime $start, \DateTime $end, string $eventType, int $capacity): ?int {
    $storage = $this->entityTypeManager->getStorage('node');

    /** @var \Drupal\node\NodeInterface $event */
    $event = $storage->create([
      'type' => 'event',
      'title' => $title,
      'uid' => $vendorUser->id(),
      'status' => 1,
      'field_event_type' => $eventType,
      'field_event_vendor' => ['target_id' => $vendor->id()],
      'field_event_store' => ['target_id' => $store->id()],
      'field_event_start' => $start->format('Y-m-d\TH:i:s'),
      'field_event_end' => $end->format('Y-m-d\TH:i:s'),
      'field_capacity' => $capacity,
      'field_venue_name' => $location['name'],
    ]);

    // Set location address.
    if ($event->hasField('field_location')) {
      $event->set('field_location', [
        'country_code' => 'AU',
        'address_line1' => $location['street'],
        'locality' => $location['city'],
        'postal_code' => $location['postcode'],
      ]);
    }

    // Create event image.
    if ($event->hasField('field_event_image')) {
      $image = $this->imageFactoryUtil->createPlaceholderImage(
        'event_' . $vendor->id() . '_' . substr($title, -10),
        1200,
        630,
        substr($title, 0, 30),
        'events'
      );
      if ($image) {
        $event->set('field_event_image', [
          'target_id' => $image->id(),
          'alt' => $title . ' Image',
        ]);
      }
    }

    $event->save();
    return (int) $event->id();
  }

  /**
   * Creates a ticket product for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store entity.
   * @param \Drupal\user\Entity\User $vendorUser
   *   The vendor user.
   * @param string $title
   *   Product title.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface|null
   *   The product entity, or NULL on failure.
   */
  private function createTicketProduct($event, $store, User $vendorUser, string $title): ?Product {
    $productStorage = $this->entityTypeManager->getStorage('commerce_product');

    $product = Product::create([
      'type' => 'ticket',
      'title' => $title . ' - Tickets',
      'stores' => [$store->id()],
      'status' => 1,
      'uid' => $vendorUser->id(),
    ]);

    // Link product to event if field exists.
    if ($product->hasField('field_event')) {
      $product->set('field_event', ['target_id' => $event->id()]);
    }

    $product->save();
    return $product;
  }

  /**
   * Creates a ticket product variation.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product entity.
   * @param string $title
   *   Variation title.
   * @param float $price
   *   Price amount.
   * @param string $currency
   *   Currency code (e.g., 'AUD').
   * @param int $stock
   *   Stock quantity.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface|null
   *   The variation entity, or NULL on failure.
   */
  private function createTicketVariation($product, string $title, float $price, string $currency, int $stock): ?ProductVariation {
    $variationStorage = $this->entityTypeManager->getStorage('commerce_product_variation');

    $sku = 'TICKET-' . $product->id() . '-' . substr(md5($title), 0, 8);

    $variation = ProductVariation::create([
      'type' => 'ticket_variation',
      'sku' => $sku,
      'title' => $title,
      'status' => 1,
      'price' => new Price(number_format($price, 2, '.', ''), $currency),
      'product_id' => $product->id(),
    ]);

    // Set stock if field exists.
    if ($variation->hasField('field_stock')) {
      $variation->set('field_stock', $stock);
    }

    $variation->save();

    // Add variation to product.
    $variations = $product->getVariations();
    $variations[] = $variation;
    $product->set('variations', $variations);
    $product->save();

    return $variation;
  }

}
