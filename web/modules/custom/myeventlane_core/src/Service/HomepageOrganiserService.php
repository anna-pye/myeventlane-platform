<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Service for selecting and rotating organisers for homepage spotlight.
 *
 * Shows organisers with event counts, auto-rotates.
 * Caches result for performance.
 */
class HomepageOrganiserService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  /**
   * Cache key for organiser spotlight.
   */
  protected const CACHE_KEY = 'homepage_organiser_spotlight';

  /**
   * Cache lifetime (1 hour).
   */
  protected const CACHE_LIFETIME = 3600;

  /**
   * Constructs a HomepageOrganiserService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    CacheBackendInterface $cache,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->cache = $cache;
  }

  /**
   * Gets a featured organiser for the spotlight.
   *
   * Rotates based on cache expiration. Shows organiser with most events.
   *
   * @return array|null
   *   Organiser data with keys: 'name', 'event_count', 'vendor_id'
   *   Returns NULL if no organisers found.
   */
  public function getFeaturedOrganiser(): ?array {
    $cache_item = $this->cache->get(self::CACHE_KEY);

    if ($cache_item) {
      return $cache_item->data;
    }

    try {
      $organisers = $this->getOrganisersWithCounts();
      if (empty($organisers)) {
        return NULL;
      }

      // Select organiser with most events (first in sorted list).
      $featured = reset($organisers);

      // Cache the result.
      $this->cache->set(
        self::CACHE_KEY,
        $featured,
        time() + self::CACHE_LIFETIME,
        ['node_list:event']
      );

      return $featured;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Gets organisers with their event counts.
   *
   * @return array
   *   Array of organisers, sorted by event count (descending).
   *   Format: [
   *     ['name' => '...', 'event_count' => 5, 'vendor_id' => 123],
   *     ...
   *   ]
   */
  protected function getOrganisersWithCounts(): array {
    $organisers = [];

    try {
      // Load all published events.
      $node_storage = $this->entityTypeManager->getStorage('node');
      $query = $node_storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'event')
        ->condition('status', 1)
        ->condition('field_event_start', time(), '>=');

      $event_ids = $query->execute();
      if (empty($event_ids)) {
        return [];
      }

      $events = $node_storage->loadMultiple($event_ids);

      // Count events per vendor/organiser.
      $vendor_counts = [];
      foreach ($events as $event) {
        $vendor_id = $this->getEventVendorId($event);
        if ($vendor_id) {
          $vendor_counts[$vendor_id] = ($vendor_counts[$vendor_id] ?? 0) + 1;
        }
      }

      if (empty($vendor_counts)) {
        return [];
      }

      // Load vendor entities to get names.
      $vendor_storage = $this->entityTypeManager->getStorage('vendor');
      $vendors = $vendor_storage->loadMultiple(array_keys($vendor_counts));

      foreach ($vendors as $vendor_id => $vendor) {
        if ($vendor->hasField('name') && !$vendor->get('name')->isEmpty()) {
          $organisers[] = [
            'name' => $vendor->get('name')->value,
            'event_count' => $vendor_counts[$vendor_id],
            'vendor_id' => (int) $vendor_id,
          ];
        }
        elseif ($vendor->label()) {
          $organisers[] = [
            'name' => $vendor->label(),
            'event_count' => $vendor_counts[$vendor_id],
            'vendor_id' => (int) $vendor_id,
          ];
        }
      }

      // Sort by event count descending.
      usort($organisers, function ($a, $b) {
        return $b['event_count'] <=> $a['event_count'];
      });
    }
    catch (\Exception $e) {
      // Fail silently.
    }

    return $organisers;
  }

  /**
   * Gets vendor ID from an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return int|null
   *   Vendor ID or NULL.
   */
  protected function getEventVendorId(NodeInterface $event): ?int {
    // Try field_event_vendor first.
    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor) {
        return (int) $vendor->id();
      }
    }

    // Fallback to event store (which links to vendor).
    if ($event->hasField('field_event_store') && !$event->get('field_event_store')->isEmpty()) {
      $store = $event->get('field_event_store')->entity;
      if ($store && $store->hasField('field_vendor') && !$store->get('field_vendor')->isEmpty()) {
        $vendor = $store->get('field_vendor')->entity;
        if ($vendor) {
          return (int) $vendor->id();
        }
      }
    }

    return NULL;
  }

}
