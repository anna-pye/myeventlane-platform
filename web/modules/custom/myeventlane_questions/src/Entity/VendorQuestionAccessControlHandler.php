<?php

declare(strict_types=1);

namespace Drupal\myeventlane_questions\Entity;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Access control handler for VendorQuestion entities.
 */
class VendorQuestionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    /** @var \Drupal\myeventlane_questions\Entity\VendorQuestionInterface $entity */

    // Admin has full access.
    if ($account->hasPermission('administer site configuration')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Anonymous users have no access.
    if ($account->isAnonymous()) {
      return AccessResult::forbidden('Anonymous users cannot access vendor questions.')->cachePerPermissions();
    }

    // For view/update/delete, check store ownership.
    if (in_array($operation, ['view', 'update', 'delete'], TRUE)) {
      $store = $entity->getStore();
      if (!$store) {
        return AccessResult::forbidden('Question has no store assigned.')->cachePerPermissions();
      }

      // Get vendor for current user.
      $vendor_storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
      $vendor_ids = $vendor_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $account->id())
        ->range(0, 1)
        ->execute();

      if (empty($vendor_ids)) {
        return AccessResult::forbidden('User has no vendor.')->cachePerPermissions();
      }

      $vendor = $vendor_storage->load(reset($vendor_ids));
      if (!$vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
        return AccessResult::forbidden('Vendor has no store.')->cachePerPermissions();
      }

      $vendor_store = $vendor->get('field_vendor_store')->entity;
      if ($vendor_store && $vendor_store->id() === $store->id()) {
        return AccessResult::allowed()->cachePerPermissions()->addCacheableDependency($entity);
      }

      return AccessResult::forbidden('Question belongs to a different store.')->cachePerPermissions();
    }

    // For create, check permission.
    if ($operation === 'create') {
      return AccessResult::allowedIfHasPermission($account, 'manage vendor question library')->cachePerPermissions();
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    // Anonymous users cannot create.
    if ($account->isAnonymous()) {
      return AccessResult::forbidden('Anonymous users cannot create vendor questions.')->cachePerPermissions();
    }

    // Admin has full access.
    if ($account->hasPermission('administer site configuration')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::allowedIfHasPermission($account, 'manage vendor question library')->cachePerPermissions();
  }

}
