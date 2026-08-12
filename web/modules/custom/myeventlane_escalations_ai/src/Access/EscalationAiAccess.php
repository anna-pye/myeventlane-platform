<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai\Access;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_escalations\Entity\EscalationInterface;

/**
 * Enforces access to the escalation used for an AI action.
 */
final class EscalationAiAccess {

  /**
   * Checks whether the account may view the target escalation.
   */
  public function draftAccess(AccountInterface $account, EscalationInterface $escalation): AccessResultInterface {
    return $escalation->access('view', $account, TRUE);
  }

}
