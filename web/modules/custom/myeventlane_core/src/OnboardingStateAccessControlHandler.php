<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Access controller for onboarding state entities.
 */
class OnboardingStateAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    $admin_perm = $this->entityType->getAdminPermission();
    if ($admin_perm && $account->hasPermission($admin_perm)) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    return AccessResult::neutral();
  }

}
