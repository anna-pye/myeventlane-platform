<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_core\Entity\OnboardingStateInterface;
use Drupal\myeventlane_core\OnboardingStateStorage;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\user\UserInterface;

/**
 * Onboarding state manager.
 *
 * Loads or creates onboarding state per track, refreshes flags, advances stage.
 */
final class OnboardingManager {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  private LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Current user (optional).
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|null
   */
  private ?AccountProxyInterface $currentUser;

  /**
   * Time service (optional).
   *
   * @var \Drupal\Component\Datetime\TimeInterface|null
   */
  private ?TimeInterface $time;

  /**
   * Whether we have already logged duplicate state this request.
   *
   * @var array<string, true>
   */
  private static array $duplicateLogged = [];

  /**
   * Constructs the manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    ?AccountProxyInterface $current_user = NULL,
    ?TimeInterface $time = NULL,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->currentUser = $current_user;
    $this->time = $time;
  }

  /**
   * Loads or creates customer onboarding state.
   *
   * Uses newest by uid + track=customer.
   */
  public function loadOrCreateCustomer(UserInterface $user): OnboardingStateInterface {
    $storage = $this->getStorage();
    $uid = (int) $user->id();
    $existing = $storage->loadByProperties([
      'uid' => $uid,
      'track' => OnboardingStateInterface::TRACK_CUSTOMER,
    ]);
    if (!empty($existing)) {
      $state = reset($existing);
      if (is_array($state)) {
        $state = reset($state);
      }
      if ($state instanceof OnboardingStateInterface) {
        $this->logDuplicateOnce('customer', (string) $uid);
        return $state;
      }
    }
    $state = $storage->create([
      'uid' => $uid,
      'track' => OnboardingStateInterface::TRACK_CUSTOMER,
      'stage' => 'probe',
      'completed' => FALSE,
    ]);
    $state->setOwnerId($uid);
    $state->setFlags([
      'has_account' => TRUE,
      'has_orders' => $this->customerHasOrders($uid),
    ]);
    $state->save();
    return $state;
  }

  /**
   * Loads the latest (newest by id) onboarding state for a vendor.
   *
   * Returns a single state deterministically. Logs a one-time warning if
   * multiple states exist for the same vendor_id.
   *
   * @param int $vendor_id
   *   The vendor entity ID.
   *
   * @return \Drupal\myeventlane_core\Entity\OnboardingStateInterface|null
   *   The newest state, or NULL if none exists.
   */
  public function loadLatestStateForVendor(int $vendor_id): ?OnboardingStateInterface {
    $storage = $this->getStorage();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vendor_id', $vendor_id)
      ->condition('track', OnboardingStateInterface::TRACK_VENDOR)
      ->sort('id', 'DESC')
      ->execute();
    if (empty($ids)) {
      return NULL;
    }
    $count = count($ids);
    if ($count > 1) {
      $this->logDuplicateOnce('vendor', (string) $vendor_id);
    }
    $newest_id = reset($ids);
    $state = $storage->load($newest_id);
    return $state instanceof OnboardingStateInterface ? $state : NULL;
  }

  /**
   * Loads the latest (newest by id) vendor onboarding state for a user.
   *
   * This supports "pre-vendor" onboarding where vendor_id may be NULL.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return \Drupal\myeventlane_core\Entity\OnboardingStateInterface|null
   *   The newest vendor-track state for this user, or NULL if none exists.
   */
  public function loadVendorStateByUid(int $uid): ?OnboardingStateInterface {
    if ($uid <= 0) {
      return NULL;
    }

    $storage = $this->getStorage();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('track', OnboardingStateInterface::TRACK_VENDOR)
      ->sort('id', 'DESC')
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    $count = count($ids);
    if ($count > 1) {
      $this->logDuplicateOnce('vendor_uid', (string) $uid);
    }

    $newest_id = reset($ids);
    $state = $storage->load($newest_id);
    return $state instanceof OnboardingStateInterface ? $state : NULL;
  }

