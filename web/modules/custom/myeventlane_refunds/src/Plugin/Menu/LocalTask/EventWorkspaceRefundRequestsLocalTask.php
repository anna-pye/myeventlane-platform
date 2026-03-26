<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Plugin\Menu\LocalTask;

use Drupal\Core\Menu\LocalTaskDefault;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;

/**
 * Refund requests tab on vendor event workspace (maps {event} to {node}).
 *
 * @MenuLocalTask(
 *   id = "myeventlane_refunds.event_workspace_refund_requests",
 *   route_name = "myeventlane_refunds.vendor_refund_requests",
 *   title = @Translation("Refund requests"),
 *   base_route = "myeventlane_vendor.console.event_overview",
 *   weight = -9,
 * )
 */
final class EventWorkspaceRefundRequestsLocalTask extends LocalTaskDefault {

  /**
   * {@inheritdoc}
   */
  public function getRouteParameters(RouteMatchInterface $route_match) {
    $event = $route_match->getParameter('event');
    $nid = $event instanceof NodeInterface ? $event->id() : $event;
    return ['node' => $nid];
  }

}
