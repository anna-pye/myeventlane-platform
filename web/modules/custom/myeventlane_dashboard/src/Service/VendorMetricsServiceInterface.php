<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Service;

use Drupal\commerce_store\Entity\StoreInterface;

/**
 * Interface for vendor metrics service.
 */
interface VendorMetricsServiceInterface {

  /**
   * Gets dashboard metrics for a store and date range.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store.
   * @param array $range
   *   Date range with keys: start (timestamp), end (timestamp), label (string).
   *
   * @return array
   *   Metrics array with 'items' and 'cache_tags'.
   */
  public function getMetrics(StoreInterface $store, array $range): array;

  /**
   * Returns per-event confirmed RSVPs keyed by event nid.
   *
   * @param int $store_id
   *   Store ID.
   * @param int $start
   *   Start timestamp.
   * @param int $end
   *   End timestamp.
   *
   * @return array<int,int>
   *   [nid => confirmed_count]
   */
  public function getConfirmedRsvpsByEventForStore(int $store_id, int $start, int $end): array;

  /**
   * Returns per-event paid tickets sold keyed by event nid.
   *
   * @param int $store_id
   *   Store ID.
   * @param int $start
   *   Start timestamp.
   * @param int $end
   *   End timestamp.
   *
   * @return array<int,int>
   *   [nid => tickets_sold]
   */
  public function getPaidTicketsSoldByEventForStore(int $store_id, int $start, int $end): array;

  /**
   * Returns per-event revenue keyed by event nid.
   *
   * @param int $store_id
   *   Store ID.
   * @param int $start
   *   Start timestamp.
   * @param int $end
   *   End timestamp.
   *
   * @return array<int,int>
   *   [nid => revenue_cents]
   */
  public function getRevenueCentsByEventForStore(int $store_id, int $start, int $end): array;

  /**
   * Gets debug totals for a store and date range (raw numbers, no formatting).
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store.
   * @param array $range
   *   Date range with keys: start (timestamp), end (timestamp), label (string).
   *
   * @return array
   *   Debug totals with gross_cents, refund_cents, net_cents, tickets_sold, confirmed_rsvps.
   */
  public function getDebugTotals(StoreInterface $store, array $range): array;

}