  /**
   * Creates a vendor-track onboarding state for a user (pre-vendor).
   *
   * Creates a vendor-track state with vendor_id = NULL (allowed while
   * in-progress). Does not create vendor entities or grant permissions/roles.
   *
   * @param int $uid
   *   The user ID (must be authenticated).
   *
   * @return \Drupal\myeventlane_core\Entity\OnboardingStateInterface
   *   The created state.
   */
  public function createVendorStateForUid(int $uid): OnboardingStateInterface {
    if ($uid <= 0) {
      throw new \InvalidArgumentException('createVendorStateForUid requires an authenticated uid.');
    }

    $storage = $this->getStorage();
    $state = $storage->create([
      'uid' => $uid,
      'track' => OnboardingStateInterface::TRACK_VENDOR,
      'stage' => 'probe',
      'completed' => FALSE,
    ]);
    $state->setOwnerId($uid);
    $state->setFlags([]);
    $state->save();
    return $state;
  }

  /**
   * Loads or creates vendor onboarding state (idempotent).
   *
   * If a state already exists for vendor_id, returns it. Otherwise creates one.
   */
  public function loadOrCreateVendor(UserInterface $user, Vendor $vendor): OnboardingStateInterface {
    $storage = $this->getStorage();
    $vid = (int) $vendor->id();
    $uid = (int) $user->id();
    $existing = $this->loadLatestStateForVendor($vid);
    if ($existing !== NULL) {
      return $existing;
    }
    $store_id = NULL;
    if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
      if ($store !== NULL) {
        $store_id = (int) $store->id();
      }
    }
    $state = $storage->create([
      'uid' => $uid,
      'track' => OnboardingStateInterface::TRACK_VENDOR,
      'stage' => 'probe',
      'completed' => FALSE,
      'vendor_id' => $vid,
      'store_id' => $store_id,
    ]);
    $state->setOwnerId($uid);
    $state->setVendorId($vid);
    $state->setStoreId($store_id);
    $state->setFlags($this->computeVendorFlags($vendor));
    $state->save();
    return $state;
  }

  /**
   * Ensures a Vendor entity exists for the given account (idempotent).
   *
   * If a vendor already exists (by uid or field_vendor_users), returns it.
   * Otherwise creates, saves, and returns a new vendor.
   *
   * Used at onboarding entry to bootstrap vendor state before Step 2+ run,
   * so VendorContext and access gates can safely assume vendor existence.
   *
   * Does NOT assume Store, Stripe, or events exist.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account (must be authenticated).
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor
   *   The existing or newly created vendor.
   */
  public function ensureVendorExists(AccountInterface $account): Vendor {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      throw new \InvalidArgumentException('ensureVendorExists requires an authenticated account.');
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');

    // Check vendors where user is owner (uid).
    $owner_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();

    // Check vendors where user is in field_vendor_users.
    $users_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_vendor_users', $uid)
      ->execute();

    $all_ids = array_merge($owner_ids ?: [], $users_ids ?: []);
    $vendor_ids = array_values(array_unique(array_map('intval', $all_ids)));

    if (!empty($vendor_ids)) {
      $vendor = $storage->load(reset($vendor_ids));
      if ($vendor instanceof Vendor) {
        return $vendor;
      }
    }

    // Create and save new vendor. Name is required; use placeholder for form.
    $vendor = Vendor::create([
      'uid' => $uid,
      'name' => 'Organiser',
    ]);
    $vendor->save();
    return $vendor;
  }

  /**
   * Ensures vendor-console access for the given account (idempotent).
   *
   * Vendor console access is guarded by VendorConsoleAccess, which requires the
   * user to have the 'access vendor console' permission (or be an admin).
   *
   * This method is the single authoritative place to ensure the correct role
   * is granted during onboarding.
   *
   * Call at Step 2 profile submit (Option B) when the user has committed to
   * onboarding, after the Vendor entity is saved and before redirecting.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account (must be authenticated).
   */
  public function ensureVendorAccess(AccountInterface $account): void {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      throw new \InvalidArgumentException('ensureVendorAccess requires an authenticated account.');
    }

    $required_permission = 'access vendor console';
    $role_to_grant = 'vendor';

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return;
    }

    // If the user already has vendor console permission (via any role),
    // do nothing.
    if ($user->hasPermission($required_permission)) {
      return;
    }

    // Ensure the vendor role grants the required permission.
    // This is safe and idempotent, and avoids relying on missing config-sync.
    $role = $this->entityTypeManager->getStorage('user_role')->load($role_to_grant);
    if ($role !== NULL) {
      $perms = $role->getPermissions();
      if (!in_array($required_permission, $perms, TRUE)) {
        $perms[] = $required_permission;
        $role->set('permissions', $perms);
        $role->save();
        $this->loggerFactory->get('myeventlane_onboarding')->warning('vendor role missing required permission; patched role=@role perm=@perm', [
          '@role' => $role_to_grant,
          '@perm' => $required_permission,
        ]);
      }
    }

    // Grant the vendor role and persist.
    if (!$user->hasRole($role_to_grant)) {
      $user->addRole($role_to_grant);
    }
    $user->save();
    $this->loggerFactory->get('myeventlane_onboarding')->info('vendor access granted uid=@uid role=@role', [
      '@uid' => $uid,
      '@role' => $role_to_grant,
    ]);
  }

  /**
   * Refreshes flags on the given state and saves.
   */
  public function refreshFlags(OnboardingStateInterface $state): OnboardingStateInterface {
    $flags = $state->getFlags();
    if ($state->getTrack() === OnboardingStateInterface::TRACK_VENDOR && $state->getVendorId() !== NULL) {
      $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($state->getVendorId());
      if ($vendor instanceof Vendor) {
        $flags = $this->computeVendorFlags($vendor);
        $state->setFlags($flags);
        $store_id = NULL;
        if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
          $store = $vendor->get('field_vendor_store')->entity;
          if ($store !== NULL) {
            $store_id = (int) $store->id();
            $state->setStoreId($store_id);
          }
        }
      }
    }
    elseif ($state->getTrack() === OnboardingStateInterface::TRACK_CUSTOMER) {
      $uid = $state->getOwnerId();
      if ($uid !== NULL) {
        $state->setFlags(array_merge($flags, [
          'has_account' => TRUE,
          'has_orders' => $this->customerHasOrders($uid),
        ]));
      }
    }
    $state->save();
    return $state;
  }

  /**
   * Advances stage; no-op and log warning if stage is invalid or regression.
   */
  public function advanceStage(OnboardingStateInterface $state, string $stage): OnboardingStateInterface {
    if ($stage === '' || !in_array($stage, OnboardingStateInterface::STAGE_ORDER, TRUE)) {
      $this->logger()->warning('advanceStage ignored invalid stage: @stage', ['@stage' => $stage]);
      return $state;
    }
    $current = $state->getStage();
    $current_idx = array_search($current, OnboardingStateInterface::STAGE_ORDER, TRUE);
    $new_idx = array_search($stage, OnboardingStateInterface::STAGE_ORDER, TRUE);
    if ($current_idx !== FALSE && $new_idx !== FALSE && $new_idx <= $current_idx) {
      $this->logger()->warning('advanceStage ignored regression: current=@c new=@n', [
        '@c' => $current,
        '@n' => $stage,
      ]);
      return $state;
    }
    $state->setStage($stage);
    $state->save();
    return $state;
  }

  /**
   * Whether the given state is completed.
   */
  public function isCompleted(OnboardingStateInterface $state): bool {
    return $state->isCompleted();
  }

  /**
   * Whether vendor is invite-ready (all Ask steps done, can proceed to Review).
   *
   * Aligns with OnboardingNavigator: organiser done, has events, tickets,
   * stripe connected. When TRUE, the "Resume setup" panel should be hidden.
   */
  public function isInviteReady(OnboardingStateInterface $state): bool {
    if ($state->isCompleted()) {
      return TRUE;
    }
    if ($state->getTrack() !== OnboardingStateInterface::TRACK_VENDOR) {
      return FALSE;
    }
    $this->refreshFlags($state);
    $flags = $state->getFlags();
    $stage = $state->getStage();
    if (in_array($stage, ['probe', 'present'], TRUE)) {
      return FALSE;
    }
    return !empty($flags['has_events'])
      && !empty($flags['has_tickets'])
      && !empty($flags['stripe_connected']);
  }

  /**
   * Returns the canonical vendor onboarding route for the next step.
   *
   * Used for "Resume setup" links and redirects. Never returns deprecated
   * onboarding routes.
   *
   * @param \Drupal\myeventlane_core\Entity\OnboardingStateInterface|null $state
   *   Vendor onboarding state, or NULL.
   *
   * @return string|null
   *   Route name for next vendor step, or NULL if complete.
   *   When state is NULL, returns create_event_gateway.
   */
  public function getNextVendorOnboardRoute(?OnboardingStateInterface $state): ?string {
    if ($state === NULL) {
      return 'myeventlane_vendor.create_event_gateway';
    }
    $next = $this->getNextAction($state);
    return $next['route_name'] ?: NULL;
  }

  /**
   * Returns next action for authenticated users with vendor.
   *
   * Never returns account route.
   *
   * Use this when building onboarding panel CTA for logged-in vendors.
   * The account route is for anonymous users only; authenticated users
   * must never be sent there (access denied).
   *
   * @param \Drupal\myeventlane_core\Entity\OnboardingStateInterface|null $state
   *   Vendor onboarding state, or NULL.
   *
   * @return array{route_name: string|null, title: string}
   *   Next action. When state is NULL, returns profile. When stage is probe,
   *   returns profile instead of account.
   */
  public function getNextActionForAuthenticatedVendor(?OnboardingStateInterface $state): array {
    if ($state === NULL) {
      return ['route_name' => 'myeventlane_vendor.onboard.profile', 'title' => 'Set up profile'];
    }
    $next = $this->getNextAction($state);
    if (($next['route_name'] ?? '') === 'myeventlane_vendor.onboard.account') {
      return ['route_name' => 'myeventlane_vendor.onboard.profile', 'title' => 'Set up profile'];
    }
    return $next;
  }

  /**
   * Returns the canonical vendor onboarding route for authenticated users.
   *
   * Never returns account route. Use for panel CTA when user is logged in.
   *
   * @param \Drupal\myeventlane_core\Entity\OnboardingStateInterface|null $state
   *   Vendor onboarding state, or NULL.
   *
   * @return string|null
   *   Route name for next step, or NULL if complete.
   */
  public function getNextVendorOnboardRouteForAuthenticated(?OnboardingStateInterface $state): ?string {
    $next = $this->getNextActionForAuthenticatedVendor($state);
    return $next['route_name'] ?: NULL;
  }

  /**
   * Returns next action as { route_name: string|null, title: string }.
   *
   * Does not guess route names; uses known vendor onboarding routes.
   */
  public function getNextAction(OnboardingStateInterface $state): array {
    $stage = $state->getStage();
    $track = $state->getTrack();
    if ($state->isCompleted()) {
      return ['route_name' => NULL, 'title' => ''];
    }
    if ($track === OnboardingStateInterface::TRACK_VENDOR) {
      $map = [
        'probe' => ['route_name' => 'myeventlane_vendor.onboard.account', 'title' => 'Create account'],
        'present' => ['route_name' => 'myeventlane_vendor.onboard.profile', 'title' => 'Set up profile'],
        'listen' => ['route_name' => 'myeventlane_vendor.onboard.stripe', 'title' => 'Set up payments'],
        'ask' => ['route_name' => 'myeventlane_vendor.onboard.first_event', 'title' => 'Create first event'],
        'invite' => ['route_name' => 'myeventlane_vendor.onboard.boost', 'title' => 'Promote with Boost'],
        'complete' => ['route_name' => NULL, 'title' => ''],
      ];
      return $map[$stage] ?? ['route_name' => NULL, 'title' => ''];
    }
    return ['route_name' => NULL, 'title' => ''];
  }

  /**
   * Gets onboarding state storage.
   */
  private function getStorage(): OnboardingStateStorage {
    $storage = $this->entityTypeManager->getStorage('myeventlane_onboarding_state');
    assert($storage instanceof OnboardingStateStorage);
    return $storage;
  }

  /**
   * Logs duplicate state once per (track, key) per request.
   */
  private function logDuplicateOnce(string $track, string $key): void {
    $k = $track . ':' . $key;
    if (!isset(self::$duplicateLogged[$k])) {
      self::$duplicateLogged[$k] = TRUE;
      $this->logger()->info('Multiple onboarding states for @track @key; using newest.', [
        '@track' => $track,
        '@key' => $key,
      ]);
    }
  }

  /**
   * Gets the onboarding logger.
   */
  private function logger(): LoggerChannelInterface {
    return $this->loggerFactory->get('myeventlane_onboarding');
  }

  /**
   * Computes vendor flags from vendor entity.
   *
   * Flags: has_vendor, has_store, stripe_connected, has_events, has_tickets.
   * Has_tickets: at least one vendor event has ticket product or ticket types.
   */
  private function computeVendorFlags(Vendor $vendor): array {
    $flags = ['has_vendor' => TRUE];
    $store = NULL;
    if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
    }
    $flags['has_store'] = $store !== NULL;
    $flags['stripe_connected'] = FALSE;
    if ($store !== NULL && $store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
      $flags['stripe_connected'] = !empty(trim((string) $store->get('field_stripe_account_id')->value));
    }
    $vid = (int) $vendor->id();
    $flags['has_events'] = $this->vendorHasEvents($vid);
    $flags['has_tickets'] = $this->vendorHasTickets($vid);
    return $flags;
  }

  /**
   * Whether vendor has at least one event with tickets (product or types).
   *
   * Uses field_product_target or field_ticket_types on node.event.
   */
  private function vendorHasTickets(int $vendor_id): bool {
    try {
      $event_ids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('field_event_vendor', $vendor_id)
        ->range(0, 10)
        ->execute();
      if (empty($event_ids)) {
        return FALSE;
      }
      foreach ($event_ids as $eid) {
        $event = $this->entityTypeManager->getStorage('node')->load($eid);
        if ($event === NULL) {
          continue;
        }
        if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
          return TRUE;
        }
        if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
          return TRUE;
        }
      }
      return FALSE;
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

  /**
   * Whether vendor has at least one event (published or any).
   *
   * Uses field_event_vendor on node.event; any status.
   */
  private function vendorHasEvents(int $vendor_id): bool {
    try {
      $count = (int) $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('field_event_vendor', $vendor_id)
        ->range(0, 1)
        ->count()
        ->execute();
      return $count > 0;
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

  /**
   * Whether customer has completed orders.
   */
  private function customerHasOrders(int $uid): bool {
    try {
      if (!$this->entityTypeManager->getStorage('commerce_order')->getEntityType()->hasKey('id')) {
        return FALSE;
      }
      $count = (int) $this->entityTypeManager->getStorage('commerce_order')->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uid)
        ->condition('state', 'completed')
        ->range(0, 1)
        ->count()
        ->execute();
      return $count > 0;
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

}
