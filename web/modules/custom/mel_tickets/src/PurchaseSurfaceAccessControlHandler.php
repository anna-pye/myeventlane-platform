<?php

declare(strict_types=1);

namespace Drupal\mel_tickets;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for Purchase Surface entities.
 */
final class PurchaseSurfaceAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\mel_tickets\Entity\PurchaseSurface $entity */

    // Admin permission grants full access.
    if ($account->hasPermission('administer all events tickets')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Check event access via EventAccess service.
    $event = $entity->getEvent();
    if (!$event) {
      return AccessResult::forbidden('Purchase surface has no associated event.');
    }

    $event_access = \Drupal::service('mel_tickets.event_access');
    if (!$event_access->canManageEventTickets($event)) {
      return AccessResult::forbidden()->cachePerUser()->addCacheableDependency($entity);
    }

    return AccessResult::allowed()
      ->cachePerUser()
      ->addCacheableDependency($entity)
      ->addCacheableDependency($event);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    if ($account->hasPermission('administer all events tickets')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if ($account->hasPermission('manage own events tickets')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::forbidden();
  }

}
