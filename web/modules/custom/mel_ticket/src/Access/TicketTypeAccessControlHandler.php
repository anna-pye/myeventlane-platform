<?php

declare(strict_types=1);

namespace Drupal\mel_ticket\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Ticket type access: admin, public view of published types, vendor ownership.
 */
final class TicketTypeAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\mel_ticket\Entity\TicketTypeInterface $entity */
    if ($account->hasPermission('administer mel_ticket_type entities')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $is_owner = (int) $entity->get('vendor_id')->target_id === (int) $account->id();

    return match ($operation) {
      'view' => AccessResult::allowedIf($entity->isPublished() || $is_owner)
        ->cachePerUser()
        ->addCacheableDependency($entity),

      'update', 'assign' => AccessResult::allowedIf($is_owner && $account->hasPermission('edit own mel_ticket_type entities'))
        ->cachePerPermissions()
        ->addCacheableDependency($entity),

      'delete' => AccessResult::allowedIf($is_owner && $account->hasPermission('delete own mel_ticket_type entities'))
        ->cachePerPermissions()
        ->addCacheableDependency($entity),

      default => AccessResult::neutral()->cachePerPermissions()->addCacheableDependency($entity),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['create mel_ticket_type entities'], FALSE);
  }

}
