<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for Venue Access entities.
 *
 * Venue access grants are managed by the system, not by vendors.
 */
class VenueAccessAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    // Only administrators can view/update/delete access grants.
    if ($account->hasPermission('administer myeventlane venues')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::forbidden('Venue access grants are managed by the system.')
      ->cachePerPermissions();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    // Only administrators can directly create access grants.
    // Normal users get grants via the share-by-link flow.
    if ($account->hasPermission('administer myeventlane venues')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::forbidden('Use the share link to request access.')
      ->cachePerPermissions();
  }

}
