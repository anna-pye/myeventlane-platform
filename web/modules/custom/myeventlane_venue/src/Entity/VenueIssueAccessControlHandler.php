<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for Venue Issue entities.
 *
 * Any authorized viewer can create issues. Owner/admin can view/manage.
 */
class VenueIssueAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if (!$entity instanceof VenueIssue) {
      return AccessResult::neutral();
    }

    // Administrators can do anything.
    if ($account->hasPermission('administer myeventlane venues')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $venue = $entity->getVenue();
    if (!$venue) {
      return AccessResult::forbidden('Issue has no parent venue.')
        ->addCacheableDependency($entity);
    }

    switch ($operation) {
      case 'view':
        // Venue owner can view issues about their venue.
        if ((int) $venue->getOwnerId() === (int) $account->id()) {
          return AccessResult::allowed()
            ->cachePerUser()
            ->addCacheableDependency($venue)
            ->addCacheableDependency($entity);
        }
        // The reporter can view their own issue.
        if ((int) $entity->getOwnerId() === (int) $account->id()) {
          return AccessResult::allowed()
            ->cachePerUser()
            ->addCacheableDependency($entity);
        }
        break;

      case 'update':
      case 'delete':
        // Only venue owner or admin can manage issues.
        if ((int) $venue->getOwnerId() === (int) $account->id()) {
          return AccessResult::allowed()
            ->cachePerUser()
            ->addCacheableDependency($venue)
            ->addCacheableDependency($entity);
        }
        break;
    }

    return AccessResult::forbidden()
      ->cachePerUser()
      ->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    // Users with flag permission can create issues.
    return AccessResult::allowedIfHasPermission($account, 'flag venue issues')
      ->cachePerPermissions();
  }

}
