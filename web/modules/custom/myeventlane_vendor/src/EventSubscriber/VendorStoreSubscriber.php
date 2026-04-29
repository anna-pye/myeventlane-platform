<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\EventSubscriber;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber to auto-create Commerce Store when Vendor is created.
 *
 * Also keeps the user account {@see field_vendor_store} in sync with the
 * vendor profile store and ensures linkage on login and key vendor routes.
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
   * The current user (for request subscriber).
   */
  private AccountProxyInterface $currentUser;

  /**
   * Track vendors being processed to prevent recursion.
   *
   * @var array
   */
  private static array $processing = [];

  /**
   * Prevent re-entry when syncing user store reference.
   *
   * @var array<int, bool>
   */
  private static array $ensureUserStoreRunning = [];

  /**
   * Constructs a VendorStoreSubscriber.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\myeventlane_core\Service\OnboardingManager $onboarding_manager
   *   Vendor onboarding.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user account proxy.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    OnboardingManager $onboarding_manager,
    AccountProxyInterface $current_user,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->onboardingManager = $onboarding_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'entity.myeventlane_vendor.insert' => 'onVendorInsert',
      KernelEvents::REQUEST => ['onKernelRequest', 100],
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

    if (!$vendor->hasField('field_vendor_store')) {
      $logger->error('Vendor @id is missing field_vendor_store.', [
        '@id' => (string) $vendor->id(),
      ]);
      return;
    }
    if (!$vendor->get('field_vendor_store')->isEmpty()) {
      $logger->notice('Vendor @id already has a store, skipping', ['@id' => $vendor->id()]);
      return;
    }

    $logger->notice('Proceeding to create store for vendor @id', ['@id' => $vendor->id()]);

    $this->createAndPersistStoreForVendor($vendor);
  }

  /**
   * Ensures vendor users have a linked store when the user store field is empty.
   */
  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || \PHP_SAPI === 'cli') {
      return;
    }

    if (!$this->currentUser->isAuthenticated()) {
      return;
    }

    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return;
    }

    if (!in_array('vendor', $user->getRoles(), TRUE)) {
      return;
    }

    if (!$user->hasField('field_vendor_store')) {
      return;
    }

    if (!$user->get('field_vendor_store')->isEmpty()) {
      return;
    }

    $this->ensureLinkedStoreForVendorUser($user);
  }

  /**
   * Returns the vendor's store, or creates one when onboarding is complete.
   *
   * Used for Stripe Connect and other flows that require a store without
   * falling back to the platform default or another user's store.
   */
  public function ensureStoreForVendor(Vendor $vendor): ?StoreInterface {
    if (!$vendor->hasField('field_vendor_store')) {
      $this->loggerFactory->get('myeventlane_vendor')->error('Vendor @id is missing field_vendor_store.', [
        '@id' => (string) $vendor->id(),
      ]);
      return NULL;
    }

    if (!$vendor->get('field_vendor_store')->isEmpty()) {
      $entity = $vendor->get('field_vendor_store')->entity;
      if ($entity instanceof StoreInterface) {
        $owner = $vendor->getOwner();
        if ($owner instanceof UserInterface) {
          $this->syncUserVendorStoreFromVendor($owner, $vendor);
        }
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
   * Ensures the vendor role account has a Commerce store reference on the user.
   *
   * Relies on the vendor entity store field as the source of truth.
   */
  public function ensureLinkedStoreForVendorUser(UserInterface $user): void {
    if (!$user->hasField('field_vendor_store')) {
      return;
    }
    $uid = (int) $user->id();
    if ($uid <= 0) {
      return;
    }
    if (!in_array('vendor', $user->getRoles(), TRUE)) {
      return;
    }
    if (isset(self::$ensureUserStoreRunning[$uid])) {
      return;
    }
    self::$ensureUserStoreRunning[$uid] = TRUE;
    try {
      $vendor = $this->loadPrimaryVendorForUser($uid);
      if ($vendor === NULL) {
        return;
      }
      $this->ensureStoreForVendor($vendor);
      $this->syncUserVendorStoreFromVendor($user, $vendor);
    }
    finally {
      unset(self::$ensureUserStoreRunning[$uid]);
    }
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
        // Commerce Tax: Australian GST only applies when the store is registered
        // to collect tax in AU (see LocalTaxTypeBase::matchesRegistrations()).
        'tax_registrations' => ['AU'],
        // Align with Australian GST config (display inclusive) and ticket face prices.
        'prices_include_tax' => TRUE,
        'is_default' => FALSE,
        'status' => TRUE,
      ]);

      $store->set('field_vendor_reference', $vendor);
      $store->save();

      $vendor->set('field_vendor_store', $store);
      $vendor->save();

      unset(self::$processing[$vendor->id()]);

      if ($owner instanceof UserInterface && $owner->hasField('field_vendor_store')) {
        $this->syncUserVendorStoreFromVendor($owner, $vendor);
      }

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
      return NULL;
    }
  }

  /**
   * Copies the vendor's store target to the user account field when present.
   */
  private function syncUserVendorStoreFromVendor(UserInterface $user, Vendor $vendor): void {
    if (!$user->hasField('field_vendor_store')) {
      return;
    }
    if (!$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      return;
    }
    $sid = $vendor->get('field_vendor_store')->target_id;
    if ($sid === NULL) {
      return;
    }
    $current = $user->get('field_vendor_store')->isEmpty()
      ? NULL
      : (int) $user->get('field_vendor_store')->target_id;
    if ($current === (int) $sid) {
      return;
    }
    try {
      $user->set('field_vendor_store', $sid);
      $user->save();
    }
    catch (EntityStorageException $e) {
      $this->loggerFactory->get('myeventlane_vendor')->error(
        'Could not sync user @uid field_vendor_store from vendor @vid: @message',
        [
          '@uid' => (string) $user->id(),
          '@vid' => (string) $vendor->id(),
          '@message' => $e->getMessage(),
        ]
      );
    }
  }

  /**
   * Loads the primary vendor for an account (owner match preferred).
   */
  private function loadPrimaryVendorForUser(int $uid): ?Vendor {
    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');

    $owner_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();

    if (!empty($owner_ids)) {
      $vendor = $storage->load(reset($owner_ids));
      if ($vendor instanceof Vendor) {
        return $vendor;
      }
    }

    $user_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_vendor_users', $uid)
      ->range(0, 1)
      ->execute();

    if (!empty($user_ids)) {
      $vendor = $storage->load(reset($user_ids));
      if ($vendor instanceof Vendor) {
        return $vendor;
      }
    }

    return NULL;
  }

}
