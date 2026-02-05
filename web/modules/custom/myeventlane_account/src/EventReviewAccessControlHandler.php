<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_account\Entity\EventReview;
use Drupal\node\NodeInterface;

/**
 * Access control handler for the event_review entity.
 *
 * - Author can view/edit own review.
 * - Admin can view all.
 * - Vendors can view reviews for their own events only.
 */
class EventReviewAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    assert($entity instanceof EventReview);

    if ($account->hasPermission('administer event reviews')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $isOwner = (int) $entity->getOwnerId() === (int) $account->id();

    switch ($operation) {
      case 'view':
        if ($isOwner) {
          return AccessResult::allowedIfHasPermission($account, 'view own event reviews')
            ->cachePerUser()
            ->addCacheableDependency($entity);
        }
        $event = $entity->getEvent();
        if ($event instanceof NodeInterface && $event->bundle() === 'event') {
          $vendor = $this->getEventVendor($event);
          if ($vendor && method_exists($vendor, 'getOwnerId') && (int) $vendor->getOwnerId() === (int) $account->id()) {
            return AccessResult::allowedIfHasPermission($account, 'view event reviews for own events')
              ->cachePerUser()
              ->addCacheableDependency($entity);
          }
        }
        break;

      case 'update':
      case 'delete':
        if ($isOwner) {
          return AccessResult::allowedIfHasPermission($account, 'edit own event reviews')
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
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'create event reviews');
  }

  /**
   * Gets the vendor entity that owns the event (if any).
   *
   * Uses field_event_vendor or field_vendor.
   * Requires myeventlane_vendor module.
   */
  private function getEventVendor(NodeInterface $event): ?object {
    if (!$this->moduleHandler()->moduleExists('myeventlane_vendor')) {
      return NULL;
    }
    $field = $event->hasField('field_event_vendor') ? 'field_event_vendor' : 'field_vendor';
    if (!$event->hasField($field) || $event->get($field)->isEmpty()) {
      return NULL;
    }
    return $event->get($field)->entity;
  }

}
