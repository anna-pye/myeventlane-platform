<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;

/**
 * Centralized event access checks for ticket workspace routes.
 */
final class EventAccess {

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly EventVendorAccessCheckerInterface $eventVendorAccessChecker,
  ) {}

  /**
   * Whether the current user may manage tickets for the event.
   */
  public function canManageEventTickets(NodeInterface $event): bool {
    // Admin override.
    if ($this->currentUser->hasPermission('administer all events tickets')) {
      return TRUE;
    }
    if (!$this->currentUser->hasPermission('manage own events tickets')) {
      return FALSE;
    }

    return $this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $this->currentUser);
  }

}
