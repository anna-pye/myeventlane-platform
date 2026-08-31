<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Ensures ticket list/resend routes use the event-scoped access model.
 *
 * @group myeventlane_tickets
 */
final class TicketListRoutingAccessTest extends TestCase {

  /**
   * Ensures event list routes share the event-scoped access check.
   */
  public function testListRoutesUseEventTicketsAccess(): void {
    $routes = Yaml::parseFile(dirname(__DIR__, 3) . '/myeventlane_tickets.routing.yml');
    foreach ([
      'myeventlane_tickets.event_tickets_groups',
      'myeventlane_tickets.event_tickets_access_codes',
      'myeventlane_tickets.event_tickets_widgets',
      'myeventlane_tickets.event_tickets_widgets_add',
      'myeventlane_tickets.event_tickets_widgets_edit',
      'entity.mel_ticket_group.delete_form',
      'entity.mel_access_code.delete_form',
      'entity.mel_purchase_surface.delete_form',
    ] as $name) {
      $this->assertArrayHasKey($name, $routes);
      $this->assertSame(
        'myeventlane_tickets.access.event_tickets:access',
        $routes[$name]['requirements']['_custom_access'] ?? NULL,
        $name,
      );
      $this->assertArrayNotHasKey('_permission', $routes[$name]['requirements'] ?? [], $name);
    }
  }

  /**
   * Ensures public widget scripts use safely constrained tokens.
   */
  public function testPublicWidgetScriptUsesAnOpaqueToken(): void {
    $routes = Yaml::parseFile(dirname(__DIR__, 3) . '/myeventlane_tickets.routing.yml');
    $route = $routes['myeventlane_tickets.purchase_surface_script'];

    $this->assertSame('/mel-widget/tickets/{token}.js', $route['path'] ?? NULL);
    $this->assertSame('access content', $route['requirements']['_permission'] ?? NULL);
    $this->assertSame('[A-Za-z0-9 _-]{8,64}', $route['requirements']['token'] ?? NULL);
  }

  /**
   * Ensures ticket resend remains protected from cross-event requests.
   */
  public function testResendRequiresPermissionOwnershipAndCsrf(): void {
    $routes = Yaml::parseFile(dirname(__DIR__, 3) . '/myeventlane_tickets.routing.yml');
    $resend = $routes['myeventlane_tickets.ticket_resend'];
    $this->assertSame('resend ticket emails', $resend['requirements']['_permission'] ?? NULL);
    $this->assertSame(
      'myeventlane_tickets.access.ticket_operations:accessResend',
      $resend['requirements']['_custom_access'] ?? NULL,
    );
    $this->assertSame('TRUE', $resend['requirements']['_csrf_token'] ?? NULL);
  }

}
