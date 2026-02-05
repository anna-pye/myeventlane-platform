<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Scope;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_analytics\Phase7\Exception\AccessDeniedAnalyticsException;
use Drupal\myeventlane_analytics\Phase7\Exception\InvalidScopeException;
use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;

/**
 * Phase 7 scope resolver (fail-closed).
 *
 * Responsibilities:
 * - Vendor scope: derive EXACTLY ONE store ID server-side.
 * - Admin scope: validate permission + require provided store IDs.
 *
 * Notes:
 * - This class MUST NOT log. Guard is the only place that logs violations.
 * - This class MUST NOT load entities; it resolves store IDs only.
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
final class AnalyticsScopeResolver implements AnalyticsScopeResolverInterface {

  /**
   * Permission required to allow admin scope queries.
   *
   * This is intentionally a high-privilege permission to prevent vendor
   * escalation. Adjust only with explicit Phase 7 approval.
   */
  private const ADMIN_SCOPE_PERMISSION = 'administer commerce_store';

  /**
   * Additional admin permission accepted for analytics admin scope.
   *
   * Existing analytics controllers treat this as an admin override.
   */
  private const ADMIN_SCOPE_PERMISSION_ALT = 'administer event attendees';

  /**
   * Constructs the resolver.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolveEffectiveStoreIds(AnalyticsQuery $query): array {
    return match ($query->scope) {
      AnalyticsQuery::SCOPE_VENDOR => $this->resolveVendorStoreIds(),
      AnalyticsQuery::SCOPE_ADMIN => $this->resolveAdminStoreIds($query),
      default => throw new InvalidScopeException('Invalid analytics scope.'),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function isAdminScopeAllowed(int $uid): bool {
    // Fail-closed: only evaluate the current user context.
    if ((int) $this->currentUser->id() !== $uid) {
      return FALSE;
    }

    // UID 1 is always allowed.
    if ($uid === 1) {
      return TRUE;
    }

    return $this->currentUser->hasPermission(self::ADMIN_SCOPE_PERMISSION)
      || $this->currentUser->hasPermission(self::ADMIN_SCOPE_PERMISSION_ALT);
  }

  /**
   * Resolves vendor store IDs for the current user.
   *
   * Returns all stores owned by the current user. Callers (e.g. VendorKpiService)
   * filter results by the specific store they need when the vendor has multiple.
   *
   * @return list<int>
   *   Store IDs owned by the current user.
   *
   * @throws \Drupal\myeventlane_analytics\Phase7\Exception\AccessDeniedAnalyticsException
   *   If no store can be resolved.
   */
  private function resolveVendorStoreIds(): array {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      throw new AccessDeniedAnalyticsException('You must be logged in.');
    }

    $store_storage = $this->entityTypeManager->getStorage('commerce_store');

    // Mirror existing MEL vendor context resolution: store owner UID + online.
    $store_ids = $store_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('type', 'online')
      ->execute();

    if (empty($store_ids)) {
      throw new AccessDeniedAnalyticsException('No vendor store found for your account.');
    }

    $ids = [];
    foreach ($store_ids as $id) {
      $id = (int) $id;
      if ($id > 0) {
        $ids[$id] = TRUE;
      }
    }
    $effective = array_keys($ids);
    sort($effective, SORT_NUMERIC);
    /** @var list<int> $effective */
    return $effective;
  }

  /**
   * Resolves admin store IDs after permission validation.
   *
   * @param \Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery $query
   *   The query.
   *
   * @return list<int>
   *   Validated store IDs.
   *
   * @throws \Drupal\myeventlane_analytics\Phase7\Exception\AccessDeniedAnalyticsException
   *   If admin scope is not permitted or store IDs are missing/invalid.
   */
  private function resolveAdminStoreIds(AnalyticsQuery $query): array {
    $uid = (int) $this->currentUser->id();
    if (!$this->isAdminScopeAllowed($uid)) {
      throw new AccessDeniedAnalyticsException('Admin scope is not permitted for this account.');
    }

    if ($query->store_ids === []) {
      throw new AccessDeniedAnalyticsException('Admin scope requires one or more store IDs.');
    }

    $ids = [];
    foreach ($query->store_ids as $store_id) {
      $store_id = (int) $store_id;
      if ($store_id <= 0) {
        throw new AccessDeniedAnalyticsException('Invalid store ID in admin scope.');
      }
      $ids[$store_id] = TRUE;
    }

    $effective = array_keys($ids);
    sort($effective, SORT_NUMERIC);
    /** @var list<int> $effective */
    return $effective;
  }

}

