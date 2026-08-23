<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards Stripe redirects from Drupal's reserved destination override.
 */
final class StripeConnectReturnToContractTest extends TestCase {

  public function testControllerUsesAndValidatesNonReservedReturnTo(): void {
    $root = dirname(__DIR__, 3);
    $controller = file_get_contents($root . '/src/Controller/StripeConnectController.php');

    self::assertIsString($controller);
    self::assertStringContainsString("\$query['return_to'] = \$returnTo", $controller);
    self::assertStringContainsString("\$request->query->get('return_to')", $controller);
    self::assertStringContainsString("\$request->query->remove('destination')", $controller);
    self::assertStringContainsString("str_starts_with(\$value, '/vendor/')", $controller);
    self::assertStringContainsString('public function manage(Request $request)', $controller);
    self::assertStringNotContainsString("\$query['destination'] = \$destination", $controller);
  }

  public function testOrganiserConnectLinksUseReturnTo(): void {
    $root = dirname(__DIR__, 3);
    $sources = [
      $root . '/src/Service/VendorPaymentsHealthService.php',
      $root . '/src/Service/VendorActionQueueBuilder.php',
      $root . '/src/Service/VendorDashboardViewModelBuilder.php',
      $root . '/src/Controller/VendorDashboardController.php',
    ];

    foreach ($sources as $sourcePath) {
      $source = file_get_contents($sourcePath);
      self::assertIsString($source);
      self::assertStringContainsString("'return_to' => '/vendor/", $source);
    }
  }

}
