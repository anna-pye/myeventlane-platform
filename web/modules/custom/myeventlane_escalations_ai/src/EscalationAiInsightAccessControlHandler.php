<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control: staff-only; deny update/delete to enforce append-only.
 */
final class EscalationAiInsightAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($operation === 'view') {
      return AccessResult::allowedIfHasPermission($account, 'view escalation ai insights');
    }
    // Deny update/delete to enforce append-only.
    if ($operation === 'update' || $operation === 'delete') {
      return AccessResult::forbidden('AI insights are append-only.');
    }
    if ($operation === 'create') {
      return AccessResult::allowedIfHasPermission($account, 'view escalation ai insights');
    }
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'view escalation ai insights');
  }

}
