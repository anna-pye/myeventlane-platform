<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for Boost entitlements.
 */
final class BoostEntitlementAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer myeventlane boost')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if ($operation === 'view') {
      $owner_id = (int) ($entity->get('uid')->target_id ?? 0);
      if ($owner_id > 0 && $owner_id === (int) $account->id()) {
        return AccessResult::allowed()->cachePerUser()->cachePerPermissions();
      }
    }

    return AccessResult::forbidden()->cachePerPermissions()->cachePerUser();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'administer myeventlane boost');
  }

}
