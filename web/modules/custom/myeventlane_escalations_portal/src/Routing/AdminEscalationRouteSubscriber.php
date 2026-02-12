<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_portal\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Overrides the escalation canonical route to use the case console controller.
 */
final class AdminEscalationRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('entity.escalation.canonical')) {
      $route->setDefault('_controller', '\Drupal\myeventlane_escalations_portal\Controller\AdminEscalationViewController::view');
      $route->setDefault('view_mode', 'full');
    }
  }

}
