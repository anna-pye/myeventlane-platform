<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_event\Service\EventModeManager;
use Drupal\myeventlane_refunds\Service\RefundAccessResolver;
use Drupal\node\NodeInterface;

/**
 * Route access for vendor approve/reject refund request forms.
 *
 * Enforces:
 * - Event parameter present and bundle = event
 * - Vendor can manage event (owner + vendor team)
 * - Event has paid tickets enabled (refund requests apply only to ticketed events)
 */
final class VendorRefundRequestAccessCheck {

  public function __construct(
    private readonly RefundAccessResolver $accessResolver,
    private readonly EventModeManager $eventModeManager,
  ) {}

  /**
   * Checks access for vendor refund request approve/reject routes.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $node = $route_match->getParameter('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return AccessResult::forbidden('Event parameter required.');
    }

    $eventAccess = $this->accessResolver->accessManageEvent($node, $account);
    if (!$eventAccess->isAllowed()) {
      return $eventAccess;
    }

    if (!$this->eventModeManager->isTicketsEnabled($node)) {
      return AccessResult::forbidden('Refund requests apply to ticketed events only.')
        ->cachePerUser()
        ->addCacheableDependency($node);
    }

    return $eventAccess;
  }

}
