<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access handler for append-only redemption audit logs.
 */
final class RedemptionLogAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($operation === 'view') {
      return AccessResult::allowedIfHasPermissions($account, [
        'administer myeventlane tickets',
        'check in tickets',
      ], 'OR')->cachePerPermissions();
    }

    if (in_array($operation, ['update', 'delete'], TRUE)) {
      return AccessResult::forbidden('Redemption logs are append-only operational audit records.');
    }

    return parent::checkAccess($entity, $operation, $account);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermissions($account, [
      'administer myeventlane tickets',
      'check in tickets',
    ], 'OR')->cachePerPermissions();
  }

}
