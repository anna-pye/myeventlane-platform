<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQueryInterface;
use Drupal\views\Plugin\views\query\QueryPluginBase;
use Drupal\views\Plugin\views\query\Sql;

/**
 * Scopes vendor RSVP Views to events the account manages.
 *
 * Uses {@see UserVendorMembershipQuery::getManagedEventNodeIds()} so author,
 * organiser entity owner, and field_vendor_users team members share the same
 * event set as workspace parity / dashboard KPIs.
 *
 * Design decisions:
 * - Views access plugins cannot safely enforce per-row ownership; this service
 *   is the authoritative query gate for /dashboard/rsvps.
 * - Empty managed-event sets force a false condition (no rows), never an
 *   unscoped query.
 * - Staff with administer nodes / administer rsvps are not scoped here.
 * - Does not load or expose attendee names while resolving event IDs.
 */
final class RsvpOrganiserViewScope {

  public function __construct(
    private readonly UserVendorMembershipQueryInterface $userVendorMembershipQuery,
  ) {}

  /**
   * Whether the account may see all RSVP rows (staff bypass).
   */
  public function accountBypassesOrganiserScope(AccountInterface $account): bool {
    return $account->hasPermission('administer nodes')
      || $account->hasPermission('administer rsvps');
  }

  /**
   * Event node IDs the account may list RSVPs for (any publish state).
   *
   * @return list<int>
   *   Managed event node IDs, or an empty list when none apply.
   */
  public function getManagedEventIds(AccountInterface $account): array {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return [];
    }
    return $this->userVendorMembershipQuery->getManagedEventNodeIds($uid, FALSE);
  }

  /**
   * Restricts a Views SQL query to managed events, or none.
   */
  public function applyToViewsQuery(QueryPluginBase $query, AccountInterface $account): void {
    if (!$query instanceof Sql) {
      return;
    }
    if ($this->accountBypassesOrganiserScope($account)) {
      return;
    }

    $eventIds = $this->getManagedEventIds($account);
    $baseTable = $query->ensureTable('rsvp_submission');
    if (!$baseTable) {
      $baseTable = 'rsvp_submission';
    }

    if ($eventIds === []) {
      // Fail closed: never return foreign (or any) RSVP rows.
      $query->addWhereExpression(0, '1 = 0');
      return;
    }

    $query->addWhere(0, "$baseTable.event_id", $eventIds, 'IN');
  }

}
