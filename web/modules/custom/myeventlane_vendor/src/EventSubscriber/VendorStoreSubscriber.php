<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\EventSubscriber;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber to auto-create Commerce Store when Vendor is created.
 */
final class VendorStoreSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  private LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Track vendors being processed to prevent recursion.
   *
   * @var array
   */
  private static array $processing = [];

  /**
   * Constructs a VendorStoreSubscriber.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'entity.myeventlane_vendor.insert' => 'onVendorInsert',
    ];
  }

  /**
   * Creates a Commerce Store when a Vendor is created.
   *
   * @param mixed $event
   *   The entity storage event.
   */
  public function onVendorInsert($event): void {
    /** @var \Drupal\myeventlane_vendor\Entity\Vendor $vendor */
    $vendor = $event->getEntity();
    $this->onVendorInsertFromHook($vendor);
  }

  /**
   * Creates a Commerce Store when a Vendor is created (called from hook).
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   */
  public function onVendorInsertFromHook(Vendor $vendor): void {
    $logger = $this->loggerFactory->get('myeventlane_vendor');
    $logger->notice('Vendor insert hook fired for vendor @id', ['@id' => $vendor->id()]);
    $this->ensureStoreForVendor($vendor);
  }

  /**
   * Ensures a vendor has exactly one linked Commerce store.
   */
  public function ensureStoreForVendor(Vendor $vendor): ?StoreInterface {
    $logger = $this->loggerFactory->get('myeventlane_vendor');

    if (!$vendor->hasField('field_vendor_store')) {
      $logger->error('Vendor @id is missing field_vendor_store.', [
        '@id' => (string) $vendor->id(),
      ]);
      return NULL;
    }

    // Prevent recursion if we're already processing this vendor.
    if (isset(self::$processing[$vendor->id()])) {
      $logger->notice('Vendor @id already being processed, skipping', ['@id' => $vendor->id()]);
      return $this->getLinkedStore($vendor);
    }

    // Only proceed if vendor doesn't already have a valid linked store.
    if (!$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
      if ($store instanceof StoreInterface) {
        $this->ensureStoreBackReference($store, $vendor);
        $logger->notice('Vendor @id already has a store, skipping', ['@id' => $vendor->id()]);
        return $store;
      }
    }

    $existing_store = $this->loadExistingStoreForVendor($vendor);
    if ($existing_store instanceof StoreInterface) {
      $vendor->set('field_vendor_store', $existing_store->id());
      $vendor->save();
      $logger->notice('Linked existing store @store to vendor @vendor.', [
        '@store' => (string) $existing_store->id(),
        '@vendor' => (string) $vendor->id(),
      ]);
      return $existing_store;
    }

    $logger->notice('Proceeding to create store for vendor @id', ['@id' => $vendor->id()]);

    // Mark as processing.
    self::$processing[$vendor->id()] = TRUE;

    try {
      $store_storage = $this->entityTypeManager->getStorage('commerce_store');
      $owner = $vendor->getOwner();
      $owner_id = $owner ? (int) $owner->id() : 1;

      // Create a new Commerce Store for this vendor.
      /** @var \Drupal\commerce_store\Entity\StoreInterface $store */
      $store = $store_storage->create([
        'type' => 'online',
        'uid' => $owner_id,
        'name' => $vendor->getName() . ' Store',
        'mail' => $owner && $owner->getEmail() ? $owner->getEmail() : 'noreply@myeventlane.com',
        'default_currency' => 'AUD',
        'timezone' => 'Australia/Sydney',
        'address' => [
          'country_code' => 'AU',
          'administrative_area' => '',
          'locality' => '',
          'postal_code' => '',
          'address_line1' => '',
          'organization' => $vendor->getName(),
        ],
        'billing_countries' => ['AU'],
        'is_default' => FALSE,
        'status' => TRUE,
      ]);

      // Link the store back to the vendor.
      $this->ensureStoreBackReference($store, $vendor, FALSE);
      $store->save();

      // Link the vendor to the store.
      $vendor->set('field_vendor_store', $store->id());
      // Save the vendor again to persist the store reference.
      // This won't trigger insert again since the entity is no longer new.
      $vendor->save();
      $this->loggerFactory->get('stripe_debug')->notice('Store created for user @uid', ['@uid' => (string) $owner_id]);

      // Unmark as processing.
      unset(self::$processing[$vendor->id()]);
      return $store;

    }
    catch (EntityStorageException $e) {
      // Unmark as processing on error.
      unset(self::$processing[$vendor->id()]);

      $this->loggerFactory->get('myeventlane_vendor')->error(
        'Failed to create store for vendor @vendor: @message',
        [
          '@vendor' => $vendor->id(),
          '@message' => $e->getMessage(),
        ]
      );
    }

    return NULL;
  }

  /**
   * Gets the vendor's currently linked store, if it is valid.
   */
  private function getLinkedStore(Vendor $vendor): ?StoreInterface {
    if (!$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      return NULL;
    }

    $store = $vendor->get('field_vendor_store')->entity;
    return $store instanceof StoreInterface ? $store : NULL;
  }

  /**
   * Loads an existing store before creating one.
   */
  private function loadExistingStoreForVendor(Vendor $vendor): ?StoreInterface {
    $store_storage = $this->entityTypeManager->getStorage('commerce_store');

    if ($vendor->id() !== NULL) {
      try {
        $store_ids = $store_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('field_vendor_reference', (int) $vendor->id())
          ->range(0, 1)
          ->execute();
        if (!empty($store_ids)) {
          $store = $store_storage->load(reset($store_ids));
          if ($store instanceof StoreInterface) {
            return $store;
          }
        }
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('myeventlane_vendor')->warning('Could not query stores by vendor reference for vendor @vendor: @message', [
          '@vendor' => (string) $vendor->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $owner = $vendor->getOwner();
    $owner_id = $owner ? (int) $owner->id() : 0;
    if ($owner_id <= 0) {
      return NULL;
    }

    $store_ids = $store_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $owner_id)
      ->execute();
    if (empty($store_ids)) {
      return NULL;
    }

    if (count($store_ids) > 1) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Multiple Commerce stores found for vendor owner uid @uid; linking the first store to vendor @vendor.', [
        '@uid' => (string) $owner_id,
        '@vendor' => (string) $vendor->id(),
      ]);
    }

    $store = $store_storage->load(reset($store_ids));
    if ($store instanceof StoreInterface) {
      $this->ensureStoreBackReference($store, $vendor);
    }

    return $store instanceof StoreInterface ? $store : NULL;
  }

  /**
   * Ensures the Commerce store can be discovered from the vendor side too.
   */
  private function ensureStoreBackReference(StoreInterface $store, Vendor $vendor, bool $save = TRUE): void {
    if (!$store->hasField('field_vendor_reference')) {
      return;
    }

    $current_target_id = !$store->get('field_vendor_reference')->isEmpty()
      ? (string) $store->get('field_vendor_reference')->target_id
      : '';
    if ($current_target_id === (string) $vendor->id()) {
      return;
    }

    if ($current_target_id !== '') {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Store @store is already linked to vendor @existing; not relinking it to vendor @vendor.', [
        '@store' => (string) $store->id(),
        '@existing' => $current_target_id,
        '@vendor' => (string) $vendor->id(),
      ]);
      return;
    }

    $store->set('field_vendor_reference', $vendor->id());
    if ($save) {
      $store->save();
    }
  }

}
