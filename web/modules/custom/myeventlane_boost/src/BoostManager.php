<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_boost\Service\BoostEntitlementManager;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Service to apply and manage boost status on events.
 *
 * Canonical source of truth for all boosted event queries.
 */
final class BoostManager {

  /**
   * The entity type manager.
   */
  private readonly EntityTypeManagerInterface $entityTypeManager;

  /**
   * The time service.
   */
  private readonly TimeInterface $time;

  /**
   * The entitlement manager (nullable during legacy container boot).
   */
  private readonly ?BoostEntitlementManager $entitlementManager;

  /**
   * The logger.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a BoostManager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\myeventlane_boost\Service\BoostEntitlementManager|\Psr\Log\LoggerInterface $third
   *   The entitlement manager (new signature) or logger (legacy signature).
   * @param \Psr\Log\LoggerInterface|null $logger
   *   The logger when using the new signature.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    TimeInterface $time,
    BoostEntitlementManager|LoggerInterface $third,
    ?LoggerInterface $logger = NULL,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->time = $time;

    // Backward compatibility for stale container definitions:
    // old signature passed logger as 3rd argument.
    if ($third instanceof BoostEntitlementManager) {
      $this->entitlementManager = $third;
      $this->logger = $logger ?? throw new \InvalidArgumentException('BoostManager logger is required.');
    }
    else {
      $this->entitlementManager = NULL;
      $this->logger = $third;
    }
  }

  /**
   * Apply or extend a boost on an event node.
   *
   * @param int $eventNid
   *   The event node ID.
   * @param int $days
   *   Number of days to boost for.
   */
  public function applyBoost(int $eventNid, int $days): void {
    $event = $this->entityTypeManager->getStorage('node')->load($eventNid);
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      $this->logger->warning('Attempted to boost invalid node @nid', ['@nid' => $eventNid]);
      return;
    }

    $now = new \DateTimeImmutable('@' . $this->time->getRequestTime());
    $currentValue = $event->get('field_promo_expires')->value ?? NULL;

    $base = $now;
    if ($currentValue) {
      try {
        $existing = new \DateTimeImmutable($currentValue, new \DateTimeZone('UTC'));
        if ($existing > $now) {
          $base = $existing;
        }
      }
      catch (\Exception) {
        // Invalid date, use now.
      }
    }

    $expires = $base->modify(sprintf('+%d days', max(1, $days)))
      ->setTimezone(new \DateTimeZone('UTC'));

    $event->set('field_promoted', 1);
    $event->set('field_promo_expires', $expires->format('Y-m-d\TH:i:s'));
    $event->save();

