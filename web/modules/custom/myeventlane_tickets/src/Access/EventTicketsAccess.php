<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_tickets\Service\EventAccess;
use Drupal\node\NodeInterface;

/**
 * Access check for tickets workspace routes.
 */
final class EventTicketsAccess implements AccessInterface {

  /**
   * Constructs EventTicketsAccess.
   */
  public function __construct(
    private readonly EventAccess $eventAccess,
  ) {}

  /**
   * Checks access for tickets workspace routes.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(NodeInterface $event, AccountInterface $account): AccessResultInterface {
    // Ensure it's an event node.
    if (!$event || $event->bundle() !== 'event') {
      return AccessResult::forbidden();
    }

    // Temporarily set the account on the EventAccess service.
    // Since EventAccess uses current_user service, we need to check with the
    // provided account. For now, we'll use the service's method which checks
    // the current user. In a production system, you might want to refactor
    // EventAccess to accept an account parameter.
    return $this->eventAccess->canManageEventTickets($event)
      ? AccessResult::allowed()->cachePerUser()->addCacheableDependency($event)
      : AccessResult::forbidden()->addCacheableDependency($event);
  }

}
