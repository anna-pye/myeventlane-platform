<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Plugin\Menu\LocalTask;

use Drupal\Core\Menu\LocalTaskDefault;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;

/**
 * Boost tab on vendor event workspace (maps {event} to {node} for Boost routes).
 *
 * @MenuLocalTask(
 *   id = "myeventlane_boost.event_workspace_boost",
 *   route_name = "myeventlane_boost.boost_page",
 *   title = @Translation("Boost"),
 *   base_route = "myeventlane_vendor.console.event_overview",
 *   weight = 22,
 * )
 */
final class EventWorkspaceBoostLocalTask extends LocalTaskDefault {

  /**
   * {@inheritdoc}
   */
  public function getRouteParameters(RouteMatchInterface $route_match) {
    $event = $route_match->getParameter('event');
    $nid = $event instanceof NodeInterface ? $event->id() : $event;
    return ['node' => $nid];
  }

}