    $this->logger->info('Applied/Extended Boost: event @nid +@days days (until @exp)', [
      '@nid' => $eventNid,
      '@days' => $days,
      '@exp' => $expires->format(\DATE_ATOM),
    ]);
  }

  /**
   * Revoke a boost from an event node.
   *
   * @param int $eventNid
   *   The event node ID.
   */
  public function revokeBoost(int $eventNid): void {
    if ($this->entitlementManager) {
      try {
        $this->entitlementManager->refreshEventBoostState($eventNid);
        return;
      }
      catch (DatabaseExceptionWrapper $e) {
        $this->logger->error('Boost entitlement table unavailable during revoke for event @nid: @message', [
          '@nid' => $eventNid,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventNid);
    if ($event instanceof NodeInterface && $event->bundle() === 'event') {
      $event->set('field_promoted', 0);
      $event->set('field_promo_expires', NULL);
      $event->save();
    }
  }

  /**
   * Check if an event is currently boosted.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if boosted and not expired.
   */
  public function isBoosted(NodeInterface $event): bool {
    return $this->getBoostStatusForEvent($event)['active'];
  }

  /**
   * Gets active boosted event IDs for a store.
   *
   * Canonical method for querying boosted events by store.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The commerce store. If NULL, returns boosted events for all stores.
   * @param array $options
   *   Query options:
   *   - include_scheduled (bool, default FALSE): Include boosts that haven't started yet
   *   - include_expired (bool, default FALSE): Include expired boosts
   *   - limit (int|null, default NULL): Maximum number of results
   *   - now (int|null, default NULL): Override current time (for testing)
   *   - access_check (bool, default TRUE): Whether to respect node access.
   *
   * @return array
   *   Array of event node IDs (integers).
   */
  public function getActiveBoostedEventIdsForStore(?StoreInterface $store = NULL, array $options = []): array {
    $options += [
      'include_scheduled' => FALSE,
      'include_expired' => FALSE,
      'limit' => NULL,
      'now' => NULL,
      'access_check' => TRUE,
    ];

    $now = $options['now'] ?? $this->time->getRequestTime();
    if (!$this->entitlementManager) {
      $nowIso = gmdate('Y-m-d\TH:i:s', $now);
      $query = $this->entityTypeManager->getStorage('node')
        ->getQuery()
        ->accessCheck($options['access_check'])
        ->condition('type', 'event')
        ->condition('status', 1)
        ->condition('field_promoted', 1);
      if (!$options['include_expired']) {
        $query->condition('field_promo_expires', $nowIso, '>');
      }
      if ($store !== NULL) {
        $query->condition('field_event_store', $store->id());
      }
      if ($options['limit'] !== NULL && $options['limit'] > 0) {
        $query->range(0, $options['limit']);
      }
      return array_map('intval', $query->execute());
    }

    try {
      $entitlementStorage = $this->entityTypeManager->getStorage('myeventlane_boost_entitlement');
      $entitlementQuery = $entitlementStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 'active');

      if (!$options['include_expired']) {
        $entitlementQuery->condition('ends', $now, '>');
      }

      $entitlement_ids = $entitlementQuery->execute();
      if (empty($entitlement_ids)) {
        return [];
      }

      $event_ids = [];
      $entitlements = $entitlementStorage->loadMultiple($entitlement_ids);
      foreach ($entitlements as $entitlement) {
        $event_id = (int) ($entitlement->get('event')->target_id ?? 0);
        if ($event_id > 0) {
          $event_ids[$event_id] = $event_id;
        }
      }

      if (empty($event_ids)) {
        return [];
      }

      $query = $this->entityTypeManager->getStorage('node')
        ->getQuery()
        ->accessCheck($options['access_check'])
        ->condition('nid', array_values($event_ids), 'IN')
        ->condition('type', 'event')
        ->condition('status', 1)
        ->sort('nid', 'DESC');

      if ($store !== NULL) {
        $query->condition('field_event_store', $store->id());
      }
      if ($options['limit'] !== NULL && $options['limit'] > 0) {
        $query->range(0, $options['limit']);
      }

      return array_map('intval', $query->execute());
    }
    catch (DatabaseExceptionWrapper $e) {
      $this->logger->error('Boost entitlement table unavailable while loading active boosts: @message', [
        '@message' => $e->getMessage(),
      ]);
      $nowIso = gmdate('Y-m-d\TH:i:s', $now);
      $query = $this->entityTypeManager->getStorage('node')
        ->getQuery()
        ->accessCheck($options['access_check'])
        ->condition('type', 'event')
        ->condition('status', 1)
        ->condition('field_promoted', 1);
      if (!$options['include_expired']) {
        $query->condition('field_promo_expires', $nowIso, '>');
      }
      if ($store !== NULL) {
        $query->condition('field_event_store', $store->id());
      }
      if ($options['limit'] !== NULL && $options['limit'] > 0) {
        $query->range(0, $options['limit']);
      }
      return array_map('intval', $query->execute());
    }
  }

  /**
   * Gets active boosted events for a store.
   *
   * Canonical method for loading boosted event nodes by store.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The commerce store. If NULL, returns boosted events for all stores.
   * @param array $options
   *   Query options (see getActiveBoostedEventIdsForStore).
   *
   * @return \Drupal\node\NodeInterface[]
   *   Array of event node entities, keyed by node ID.
   */
  public function getActiveBoostedEventsForStore(?StoreInterface $store = NULL, array $options = []): array {
    $nids = $this->getActiveBoostedEventIdsForStore($store, $options);
    if (empty($nids)) {
      return [];
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $events = $nodeStorage->loadMultiple($nids);

    // Filter to ensure we only return event nodes and respect access.
    $result = [];
    foreach ($events as $event) {
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        $result[$event->id()] = $event;
      }
    }

    return $result;
  }

  /**
   * Gets boost status for a single event.
   *
   * Canonical method for checking boost status of an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param int|null $now
   *   Override current time (for testing). NULL uses request time.
   *
   * @return array
   *   Boost status array with keys:
   *   - active (bool): Whether boost is currently active (not expired)
   *   - scheduled (bool): Whether boost is scheduled (not yet started) - always FALSE if no start field
   *   - expired (bool): Whether boost has expired
   *   - start_timestamp (int|null): Boost start timestamp (NULL if no start field)
   *   - end_timestamp (int|null): Boost end timestamp (from field_promo_expires)
   *   - boost_product_id (int|null): Product/variation ID if linked (NULL - not tracked)
   *   - source_order_id (int|null): Source order ID if linked (NULL - not tracked)
   */
  public function getBoostStatusForEvent(NodeInterface $event, ?int $now = NULL): array {
    if ($event->bundle() !== 'event') {
      return [
        'active' => FALSE,
        'scheduled' => FALSE,
        'expired' => FALSE,
        'start_timestamp' => NULL,
        'end_timestamp' => NULL,
        'boost_product_id' => NULL,
        'source_order_id' => NULL,
      ];
    }

    $now = $now ?? $this->time->getRequestTime();
    $endTimestamp = NULL;
    if ($this->entitlementManager) {
      try {
        $endTimestamp = $this->entitlementManager->getActiveEndTimestampForEvent((int) $event->id());
      }
      catch (DatabaseExceptionWrapper $e) {
        $this->logger->error('Boost entitlement table unavailable for event @nid status check: @message', [
          '@nid' => $event->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }
    if ($endTimestamp === NULL) {
      $promoted = (bool) ($event->get('field_promoted')->value ?? FALSE);
      $expiresValue = $event->get('field_promo_expires')->value ?? NULL;
      if ($promoted && $expiresValue) {
        try {
          $expires = new \DateTimeImmutable($expiresValue, new \DateTimeZone('UTC'));
          $endTimestamp = $expires->getTimestamp();
        }
        catch (\Exception) {
          $endTimestamp = NULL;
        }
      }
    }
    $active = $endTimestamp !== NULL && $endTimestamp > $now;
    $expired = $endTimestamp !== NULL && $endTimestamp <= $now;
    $scheduled = FALSE;

    return [
      'active' => $active,
      'scheduled' => $scheduled,
      'expired' => $expired,
      'start_timestamp' => NULL,
      'end_timestamp' => $endTimestamp,
      'boost_product_id' => NULL,
      'source_order_id' => NULL,
    ];
  }

  /**
   * Gets all events for a store (boosted or not).
   *
   * Helper method to fetch all vendor events for the Boost page.
   * Falls back to owner-based query if store-based query returns empty.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The commerce store.
   * @param array $options
   *   Query options:
   *   - published_only (bool, default TRUE): Only published events
   *   - limit (int|null, default NULL): Maximum number of results
   *   - access_check (bool, default TRUE): Whether to respect node access.
   *   - fallback_to_owner (bool, default TRUE): If store query empty, query by store owner uid.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Array of event node entities, keyed by node ID.
   */
  public function getEventsForStore(StoreInterface $store, array $options = []): array {
    $options += [
      'published_only' => TRUE,
      'limit' => NULL,
      'access_check' => TRUE,
      'fallback_to_owner' => TRUE,
    ];

    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Try store-based query first.
    $query = $nodeStorage->getQuery()
      ->accessCheck($options['access_check'])
      ->condition('type', 'event')
      ->condition('field_event_store', $store->id());

    if ($options['published_only']) {
      $query->condition('status', 1);
    }

    // Order by created descending (newest first).
    $query->sort('created', 'DESC');

    if ($options['limit'] !== NULL && $options['limit'] > 0) {
      $query->range(0, $options['limit']);
    }

    $nids = $query->execute();

    // Fallback: if no events found by store, try by store owner.
    if (empty($nids) && $options['fallback_to_owner'] && $store->hasField('uid')) {
      $storeOwnerId = (int) $store->get('uid')->target_id;
      if ($storeOwnerId > 0) {
        $fallbackQuery = $nodeStorage->getQuery()
          ->accessCheck($options['access_check'])
          ->condition('type', 'event')
          ->condition('uid', $storeOwnerId);

        if ($options['published_only']) {
          $fallbackQuery->condition('status', 1);
        }

        $fallbackQuery->sort('created', 'DESC');

        if ($options['limit'] !== NULL && $options['limit'] > 0) {
          $fallbackQuery->range(0, $options['limit']);
        }

        $nids = $fallbackQuery->execute();

        if (!empty($nids)) {
          $this->logger->debug('getEventsForStore: Store query empty, using owner fallback', [
            'store_id' => $store->id(),
            'store_owner_uid' => $storeOwnerId,
            'events_found' => count($nids),
          ]);
        }
      }
    }

    if (empty($nids)) {
      return [];
    }

    $events = $nodeStorage->loadMultiple($nids);
    $result = [];
    foreach ($events as $event) {
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        $result[$event->id()] = $event;
      }
    }

    return $result;
  }

  /**
   * Gets boosted events expiring within a time window.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The commerce store. If NULL, checks all stores.
   * @param int $seconds
   *   Number of seconds from now to check (e.g., 48 * 3600 for 48 hours).
   * @param array $options
   *   Query options (see getActiveBoostedEventIdsForStore).
   *
   * @return array
   *   Array of event node IDs expiring within the window.
   */
  public function getExpiringBoostedEventIdsForStore(?StoreInterface $store = NULL, int $seconds = 86400, array $options = []): array {
    $options += ['access_check' => FALSE, 'now' => NULL];
    $now = $options['now'] ?? $this->time->getRequestTime();
    $upper = $now + $seconds;

    if (!$this->entitlementManager) {
      $nowIso = gmdate('Y-m-d\TH:i:s', $now);
      $upperIso = gmdate('Y-m-d\TH:i:s', $upper);
      $query = $this->entityTypeManager->getStorage('node')
        ->getQuery()
        ->accessCheck($options['access_check'])
        ->condition('type', 'event')
        ->condition('status', 1)
        ->condition('field_promoted', 1)
        ->condition('field_promo_expires', $nowIso, '>')
        ->condition('field_promo_expires', $upperIso, '<=');
      if ($store !== NULL) {
        $query->condition('field_event_store', $store->id());
      }
      return array_map('intval', $query->execute());
    }

    try {
      $storage = $this->entityTypeManager->getStorage('myeventlane_boost_entitlement');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 'active')
        ->condition('ends', $now, '>')
        ->condition('ends', $upper, '<=')
        ->execute();
      if (empty($ids)) {
        return [];
      }

      $event_ids = [];
      $entitlements = $storage->loadMultiple($ids);
      foreach ($entitlements as $entitlement) {
        $event_id = (int) ($entitlement->get('event')->target_id ?? 0);
        if ($event_id > 0) {
          $event_ids[$event_id] = $event_id;
        }
      }
      if (empty($event_ids)) {
        return [];
      }

      $query = $this->entityTypeManager->getStorage('node')
        ->getQuery()
        ->accessCheck($options['access_check'])
        ->condition('nid', array_values($event_ids), 'IN')
        ->condition('type', 'event')
        ->condition('status', 1);
      if ($store !== NULL) {
        $query->condition('field_event_store', $store->id());
      }
      return array_map('intval', $query->execute());
    }
    catch (DatabaseExceptionWrapper $e) {
      $this->logger->error('Boost entitlement table unavailable while loading expiring boosts: @message', [
        '@message' => $e->getMessage(),
      ]);
      $nowIso = gmdate('Y-m-d\TH:i:s', $now);
      $upperIso = gmdate('Y-m-d\TH:i:s', $upper);
      $query = $this->entityTypeManager->getStorage('node')
        ->getQuery()
        ->accessCheck($options['access_check'])
        ->condition('type', 'event')
        ->condition('status', 1)
        ->condition('field_promoted', 1)
        ->condition('field_promo_expires', $nowIso, '>')
        ->condition('field_promo_expires', $upperIso, '<=');
      if ($store !== NULL) {
        $query->condition('field_event_store', $store->id());
      }
      return array_map('intval', $query->execute());
    }
  }

  /**
   * Gets expired boosted event IDs for a store.
   *
   * Returns only events where boost has expired (field_promo_expires <= now).
   * Used by BoostExpiryCron to find and clear expired boosts.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The commerce store. If NULL, checks all stores.
   * @param array $options
   *   Query options:
   *   - limit (int|null, default NULL): Maximum number of results
   *   - now (int|null, default NULL): Override current time (for testing)
   *   - access_check (bool, default FALSE): Whether to respect node access.
   *
   * @return array
   *   Array of event node IDs (integers) with expired boosts.
   */
  public function getExpiredBoostedEventIdsForStore(?StoreInterface $store = NULL, array $options = []): array {
    $options += [
      'limit' => NULL,
      'now' => NULL,
      'access_check' => FALSE,
    ];

    $now = $options['now'] ?? $this->time->getRequestTime();
    if (!$this->entitlementManager) {
      $nowIso = gmdate('Y-m-d\TH:i:s', $now);
      $query = $this->entityTypeManager->getStorage('node')
        ->getQuery()
        ->accessCheck($options['access_check'])
        ->condition('type', 'event')
        ->condition('field_promoted', 1)
        ->condition('field_promo_expires', $nowIso, '<=');
      if ($store !== NULL) {
        $query->condition('field_event_store', $store->id());
      }
      if ($options['limit'] !== NULL && $options['limit'] > 0) {
        $query->range(0, $options['limit']);
      }
      return array_map('intval', $query->execute());
    }

    try {
      $storage = $this->entityTypeManager->getStorage('myeventlane_boost_entitlement');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 'active')
        ->condition('ends', $now, '<=')
        ->execute();
      if (empty($ids)) {
        return [];
      }

      $event_ids = [];
      $entitlements = $storage->loadMultiple($ids);
      foreach ($entitlements as $entitlement) {
        $event_id = (int) ($entitlement->get('event')->target_id ?? 0);
        if ($event_id > 0) {
          $event_ids[$event_id] = $event_id;
        }
      }
      if (empty($event_ids)) {
        return [];
      }

      $query = $this->entityTypeManager->getStorage('node')
        ->getQuery()
        ->accessCheck($options['access_check'])
        ->condition('nid', array_values($event_ids), 'IN')
        ->condition('type', 'event');
      if ($store !== NULL) {
        $query->condition('field_event_store', $store->id());
      }
      if ($options['limit'] !== NULL && $options['limit'] > 0) {
        $query->range(0, $options['limit']);
      }
      return array_map('intval', $query->execute());
    }
    catch (DatabaseExceptionWrapper $e) {
      $this->logger->error('Boost entitlement table unavailable while loading expired boosts: @message', [
        '@message' => $e->getMessage(),
      ]);
      $nowIso = gmdate('Y-m-d\TH:i:s', $now);
      $query = $this->entityTypeManager->getStorage('node')
        ->getQuery()
        ->accessCheck($options['access_check'])
        ->condition('type', 'event')
        ->condition('field_promoted', 1)
        ->condition('field_promo_expires', $nowIso, '<=');
      if ($store !== NULL) {
        $query->condition('field_event_store', $store->id());
      }
      if ($options['limit'] !== NULL && $options['limit'] > 0) {
        $query->range(0, $options['limit']);
      }
      return array_map('intval', $query->execute());
    }
  }

}
