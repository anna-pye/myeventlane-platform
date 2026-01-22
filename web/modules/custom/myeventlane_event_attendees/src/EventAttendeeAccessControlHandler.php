<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for the event_attendee entity.
 */
class EventAttendeeAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\myeventlane_event_attendees\Entity\EventAttendee $entity */

    // Admin permission grants full access.
    if ($account->hasPermission('administer event attendees')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $event = $entity->getEvent();
    $isOwner = $event && (int) $event->getOwnerId() === (int) $account->id();

    switch ($operation) {
      case 'view':
        if ($isOwner && $account->hasPermission('view own event attendees')) {
          return AccessResult::allowed()
            ->cachePerUser()
            ->addCacheableDependency($entity);
        }
        break;

      case 'update':
      case 'delete':
        if ($isOwner && $account->hasPermission('manage own event attendees')) {
          return AccessResult::allowed()
            ->cachePerUser()
            ->addCacheableDependency($entity);
        }
        break;
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Only admins can manually create attendees via the admin UI.
    // Normal creation happens through RSVP forms and checkout.
    return AccessResult::allowedIfHasPermission($account, 'administer event attendees');
  }

}
