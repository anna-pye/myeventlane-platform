<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai_draft\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_escalations\Entity\EscalationInterface;

/**
 * Access check for escalation AI draft route.
 *
 * Ensures route is admin-only and escalation exists.
 */
final class EscalationAiDraftAccess {

  /**
   * Checks access to the AI draft generation endpoint.
   */
  public function draftAccess(AccountInterface $account, EscalationInterface $escalation): AccessResultInterface {
    if (!$account->hasPermission('generate escalation ai drafts')) {
      return AccessResult::forbidden()->cachePerUser();
    }

    return $escalation->access('view', $account, TRUE);
  }

}
