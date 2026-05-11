<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Access handler for append-only redemption audit logs.
 */
final class RedemptionLogAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($operation === 'view') {
      if ($account->hasPermission('administer myeventlane tickets') || $account->hasPermission('administer all events tickets')) {
        return AccessResult::allowed()->cachePerPermissions();
      }

      if (!$account->hasPermission('check in tickets')) {
        return AccessResult::forbidden('Redemption logs are operational records.')
          ->cachePerPermissions();
      }

      $event = $this->loadEvent($entity);
      if (!$event instanceof NodeInterface) {
        return AccessResult::forbidden('Redemption log has no resolvable event scope.')
          ->cachePerPermissions()
          ->cachePerUser();
      }

      if ($this->accountHasEventScope($event, $account) || $this->accountHasVendorScope($entity, $event, $account)) {
        return AccessResult::allowed()
          ->cachePerPermissions()
          ->cachePerUser()
          ->addCacheableDependency($event);
      }

      return AccessResult::forbidden('Redemption log is outside the account event/vendor scope.')
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($event);
    }

    if (in_array($operation, ['update', 'delete'], TRUE)) {
      return AccessResult::forbidden('Redemption logs are append-only operational audit records.')
        ->cachePerPermissions();
    }

    return parent::checkAccess($entity, $operation, $account);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermissions($account, [
      'administer myeventlane tickets',
      'check in tickets',
    ], 'OR')->cachePerPermissions();
  }

  /**
   * Loads the event scope captured on a redemption log.
   */
  private function loadEvent(EntityInterface $entity): ?NodeInterface {
    if (!$entity->hasField('event_id') || $entity->get('event_id')->isEmpty()) {
      return NULL;
    }

    $event = $entity->get('event_id')->entity;
    return $event instanceof NodeInterface && $event->bundle() === 'event' ? $event : NULL;
  }

  /**
   * TRUE when the account owns the event scope.
   */
  private function accountHasEventScope(NodeInterface $event, AccountInterface $account): bool {
    return (int) $event->getOwnerId() === (int) $account->id();
  }

  /**
   * TRUE when the account belongs to the vendor scope captured on the log/event.
   */
  private function accountHasVendorScope(EntityInterface $entity, NodeInterface $event, AccountInterface $account): bool {
    $vendor = NULL;
    if ($entity->hasField('vendor_id') && !$entity->get('vendor_id')->isEmpty()) {
      $vendor = $entity->get('vendor_id')->entity;
    }
    elseif ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
    }
    if (!$vendor instanceof EntityInterface) {
      return FALSE;
    }

    if ($vendor->hasField('uid') && (int) $vendor->get('uid')->target_id === (int) $account->id()) {
      return TRUE;
    }

    if ($vendor->hasField('field_vendor_users')) {
      foreach ($vendor->get('field_vendor_users') as $item) {
        if ((int) $item->target_id === (int) $account->id()) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

}
