<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Entity;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Delivery access: owners see their rows; platform admins see all.
 */
final class MelNotificationDeliveryAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer mel notifications entities') ||
        $account->hasPermission('administer myeventlane notifications')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if (!$entity instanceof MelNotificationDelivery) {
      return AccessResult::neutral();
    }

    $owner = (int) $entity->get('recipient_uid')->target_id;
    if ($owner > 0 && (int) $account->id() === $owner) {
      return AccessResult::allowed()
        ->addCacheableDependency($entity)
        ->cachePerUser();
    }

    return AccessResult::forbidden()
      ->addCacheableDependency($entity)
      ->cachePerUser();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'administer mel notifications entities')
      ->cachePerPermissions();
  }

}
