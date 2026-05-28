<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Unit;

use Drupal\myeventlane_tickets\Service\OperationalFulfillmentExecutionCustomerDiagnostics;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\OperationalFulfillmentExecutionCustomerDiagnostics
 *
 * @group myeventlane_tickets
 */
final class OperationalFulfillmentExecutionCustomerDiagnosticsTest extends UnitTestCase {

  /**
   * @dataProvider internalDiagnosticTokenProvider
   */
  public function testContainsInternalDiagnosticTokensDetectsAllStripTokens(string $token): void {
    $text = 'Customer note; ' . $token . '=leaked; tail';
    $this->assertTrue(
      OperationalFulfillmentExecutionCustomerDiagnostics::containsInternalDiagnosticTokens($text),
    );
  }

  /**
   * @return array<string, array{string}>
   */
  public static function internalDiagnosticTokenProvider(): array {
    $cases = [];
    foreach (OperationalFulfillmentExecutionCustomerDiagnostics::INTERNAL_DIAGNOSTIC_TOKEN_NAMES as $token) {
      $cases[$token] = [$token];
    }
    return $cases;
  }

  public function testStripThenGuardRemovesKnownDiagnostics(): void {
    $raw = 'pickup_lane=required; redemption_lane=validation_only; timed=slot_a';
    $stripped = OperationalFulfillmentExecutionCustomerDiagnostics::stripInternalExecutionDiagnostics($raw);
    $this->assertSame('', $stripped);
    $this->assertFalse(
      OperationalFulfillmentExecutionCustomerDiagnostics::containsInternalDiagnosticTokens($stripped),
    );
  }

}
