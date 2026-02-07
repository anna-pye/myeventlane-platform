<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for Venue entities.
 *
 * This is the SINGLE SOURCE OF TRUTH for venue access decisions.
 *
 * Access rules:
 * - View: Allowed if public, or if owner, or if explicit access exists.
 * - Update/Delete: Only allowed for owner.
 * - Use (for event creation): Allowed if view access is allowed.
 */
class VenueAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if (!$entity instanceof Venue) {
      return AccessResult::neutral();
    }

    // Administrators can do anything.
    if ($account->hasPermission('administer myeventlane venues')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $ownerId = (int) $entity->getOwnerId();
    $accountId = (int) $account->id();
    $isOwner = $ownerId > 0 && $ownerId === $accountId;

    switch ($operation) {
      case 'view':
      case 'use':
        return $this->checkViewAccess($entity, $account, $isOwner);

      case 'update':
      case 'delete':
        // Only the owner can update or delete.
        if ($isOwner) {
          return AccessResult::allowed()
            ->cachePerUser()
            ->addCacheableDependency($entity);
        }
        return AccessResult::forbidden('Only the venue owner can modify this venue.')
          ->cachePerUser()
          ->addCacheableDependency($entity);
    }

    return AccessResult::neutral();
  }

  /**
   * Checks view access for a venue.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   * @param bool $isOwner
   *   Whether the account is the owner.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function checkViewAccess(Venue $venue, AccountInterface $account, bool $isOwner): AccessResultInterface {
    // Owner always has access.
    if ($isOwner) {
      return AccessResult::allowed()
        ->cachePerUser()
        ->addCacheableDependency($venue);
    }

    // Public venues are viewable by authenticated users with permission.
    if ($venue->isPublic()) {
      return AccessResult::allowedIfHasPermission($account, 'access vendor venues')
        ->addCacheableDependency($venue);
    }

    // Shared venues: check for explicit access grant.
    if ($this->hasExplicitAccess($venue, $account)) {
      return AccessResult::allowed()
        ->cachePerUser()
        ->addCacheableDependency($venue);
    }

    // No access - venue is not public and user has no explicit grant.
    return AccessResult::forbidden('You do not have access to this venue.')
      ->cachePerUser()
      ->addCacheableDependency($venue);
  }

  /**
   * Checks if the account has explicit access to the venue.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return bool
   *   TRUE if explicit access exists, FALSE otherwise.
   */
  protected function hasExplicitAccess(Venue $venue, AccountInterface $account): bool {
    if ($account->isAnonymous()) {
      return FALSE;
    }

    try {
      /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
      $storage = \Drupal::entityTypeManager()->getStorage('myeventlane_venue_access');
      $count = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('venue_id', $venue->id())
        ->condition('uid', $account->id())
        ->count()
        ->execute();

      return $count > 0;
    }
    catch (\Exception $e) {
      // Entity type may not exist during installation.
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    // Administrators can create venues.
    if ($account->hasPermission('administer myeventlane venues')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Users with vendor console access can create venues.
    return AccessResult::allowedIfHasPermission($account, 'access vendor venues')
      ->cachePerPermissions();
  }

}
