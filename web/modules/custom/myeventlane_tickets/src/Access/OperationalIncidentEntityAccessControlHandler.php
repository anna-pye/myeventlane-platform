<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access for operational coordination entities (not tickets or scanner truth).
 */
final class OperationalIncidentEntityAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $parent = parent::checkAccess($entity, $operation, $account);
    if ($parent->isAllowed()) {
      return $parent;
    }

    if ($account->isAnonymous()) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    return match ($operation) {
      'view', 'view label' => $this->viewAccess($account),
      'update', 'delete' => $this->manageAccess($account),
      default => $parent,
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    $parent = parent::checkCreateAccess($account, $context, $entity_bundle);
    if ($parent->isAllowed()) {
      return $parent;
    }
    return $this->manageAccess($account);
  }

  private function viewAccess(AccountInterface $account): AccessResult {
    if ($account->hasPermission('manage mel operational incidents')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    if ($account->hasPermission('view mel venue operations workspace')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    return AccessResult::forbidden()->cachePerPermissions();
  }

  private function manageAccess(AccountInterface $account): AccessResult {
    if ($account->hasPermission('manage mel operational incidents')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    return AccessResult::forbidden()->cachePerPermissions();
  }

}
