<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\EventSubscriber;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Entity\EntityStorageException;

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
   * The onboarding state manager.
   */
  private OnboardingManager $onboardingManager;

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
    OnboardingManager $onboarding_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->onboardingManager = $onboarding_manager;
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

    $owner = $vendor->getOwner();
    if ($owner !== NULL) {
      $state = $this->onboardingManager->loadVendorStateByUid((int) $owner->id());
      if ($state !== NULL && !$this->onboardingManager->isCompleted($state)) {
        $logger->notice('Store creation skipped (onboarding incomplete) vendor=@id', [
          '@id' => (string) $vendor->id(),
        ]);
        return;
      }
    }

    // Prevent recursion if we're already processing this vendor.
    if (isset(self::$processing[$vendor->id()])) {
      $logger->notice('Vendor @id already being processed, skipping', ['@id' => $vendor->id()]);
      return;
    }

    // Only proceed if vendor doesn't already have a store.
    if (!$vendor->get('field_vendor_store')->isEmpty()) {
      $logger->notice('Vendor @id already has a store, skipping', ['@id' => $vendor->id()]);
      return;
    }

    $logger->notice('Proceeding to create store for vendor @id', ['@id' => $vendor->id()]);

    $this->createAndPersistStoreForVendor($vendor);
  }

  /**
   * Returns the vendor's store, or creates one when onboarding is complete.
   *
   * Used for Stripe Connect and other flows that require a store without
   * falling back to the platform default or another user's store.
   */
  public function ensureStoreForVendor(Vendor $vendor): ?StoreInterface {
    if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $entity = $vendor->get('field_vendor_store')->entity;
      if ($entity instanceof StoreInterface) {
        return $entity;
      }
    }

    $owner = $vendor->getOwner();
    if ($owner !== NULL) {
      $state = $this->onboardingManager->loadVendorStateByUid((int) $owner->id());
      if ($state !== NULL && !$this->onboardingManager->isCompleted($state)) {
        $this->loggerFactory->get('myeventlane_vendor')->notice('Store ensure: onboarding incomplete, vendor @id', [
          '@id' => (string) $vendor->id(),
        ]);
        return NULL;
      }
    }

    if (isset(self::$processing[$vendor->id()])) {
      $this->loggerFactory->get('myeventlane_vendor')->notice('Store ensure: vendor @id already in progress', [
        '@id' => (string) $vendor->id(),
      ]);
      return NULL;
    }

    return $this->createAndPersistStoreForVendor($vendor);
  }

  /**
   * Creates and saves a Commerce store for a vendor, linking both entities.
   */
  private function createAndPersistStoreForVendor(Vendor $vendor): ?StoreInterface {
    self::$processing[$vendor->id()] = TRUE;
    $logger = $this->loggerFactory->get('myeventlane_vendor');
    $store = NULL;
    try {
      $store_storage = $this->entityTypeManager->getStorage('commerce_store');
      $owner = $vendor->getOwner();

      /** @var \Drupal\commerce_store\Entity\StoreInterface $store */
      $store = $store_storage->create([
        'type' => 'online',
        'uid' => $owner ? $owner->id() : 1,
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

      $store->set('field_vendor_reference', $vendor);
      $store->save();

      $vendor->set('field_vendor_store', $store);
      $vendor->save();

      unset(self::$processing[$vendor->id()]);

      return $store;
    }
    catch (EntityStorageException $e) {
      unset(self::$processing[$vendor->id()]);

      $logger->error(
        'Failed to create store for vendor @vendor: @message',
        [
          '@vendor' => $vendor->id(),
          '@message' => $e->getMessage(),
        ]
      );
    }

    return $store;
  }

}
