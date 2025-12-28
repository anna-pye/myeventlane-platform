<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Access control for boost routes.
 *
 * Enforces:
 * - Owner OR admin
 * - Event published = TRUE
 * - Vendor has Stripe connected
 */
final class BoostRouteAccess {

  /**
   * Checks access to boost route.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public static function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    $node = $route_match->getParameter('node');

    if (!$node instanceof NodeInterface) {
      \Drupal::logger('myeventlane_boost')->debug('Boost access denied: invalid node parameter');
      return AccessResult::forbidden()->cachePerUser();
    }

    // Admin override.
    if ($account->hasPermission('administer myeventlane')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Must be owner.
    if ((int) $node->getOwnerId() !== (int) $account->id()) {
      \Drupal::logger('myeventlane_boost')->debug('Boost access denied: user @uid is not owner of event @nid', [
        '@uid' => $account->id(),
        '@nid' => $node->id(),
      ]);
      return AccessResult::forbidden()->cachePerUser();
    }

    // Must be published.
    if (!$node->isPublished()) {
      \Drupal::logger('myeventlane_boost')->debug('Boost access denied: event @nid is not published', [
        '@nid' => $node->id(),
      ]);
      return AccessResult::forbidden('Event must be published to boost.')
        ->cachePerUser()
        ->addCacheableDependency($node);
    }

    // Must have Stripe connected.
    $hasStripe = self::checkStripeConnection($account);
    if (!$hasStripe) {
      \Drupal::logger('myeventlane_boost')->debug('Boost access denied: user @uid does not have Stripe connected', [
        '@uid' => $account->id(),
      ]);
      return AccessResult::forbidden('Stripe account must be connected to boost events.')
        ->cachePerUser();
    }

    return AccessResult::allowed()
      ->cachePerUser()
      ->addCacheableDependency($node);
  }

  /**
   * Checks if user has Stripe Connect account configured.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return bool
   *   TRUE if Stripe is connected, FALSE otherwise.
   */
  private static function checkStripeConnection(AccountInterface $account): bool {
    $userId = (int) $account->id();
    if ($userId === 0) {
      return FALSE;
    }

    try {
      $entityTypeManager = \Drupal::entityTypeManager();

      // Check commerce_store for Stripe account ID.
      $storeStorage = $entityTypeManager->getStorage('commerce_store');
      $storeIds = $storeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $userId)
        ->range(0, 1)
        ->execute();

      if (!empty($storeIds)) {
        $store = $storeStorage->load(reset($storeIds));
        if ($store && $store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
          $accountId = $store->get('field_stripe_account_id')->value;
          // Check if account is actually connected (not just pending).
          if ($store->hasField('field_stripe_connected')) {
            return (bool) $store->get('field_stripe_connected')->value;
          }
          // If field doesn't exist, assume connected if account ID exists.
          return !empty($accountId);
        }
      }

      // Fallback: check vendor entity.
      $vendorStorage = $entityTypeManager->getStorage('myeventlane_vendor');
      $vendorIds = $vendorStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $userId)
        ->range(0, 1)
        ->execute();

      if (!empty($vendorIds)) {
        $vendor = $vendorStorage->load(reset($vendorIds));
        if ($vendor && $vendor->hasField('field_stripe_account_id') && !$vendor->get('field_stripe_account_id')->isEmpty()) {
          $accountId = $vendor->get('field_stripe_account_id')->value;
          return !empty($accountId);
        }
      }
    }
    catch (\Exception $e) {
      // Log error but don't break access check.
      \Drupal::logger('myeventlane_boost')->warning('Error checking Stripe connection for user @uid: @message', [
        '@uid' => $userId,
        '@message' => $e->getMessage(),
      ]);
    }

    return FALSE;
  }

}
