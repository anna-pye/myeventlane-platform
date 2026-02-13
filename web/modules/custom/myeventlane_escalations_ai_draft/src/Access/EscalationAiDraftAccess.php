<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai_draft\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access check for escalation AI draft route.
 *
 * Ensures route is admin-only and escalation exists.
 */
final class EscalationAiDraftAccess {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks access to the AI draft generation endpoint.
   */
  public function draftAccess(AccountInterface $account, int $escalation): AccessResultInterface {
    if (!$account->hasPermission('generate escalation ai drafts')) {
      return AccessResult::forbidden()->cachePerUser();
    }

    $entity = $this->entityTypeManager->getStorage('escalation')->load($escalation);
    if (!$entity) {
      return AccessResult::forbidden('Escalation not found.');
    }

    return AccessResult::allowed()->addCacheableDependency($entity)->cachePerUser();
  }

}
