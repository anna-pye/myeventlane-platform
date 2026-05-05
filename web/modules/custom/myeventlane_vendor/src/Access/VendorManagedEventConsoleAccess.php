<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;

/**
 * Vendor console access plus managed-event workspace parity for {event} routes.
 */
final class VendorManagedEventConsoleAccess {

  public function __construct(
    private readonly VendorConsoleAccess $vendorConsoleAccess,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
  ) {}

  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    $base = $this->vendorConsoleAccess->access($route_match, $account);
    if (!$base->isAllowed()) {
      return $base;
    }

    $event = $route_match->getParameter('event');
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      return AccessResult::forbidden();
    }

    if (!$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
      return AccessResult::forbidden();
    }

    return AccessResult::allowed()->addCacheableDependency($event);
  }

}
