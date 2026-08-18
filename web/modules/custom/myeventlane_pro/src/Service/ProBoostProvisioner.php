<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\advancedqueue\Job;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_boost\BoostManager;
use Drupal\myeventlane_boost\Entity\BoostEntitlementInterface;
use Drupal\myeventlane_boost\Service\BoostEntitlementManager;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Provisions and revokes Pro-sourced boost entitlements.
 */
final class ProBoostProvisioner {

  private const PRO_SOURCE = 'pro';
  private const DEFAULT_BOOST_DAYS = 7;
  private const BOOST_SYNC_QUEUE_ID = 'pro_boost_sync';
  private const BOOST_SYNC_JOB_ID = 'pro_boost_sync_job';

  public function __construct(
    private readonly ProActiveResolver $proActiveResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BoostEntitlementManager $boostEntitlementManager,
    private readonly ?BoostManager $boostManager,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ProBoostGrantPolicy $grantPolicy,
  ) {}

  /**
   * Syncs Pro boost state for one store.
   */
  public function syncStore(StoreInterface $store): array {
    $storeId = (int) ($store->id() ?? 0);
    if ($storeId <= 0) {
      return ['created' => 0, 'updated' => 0, 'revoked' => 0];
    }

    if (!$this->proActiveResolver->isStoreProActive($store)) {
      $revoked = $this->revokeProEntitlementsForStore($store);
      return ['created' => 0, 'updated' => 0, 'revoked' => $revoked];
    }

    $owner = $store->getOwner();
    if ($owner === NULL || (int) $owner->id() <= 0) {
      $this->logger->warning('Skipping Pro boost sync: store @store_id has no valid owner.', [
        '@store_id' => $storeId,
      ]);
      return ['created' => 0, 'updated' => 0, 'revoked' => 0];
    }

    $now = $this->time->getRequestTime();
    $eligibleEvents = $this->loadEligibleEventsForStore($storeId, $now);
    $boostDays = max(1, (int) ($this->configFactory->get('myeventlane_pro.settings')->get('pro_boost_days') ?? self::DEFAULT_BOOST_DAYS));
    $activeEnds = $this->proActiveResolver->getStoreActiveEndTimestamp($store);
    $boostEnds = $this->grantPolicy->newGrantEnd($now, $activeEnds, $boostDays);

    if ($boostEnds === NULL) {
      return ['created' => 0, 'updated' => 0, 'revoked' => $this->revokeProEntitlementsForStore($store)];
    }

    $created = 0;
    $updated = 0;

    foreach ($eligibleEvents as $event) {
      $result = $this->upsertProEntitlement($event, (int) $owner->id(), $now, $boostEnds, $activeEnds, $boostDays);
      if ($result === 'created') {
        $created++;
      }
      elseif ($result === 'updated') {
        $updated++;
      }
      $this->boostEntitlementManager->refreshEventBoostState((int) $event->id());
    }

    return ['created' => $created, 'updated' => $updated, 'revoked' => 0];
  }

  /**
   * Syncs a store by ID.
   */
  public function syncStoreById(int $storeId): array {
    if ($storeId <= 0) {
      return ['created' => 0, 'updated' => 0, 'revoked' => 0];
    }
    $store = $this->entityTypeManager->getStorage('commerce_store')->load($storeId);
    if (!$store instanceof StoreInterface) {
      return ['created' => 0, 'updated' => 0, 'revoked' => 0];
    }
    return $this->syncStore($store);
  }

