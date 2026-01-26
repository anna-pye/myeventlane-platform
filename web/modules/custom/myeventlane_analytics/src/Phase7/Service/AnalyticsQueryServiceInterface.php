<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Service;

use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;
use Drupal\myeventlane_analytics\Phase7\Value\CountByStoreEventRow;
use Drupal\myeventlane_analytics\Phase7\Value\CountByStoreRow;
use Drupal\myeventlane_analytics\Phase7\Value\MoneyByStoreEventCurrencyRow;

/**
 * Phase 7 analytics query service contract (read-only, scoped).
 *
 * This interface defines the public API for Phase 7 analytics metrics.
 *
 * Note: Implementations MUST be fail-closed and enforce scope/guard rules.
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
interface AnalyticsQueryServiceInterface {

  /**
   * Returns gross revenue grouped by store + event + currency.
   *
   * @return list<\Drupal\myeventlane_analytics\Phase7\Value\MoneyByStoreEventCurrencyRow>
   *   Gross revenue rows.
   */
  public function getGrossRevenue(AnalyticsQuery $query): array;

  /**
   * Returns net revenue grouped by store + event + currency.
   *
   * @return list<\Drupal\myeventlane_analytics\Phase7\Value\MoneyByStoreEventCurrencyRow>
   *   Net revenue rows.
   */
  public function getNetRevenue(AnalyticsQuery $query): array;

  /**
   * Returns tickets sold grouped by store + event.
   *
   * @return list<\Drupal\myeventlane_analytics\Phase7\Value\CountByStoreEventRow>
   *   Ticket count rows.
   */
  public function getTicketsSold(AnalyticsQuery $query): array;

  /**
   * Returns reserved RSVPs grouped by store + event.
   *
   * @return list<\Drupal\myeventlane_analytics\Phase7\Value\CountByStoreEventRow>
   *   RSVP reserved rows.
   */
  public function getReservedRsvps(AnalyticsQuery $query): array;

  /**
   * Returns refund amounts grouped by store + event + currency.
   *
   * @return list<\Drupal\myeventlane_analytics\Phase7\Value\MoneyByStoreEventCurrencyRow>
   *   Refund amount rows.
   */
  public function getRefundAmount(AnalyticsQuery $query): array;

  /**
   * Returns active event counts grouped by store.
   *
   * @return list<\Drupal\myeventlane_analytics\Phase7\Value\CountByStoreRow>
   *   Active event count rows.
   */
  public function getActiveEvents(AnalyticsQuery $query): array;

  /**
   * Returns cancelled event counts grouped by store.
   *
   * @return list<\Drupal\myeventlane_analytics\Phase7\Value\CountByStoreRow>
   *   Cancelled event count rows.
   */
  public function getCancelledEvents(AnalyticsQuery $query): array;

}

