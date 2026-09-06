<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Gives signed-out and signed-in visitors a clear contact pathway.
 */
final class HelpContactController extends ControllerBase {

  /**
   * Builds the access-aware help content.
   */
  public function build(): array {
    return [
      '#theme' => 'help_contact',
      '#bookings_url' => $this->currentUser()->isAuthenticated()
        ? Url::fromRoute('myeventlane_checkout_flow.my_tickets')
        : Url::fromRoute('user.login', [], ['query' => ['destination' => '/my-tickets']]),
      '#support_url' => $this->currentUser()->hasPermission('view own escalation')
        ? Url::fromRoute('myeventlane_escalations_portal.customer_support_tickets')
        : Url::fromRoute('user.login', [], ['query' => ['destination' => '/support/tickets']]),
      '#contact_url' => Url::fromUserInput('/contact'),
      '#cache' => ['contexts' => ['user.permissions', 'user.roles:authenticated']],
    ];
  }

}