  /**
   * Enqueues one sync job for a store.
   */
  public function enqueueStoreSync(int $storeId): bool {
    if ($storeId <= 0) {
      return FALSE;
    }

    if ($this->hasPendingSyncJobForStore($storeId)) {
      return FALSE;
    }

    $queueStorage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    $queue = $queueStorage->load(self::BOOST_SYNC_QUEUE_ID);
    if ($queue === NULL) {
      $queue = $queueStorage->create([
        'id' => self::BOOST_SYNC_QUEUE_ID,
        'label' => 'Pro Boost Sync',
        'backend' => 'database',
        'backend_configuration' => ['lease_time' => 300],
        'processor' => 'cron',
        'processing_time' => 120,
        'threshold' => ['state' => 'failure', 'limit' => 500],
        'locked' => FALSE,
      ]);
      $queue->save();
      $this->logger->warning('Re-created missing AdvancedQueue queue "@queue" for Pro boost sync.', [
        '@queue' => self::BOOST_SYNC_QUEUE_ID,
      ]);
    }

    $queue->enqueueJob(Job::create(self::BOOST_SYNC_JOB_ID, ['store_id' => $storeId]));
    return TRUE;
  }

  /**
   * Enqueues sync jobs for all currently active Pro stores.
   */
  public function enqueueActiveProStoreSyncs(int $limit = 500): int {
    $storeIds = $this->loadProStoreIds($limit);
    $enqueued = 0;

    foreach ($storeIds as $storeId) {
      if ($this->enqueueStoreSync($storeId)) {
        $enqueued++;
      }
    }

    return $enqueued;
  }

  /**
   * Revokes active Pro-sourced entitlements for a store and refreshes fields.
   */
  public function revokeProEntitlementsForStore(StoreInterface $store): int {
    $storeId = (int) ($store->id() ?? 0);
    if ($storeId <= 0) {
      return 0;
    }

    $eventIds = $this->loadAllEventIdsForStore($storeId);
    if ($eventIds === []) {
      return 0;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_boost_entitlement');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventIds, 'IN')
      ->condition('source', self::PRO_SOURCE)
      ->condition('status', BoostEntitlementInterface::STATUS_ACTIVE)
      ->execute();

    if ($ids === []) {
      return 0;
    }

    $count = 0;
    $entitlements = $storage->loadMultiple($ids);
    foreach ($entitlements as $entitlement) {
      if (!$entitlement instanceof BoostEntitlementInterface) {
        continue;
      }
      $entitlement->set('status', BoostEntitlementInterface::STATUS_REVOKED);
      $entitlement->save();
      $eventId = (int) ($entitlement->get('event')->target_id ?? 0);
      if ($eventId > 0) {
        $this->boostEntitlementManager->refreshEventBoostState($eventId);
      }
      $count++;
    }

    return $count;
  }

  /**
   * @return \Drupal\node\NodeInterface[]
   *   Eligible event nodes for a store.
   */
  private function loadEligibleEventsForStore(int $storeId, int $now): array {
    $eventIds = $this->loadEligibleEventIdsForStore($storeId, $now);
    if ($eventIds === []) {
      return [];
    }
    $events = $this->entityTypeManager->getStorage('node')->loadMultiple($eventIds);
    return array_values(array_filter($events, static fn($event): bool => $event instanceof NodeInterface && $event->bundle() === 'event'));
  }

  /**
   * @return int[]
   *   Eligible event IDs for a store.
   */
  private function loadEligibleEventIdsForStore(int $storeId, int $now): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('field_event_store', $storeId)
      ->condition('field_event_start', gmdate('Y-m-d\TH:i:s', $now), '>=')
      ->sort('nid', 'ASC');

    $ids = $query->execute();
    return array_values(array_map('intval', $ids));
  }

  /**
   * @return int[]
   *   All event IDs for a store.
   */
  private function loadAllEventIdsForStore(int $storeId): array {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('field_event_store', $storeId)
      ->execute();
    return array_values(array_map('intval', $ids));
  }

