<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;

/**
 * Access check for vendor RSVP management routes.
 *
 * Allows access when the user can manage RSVPs for the event:
 * - Admin (`administer rsvps` or `administer nodes`)
 * - Permission `manage own event rsvps` plus workspace parity (author or vendor team)
 *
 * Workspace parity matches {@see EventVendorAccessChecker::accountHasWorkspaceParityForEvent}.
 */
final class VendorEventAccess implements AccessInterface {

  public function __construct(
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $event = $route_match->getParameter('event');
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      return AccessResult::forbidden()->addCacheableDependency($event ?? new \stdClass());
    }

    if ($account->hasPermission('administer rsvps') || $account->hasPermission('administer nodes')) {
      return AccessResult::allowed()->cachePerPermissions()->addCacheableDependency($event);
    }

    if ($account->hasPermission('manage own event rsvps')) {
      if ($this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
        return AccessResult::allowed()->cachePerUser()->addCacheableDependency($event);
      }
    }

    return AccessResult::forbidden()->addCacheableDependency($event);
  }

}
