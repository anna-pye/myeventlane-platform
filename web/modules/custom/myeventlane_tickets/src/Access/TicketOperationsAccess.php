<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\EventAccess;

/**
 * Access checks for ticket operations requiring event ownership/vendor access.
 */
final class TicketOperationsAccess implements AccessInterface {

  public function __construct(
    private readonly EventAccess $eventAccess,
  ) {}

  /**
   * Access for resend/check-in operations by ticket.
   */
  public function accessResend(Ticket $myeventlane_ticket, AccountInterface $account): AccessResultInterface {
    $event = $myeventlane_ticket->get('event_id')->entity;
    if (!$event) {
      return AccessResult::forbidden();
    }

    if (!$account->hasPermission('resend ticket emails') && !$account->hasPermission('administer myeventlane tickets')) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    if ($this->eventAccess->canManageEventTickets($event)) {
      return AccessResult::allowed()->cachePerUser()->addCacheableDependency($event);
    }

    return AccessResult::forbidden()->cachePerUser()->addCacheableDependency($event);
  }

}