  /**
   * Upserts a Pro-sourced entitlement for an event.
   */
  private function upsertProEntitlement(NodeInterface $event, int $uid, int $startsAt, int $endsAt, ?int $proActiveEnd, int $boostDays): string {
    $storage = $this->entityTypeManager->getStorage('myeventlane_boost_entitlement');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', (int) $event->id())
      ->condition('source', self::PRO_SOURCE)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids !== []) {
      $existing = $storage->load((int) reset($ids));
      if ($existing instanceof BoostEntitlementInterface) {
        $existingStarts = (int) $existing->get('starts')->value;
        $existingEnds = (int) $existing->get('ends')->value;
        $canonicalEnds = $this->grantPolicy->existingGrantEnd(
          $startsAt,
          $existingStarts,
          $existingEnds,
          $proActiveEnd,
          $boostDays,
        );
        if ($canonicalEnds === NULL) {
          return 'none';
        }

        $changed = FALSE;
        if ($existingEnds !== $canonicalEnds) {
          $existing->set('ends', $canonicalEnds);
          $changed = TRUE;
        }
        if ((string) $existing->get('status')->value !== BoostEntitlementInterface::STATUS_ACTIVE) {
          $existing->set('status', BoostEntitlementInterface::STATUS_ACTIVE);
          $changed = TRUE;
        }
        if ($changed) {
          $existing->save();
          return 'updated';
        }
        return 'none';
      }
    }

    $entitlement = $storage->create([
      'uid' => $uid,
      'event' => (int) $event->id(),
      'starts' => $startsAt,
      'ends' => $endsAt,
      'status' => BoostEntitlementInterface::STATUS_ACTIVE,
      'label' => sprintf('Pro boost for %s', $event->label()),
      'source' => self::PRO_SOURCE,
    ]);
    $entitlement->save();
    return 'created';
  }

  /**
   * Resolves active Pro stores from subscription owners.
   *
   * @return int[]
   *   Store IDs.
   */
  private function loadProStoreIds(int $limit): array {
    $subscriptionStorage = $this->entityTypeManager->getStorage('commerce_subscription');
    $ids = $subscriptionStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('billing_schedule', 'mel_pro_monthly')
      ->sort('subscription_id', 'DESC')
      ->range(0, max(1, $limit * 2))
      ->execute();

    if ($ids === []) {
      return [];
    }

    $storeIds = [];
    $subscriptions = $subscriptionStorage->loadMultiple($ids);
    foreach ($subscriptions as $subscription) {
      if (!$subscription instanceof \Drupal\commerce_recurring\Entity\SubscriptionInterface) {
        continue;
      }
      $customer = $subscription->getCustomer();
      if (!$customer instanceof \Drupal\user\UserInterface || $customer->isAnonymous()) {
        continue;
      }
      if (!$this->proActiveResolver->isUserProActive($customer)) {
        continue;
      }

      $vendorIds = $this->entityTypeManager->getStorage('myeventlane_vendor')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', (int) $customer->id())
        ->range(0, 1)
        ->execute();

      if ($vendorIds === []) {
        continue;
      }

      $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load((int) reset($vendorIds));
      if (!$vendor instanceof \Drupal\myeventlane_vendor\Entity\Vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
        continue;
      }

      $store = $vendor->get('field_vendor_store')->entity;
      if ($store instanceof StoreInterface && $store->id() !== NULL) {
        $storeIds[(int) $store->id()] = (int) $store->id();
      }
      if (count($storeIds) >= $limit) {
        break;
      }
    }

    return array_values($storeIds);
  }

  /**
   * Checks whether a queued/processing sync job already exists for a store.
   */
  private function hasPendingSyncJobForStore(int $storeId): bool {
    $query = $this->database->select('advancedqueue', 'aq')
      ->fields('aq', ['payload'])
      ->condition('queue_id', self::BOOST_SYNC_QUEUE_ID)
      ->condition('type', self::BOOST_SYNC_JOB_ID)
      ->condition('state', [Job::STATE_QUEUED, Job::STATE_PROCESSING], 'IN');

    foreach ($query->execute()->fetchCol() as $rawPayload) {
      $payload = json_decode((string) $rawPayload, TRUE);
      if (!is_array($payload)) {
        $payload = unserialize((string) $rawPayload, ['allowed_classes' => FALSE]);
      }
      if (!is_array($payload)) {
        continue;
      }
      if ((int) ($payload['store_id'] ?? 0) === $storeId) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
