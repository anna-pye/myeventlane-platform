<?php

namespace Drupal\myeventlane_vendor\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;

/**
 * Access control handler for Vendor entities.
 *
 * Enforces strict ownership: users can only access their own vendor entity.
 */
class VendorAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if (!$entity instanceof Vendor) {
      return AccessResult::neutral();
    }

    // Administrators can do anything.
    if ($account->hasPermission('administer myeventlane vendor')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    switch ($operation) {
      case 'view':
        // Public viewing is allowed for all authenticated users.
        return AccessResult::allowedIfHasPermission($account, 'access content');

      case 'update':
      case 'delete':
        // HARD OWNERSHIP CHECK: Only the owner can update/delete their vendor.
        $ownerId = (int) $entity->getOwnerId();
        $accountId = (int) $account->id();

        if ($ownerId > 0 && $ownerId === $accountId) {
          return AccessResult::allowed()
            ->cachePerUser()
            ->addCacheableDependency($entity);
        }

        // Deny access if not the owner.
        return AccessResult::forbidden('You can only modify your own vendor entity.')
          ->cachePerUser()
          ->addCacheableDependency($entity);
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Administrators can create vendors.
    if ($account->hasPermission('administer myeventlane vendor')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Regular users can create their own vendor (enforced by 1:1 constraint).
    // The form validation and preSave() will prevent duplicates.
    return AccessResult::allowedIfHasPermission($account, 'access content')
      ->cachePerPermissions();
  }

}
