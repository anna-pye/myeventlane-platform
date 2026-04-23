<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
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
   * Lock backend (serialises vendor-track state creation per uid).
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  private LockBackendInterface $lock;

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
    LockBackendInterface $lock,
    ?AccountProxyInterface $current_user = NULL,
    ?TimeInterface $time = NULL,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->currentUser = $current_user;
    $this->time = $time;
    $this->lock = $lock;
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
    if ($vendor_id <= 0) {
      return NULL;
    }

    $storage = $this->getStorage();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vendor_id', $vendor_id)
      ->condition('track', OnboardingStateInterface::TRACK_VENDOR)
      ->execute();
    if (empty($ids)) {
      return NULL;
    }

    $entities = $storage->loadMultiple($ids);
    $sorted = $this->sortVendorTrackStatesNewestFirst($entities);
    $canonical = $sorted[0] ?? NULL;
    if (!$canonical instanceof OnboardingStateInterface) {
      return NULL;
    }

    if (count($sorted) > 1) {
      $this->logDuplicateOnce('vendor', (string) $vendor_id, count($sorted));
      $this->deleteOlderVendorTrackStatesAfterCanonical($canonical, array_slice($sorted, 1), 'vendor', (string) $vendor_id);
    }

    return $canonical;
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
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    $entities = $storage->loadMultiple($ids);
    $sorted = $this->sortVendorTrackStatesNewestFirst($entities);
    $canonical = $sorted[0] ?? NULL;
    if (!$canonical instanceof OnboardingStateInterface) {
      return NULL;
    }

    if (count($sorted) > 1) {
      $this->logDuplicateOnce('vendor_uid', (string) $uid, count($sorted));
      $this->deleteOlderVendorTrackStatesAfterCanonical($canonical, array_slice($sorted, 1), 'vendor_uid', (string) $uid);
    }

    return $canonical;
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

    $existing = $this->loadVendorStateByUid($uid);
    if ($existing !== NULL) {
      $this->logger()->notice('Using existing onboarding state uid=@uid', [
        '@uid' => (string) $uid,
      ]);
      return $existing;
    }

    $lock_name = 'myeventlane_onboarding_vendor_uid_' . (string) $uid;
    $acquired = $this->lock->acquire($lock_name, 30.0);
    if (!$acquired) {
      $this->lock->wait($lock_name, 30);
      $acquired = $this->lock->acquire($lock_name, 30.0);
    }
    if (!$acquired) {
      $this->logger()->warning('Onboarding vendor state lock not acquired uid=@uid after wait; reloading state.', ['@uid' => (string) $uid]);
      $existing = $this->loadVendorStateByUid($uid);
      if ($existing !== NULL) {
        $this->logger()->notice('Using existing onboarding state uid=@uid', [
          '@uid' => (string) $uid,
        ]);
        return $existing;
      }
      $this->logger()->error('Onboarding vendor state could not be created: lock unavailable uid=@uid', ['@uid' => (string) $uid]);
      throw new \RuntimeException('Could not acquire lock to create vendor onboarding state.');
    }
    try {
      $existing = $this->loadVendorStateByUid($uid);
      if ($existing !== NULL) {
        $this->logger()->notice('Using existing onboarding state uid=@uid', [
          '@uid' => (string) $uid,
        ]);
        return $existing;
      }

      $this->logger()->notice('Creating onboarding state uid=@uid', [
        '@uid' => (string) $uid,
      ]);

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
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Loads or creates vendor onboarding state (idempotent, one row per user).
   *
   * Merges into the existing uid-based vendor state when one already exists
   * (e.g. from createVendorStateForUid) to avoid creating a second state row
   * when a Vendor entity is linked later. Otherwise reuses the row for
   * vendor_id or creates one if neither exists.
   */
  public function loadOrCreateVendor(UserInterface $user, Vendor $vendor): OnboardingStateInterface {
    $storage = $this->getStorage();
    $vid = (int) $vendor->id();
    $uid = (int) $user->id();

    $by_uid = $this->loadVendorStateByUid($uid);
    if ($by_uid !== NULL) {
      if ((int) ($by_uid->getVendorId() ?? 0) !== $vid) {
        $by_uid->setVendorId($vid);
      }
      $store_id = NULL;
      if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
        $store = $vendor->get('field_vendor_store')->entity;
        if ($store !== NULL) {
          $store_id = (int) $store->id();
        }
      }
      if ($store_id) {
        $by_uid->setStoreId($store_id);
      }
      $by_uid->setFlags(array_merge($by_uid->getFlags(), $this->computeVendorFlags($vendor)));
      $by_uid->save();
      return $by_uid;
    }

    $by_vendor = $this->loadLatestStateForVendor($vid);
    if ($by_vendor !== NULL) {
      return $by_vendor;
    }
    $store_id = NULL;
    if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
      if ($store !== NULL) {
        $store_id = (int) $store->id();
      }
    }
    $this->logger()->notice('Creating onboarding state uid=@uid', [
      '@uid' => (string) $uid,
    ]);
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
   * For new organiser flows, prefer creating the vendor at onboarding
   * completion (VendorOnboardCompleteController) or gateway recovery, not
   * during the pre-completion profile step.
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
   * Copies legal audit fields from onboarding state flags onto the vendor entity.
   *
   * Aligns with VendorOnboardCompleteController; caller must $vendor->save().
   * Does not add new fields: only sets values when the key exists in $flags
   * and the corresponding field is present.
   */
  public function applyVendorLegalFieldsFromStateFlags(Vendor $vendor, array $flags): void {
    if (!empty($flags['vendor_terms_version']) && $vendor->hasField('field_vendor_terms_version')) {
      $vendor->set('field_vendor_terms_version', (string) $flags['vendor_terms_version']);
    }
    if (isset($flags['vendor_terms_accepted_at']) && (int) $flags['vendor_terms_accepted_at'] > 0 && $vendor->hasField('field_vendor_terms_accepted_at')) {
      $vendor->set('field_vendor_terms_accepted_at', (int) $flags['vendor_terms_accepted_at']);
    }
    if (!empty($flags['vendor_terms_accepted_ip']) && $vendor->hasField('field_vendor_terms_accepted_ip')) {
      $vendor->set('field_vendor_terms_accepted_ip', (string) $flags['vendor_terms_accepted_ip']);
    }
    if (!empty($flags['vendor_terms_accepted_ua']) && $vendor->hasField('field_vendor_terms_accepted_ua')) {
      $vendor->set('field_vendor_terms_accepted_ua', (string) $flags['vendor_terms_accepted_ua']);
    }
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
   * Records /create-event gateway milestones (merged into vendor onboarding flags).
   *
   * Preserved across refreshFlags() when vendor_id is set. Unknown milestones
   * are logged and ignored.
   *
   * @param string $milestone
   *   One of: event_started, draft_created, vendor_incomplete.
   */
  public function recordCreateEventGatewayMilestone(OnboardingStateInterface $state, string $milestone): void {
    $allowed = ['event_started', 'draft_created', 'vendor_incomplete'];
    if (!in_array($milestone, $allowed, TRUE)) {
      $this->logger()->warning('recordCreateEventGatewayMilestone ignored unknown milestone: @m', ['@m' => $milestone]);
      return;
    }
    $flags = $state->getFlags();
    $mel = $flags['mel_create_event_gateway'] ?? [];
    if (!is_array($mel)) {
      $mel = [];
    }
    if (!empty($mel[$milestone])) {
      return;
    }
    $mel[$milestone] = TRUE;
    $flags['mel_create_event_gateway'] = $mel;
    $state->setFlags($flags);
    $state->save();
  }

  /**
   * Refreshes flags on the given state and saves.
   */
  public function refreshFlags(OnboardingStateInterface $state): OnboardingStateInterface {
    $flags = $state->getFlags();
    $preserved_gateway = $this->extractPreserveGatewayFlags($flags);
    if ($state->getTrack() === OnboardingStateInterface::TRACK_VENDOR && $state->getVendorId() !== NULL) {
      $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($state->getVendorId());
      if ($vendor instanceof Vendor) {
        $flags = $this->computeVendorFlags($vendor);
        if ($preserved_gateway !== []) {
          $flags['mel_create_event_gateway'] = $preserved_gateway;
        }
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
   * Whether vendor onboarding allows Event Studio / create routes.
   *
   * Action-first flow: any vendor-track state unlocks Event Studio. Progressive
   * requirements (profile, terms, paid+Stripe) gate publish in Event Studio, not entry.
   */
  public function isVendorEventStudioUnlocked(OnboardingStateInterface $state): bool {
    return $state->getTrack() === OnboardingStateInterface::TRACK_VENDOR;
  }

  /**
   * Whether the user has at least one completed Commerce order (lightweight count).
   *
   * Used for customer UX signals (e.g. post-login hub) without loading orders.
   */
  public function customerHasCompletedOrders(int $uid): bool {
    return $this->customerHasOrders($uid);
  }

  /**
   * Advances customer track after an authenticated RSVP (probe → present).
   */
  public function progressCustomerAfterRsvp(UserInterface $user): void {
    $uid = (int) $user->id();
    if ($uid <= 0) {
      return;
    }
    $state = $this->loadOrCreateCustomer($user);
    $this->refreshFlags($state);
    if ($state->getTrack() !== OnboardingStateInterface::TRACK_CUSTOMER) {
      return;
    }
    if ($state->isCompleted()) {
      return;
    }
    if ($state->getStage() === 'probe') {
      $this->advanceStage($state, 'present');
    }
  }

  /**
   * Completes customer track after a placed Commerce order (first purchase milestone).
   */
  public function progressCustomerAfterPlacedOrder(UserInterface $user): void {
    $uid = (int) $user->id();
    if ($uid <= 0) {
      return;
    }
    $state = $this->loadOrCreateCustomer($user);
    $this->refreshFlags($state);
    if ($state->getTrack() !== OnboardingStateInterface::TRACK_CUSTOMER) {
      return;
    }
    if ($state->isCompleted()) {
      return;
    }
    $this->advanceStage($state, 'complete');
    $state->setCompleted(TRUE);
    $state->save();
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
        // Next step is the create-event gateway; Event Studio is only after onboarding is complete.
        'listen' => ['route_name' => 'myeventlane_vendor.create_event_gateway', 'title' => 'Create your event'],
        'ask' => ['route_name' => 'myeventlane_vendor.create_event_gateway', 'title' => 'Create your event'],
        'invite' => ['route_name' => 'myeventlane_vendor.onboard.boost', 'title' => 'Promote with Boost'],
        'complete' => ['route_name' => NULL, 'title' => ''],
      ];
      return $map[$stage] ?? ['route_name' => NULL, 'title' => ''];
    }
    return ['route_name' => NULL, 'title' => ''];
  }

  /**
   * Sorts vendor-track states by created (newest first), then id.
   *
   * @param array<int|string, mixed> $entities
   *   Result of entity storage loadMultiple().
   *
   * @return list<\Drupal\myeventlane_core\Entity\OnboardingStateInterface>
   */
  private function sortVendorTrackStatesNewestFirst(array $entities): array {
    $list = [];
    foreach ($entities as $entity) {
      if ($entity instanceof OnboardingStateInterface) {
        $list[] = $entity;
      }
    }
    usort($list, function (OnboardingStateInterface $a, OnboardingStateInterface $b) {
      $ca = (int) ($a->get('created')->value ?? 0);
      $cb = (int) ($b->get('created')->value ?? 0);
      if ($ca !== $cb) {
        return $cb <=> $ca;
      }
      return (int) $b->id() <=> (int) $a->id();
    });
    return $list;
  }

  /**
   * Deletes duplicate vendor-track states, keeping the canonical row.
   *
   * @param list<\Drupal\myeventlane_core\Entity\OnboardingStateInterface> $older
   *   Older states to remove (must not include $canonical).
   */
  private function deleteOlderVendorTrackStatesAfterCanonical(OnboardingStateInterface $canonical, array $older, string $log_track, string $log_key): void {
    foreach ($older as $state) {
      if (!$state instanceof OnboardingStateInterface) {
        continue;
      }
      if ((int) $state->id() === (int) $canonical->id()) {
        continue;
      }
      $id = (int) $state->id();
      try {
        $state->delete();
        $this->logger()->warning('Removed duplicate vendor-track onboarding state id=@id (@track @key); kept=@keep.', [
          '@id' => (string) $id,
          '@track' => $log_track,
          '@key' => $log_key,
          '@keep' => (string) $canonical->id(),
        ]);
      }
      catch (\Throwable $e) {
        $this->logger()->error('Failed deleting duplicate onboarding state id=@id: @message', [
          '@id' => (string) $id,
          '@message' => $e->getMessage(),
        ]);
      }
    }
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
  private function logDuplicateOnce(string $track, string $key, int $count = 0): void {
    $k = $track . ':' . $key;
    if (!isset(self::$duplicateLogged[$k])) {
      self::$duplicateLogged[$k] = TRUE;
      if ($count > 1) {
        $this->logger()->warning('Duplicate onboarding states detected — FIX REQUIRED. count=@count track=@track key=@key; using newest.', [
          '@count' => (string) $count,
          '@track' => $track,
          '@key' => $key,
        ]);
      }
      else {
        $this->logger()->info('Multiple onboarding states for @track @key; using newest.', [
          '@track' => $track,
          '@key' => $key,
        ]);
      }
    }
  }

  /**
   * Gets the onboarding logger.
   */
  private function logger(): LoggerChannelInterface {
    return $this->loggerFactory->get('myeventlane_onboarding');
  }

  /**
   * @param array<string, mixed> $flags
   *
   * @return array<string, mixed>
   */
  private function extractPreserveGatewayFlags(array $flags): array {
    $mel = $flags['mel_create_event_gateway'] ?? [];
    return is_array($mel) ? $mel : [];
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
   * Count of completed Commerce orders for the user (lightweight).
   */
  public function getCustomerCompletedOrderCount(int $uid): int {
    try {
      if (!$this->entityTypeManager->getStorage('commerce_order')->getEntityType()->hasKey('id')) {
        return 0;
      }
      return (int) $this->entityTypeManager->getStorage('commerce_order')->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uid)
        ->condition('state', 'completed')
        ->count()
        ->execute();
    }
    catch (\Throwable $e) {
      return 0;
    }
  }

  /**
   * Whether customer has completed orders.
   */
  private function customerHasOrders(int $uid): bool {
    return $this->getCustomerCompletedOrderCount($uid) > 0;
  }

}
