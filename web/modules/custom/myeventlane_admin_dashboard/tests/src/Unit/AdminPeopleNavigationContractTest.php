<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_admin_dashboard\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards customer, vendor, consent, and filtering access from the PCC.
 *
 * @group myeventlane_admin_dashboard
 */
final class AdminPeopleNavigationContractTest extends TestCase {

  /**
   * Confirms the operational people views remain reachable and searchable.
   */
  public function testPeopleViewsAreInPlatformControlNavigation(): void {
    $modulePath = dirname(__DIR__, 3);
    $navigation = file_get_contents($modulePath . '/src/Plugin/Block/MelAdminSidebarNavBlock.php');
    $routes = file_get_contents($modulePath . '/myeventlane_admin_dashboard.routing.yml');
    $customers = file_get_contents($modulePath . '/templates/platform-control-centre--customers.html.twig');
    $vendors = file_get_contents($modulePath . '/templates/platform-control-centre--vendors.html.twig');
    $consents = file_get_contents($modulePath . '/templates/platform-control-centre--consents.html.twig');

    self::assertIsString($navigation);
    self::assertIsString($routes);
    self::assertIsString($customers);
    self::assertIsString($vendors);
    self::assertIsString($consents);
    self::assertStringContainsString("'myeventlane_admin_dashboard.customers' => 'Customers'", $navigation);
    self::assertStringContainsString("'myeventlane_admin_dashboard.vendors' => 'Vendors'", $navigation);
    self::assertStringContainsString("'myeventlane_admin_dashboard.consents' => 'Consent history'", $navigation);
    self::assertStringContainsString("path: '/admin/myeventlane/customers'", $routes);
    self::assertStringContainsString("path: '/admin/myeventlane/consents'", $routes);
    self::assertStringContainsString('name="search"', $customers);
    self::assertStringContainsString('name="relationship"', $customers);
    self::assertStringContainsString('name="search"', $vendors);
    self::assertStringContainsString('name="status"', $vendors);
    self::assertStringContainsString('name="search"', $consents);
    self::assertStringContainsString('name="source"', $consents);
    self::assertStringNotContainsString('ip_address', $consents);
    self::assertStringNotContainsString('user_agent', $consents);
    self::assertStringNotContainsString('session_id', $consents);
  }

}
