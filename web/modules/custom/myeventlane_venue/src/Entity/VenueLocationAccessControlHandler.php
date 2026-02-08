<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for Venue Location entities.
 *
 * Location access delegates to the parent venue access.
 */
class VenueLocationAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if (!$entity instanceof VenueLocation) {
      return AccessResult::neutral();
    }

    // Administrators can do anything.
    if ($account->hasPermission('administer myeventlane venues')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $venue = $entity->getVenue();
    if (!$venue) {
      return AccessResult::forbidden('Location has no parent venue.')
        ->addCacheableDependency($entity);
    }

    // Delegate to venue access.
    return $venue->access($operation, $account, TRUE)
      ->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    // Administrators can create locations.
    if ($account->hasPermission('administer myeventlane venues')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Users with venue access can create locations (venue ownership check
    // happens in the form/controller).
    return AccessResult::allowedIfHasPermission($account, 'access vendor venues')
      ->cachePerPermissions();
  }

}
