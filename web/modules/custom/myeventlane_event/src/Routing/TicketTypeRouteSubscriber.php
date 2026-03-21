<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Restricts standalone mel_ticket_type add form to admins only.
 *
 * Organisers must create tiers via EventTicketsBuilder / workspace form (TicketTierLifecycleService).
 * The entity add route, if present, is admin-only maintenance.
 */
final class TicketTypeRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('entity.mel_ticket_type.add_form');
    if ($route) {
      $route->setRequirement('_permission', 'administer mel_ticket_type entities');
    }
  }

}
