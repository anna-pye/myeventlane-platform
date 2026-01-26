<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Scope;

use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;

/**
 * Resolves effective store scope for Phase 7 analytics queries.
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
interface AnalyticsScopeResolverInterface {

  /**
   * Resolves effective store IDs for the query (server-derived).
   *
   * @return list<int>
   *   Effective store IDs.
   */
  public function resolveEffectiveStoreIds(AnalyticsQuery $query): array;

  /**
   * Validates whether admin scope is allowed for a given user ID.
   *
   * @param int $uid
   *   User ID.
   *
   * @return bool
   *   TRUE if admin scope is allowed; FALSE otherwise.
   */
  public function isAdminScopeAllowed(int $uid): bool;

}

