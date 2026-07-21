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

  public function testListRoutesUseEventTicketsAccess(): void {
    $routes = Yaml::parseFile(dirname(__DIR__, 3) . '/myeventlane_tickets.routing.yml');
    foreach ([
      'myeventlane_tickets.event_tickets_groups',
      'myeventlane_tickets.event_tickets_access_codes',
      'myeventlane_tickets.event_tickets_widgets',
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
