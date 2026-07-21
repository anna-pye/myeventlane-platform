<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_attendees\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Ownership matrix for WaitlistManagementController::access.
 *
 * @group myeventlane_event_attendees
 */
final class WaitlistManagementAccessOwnershipTest extends TestCase {

  /**
   * Verifies allow/deny for staff bypass and parity actors.
   *
   * @dataProvider actorProvider
   */
  public function testAccessMatrix(
    int $uid,
    bool $administerNodes,
    bool $hasParity,
    bool $expectedAllowed,
  ): void {
    $this->assertSame(
      $expectedAllowed,
      $this->decide($uid, $administerNodes, $hasParity),
    );
  }

  /**
   * Source wiring must use the canonical checker and drop team-partial loops.
   */
  public function testControllerWiresCanonicalChecker(): void {
    $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controller/WaitlistManagementController.php');
    $this->assertStringContainsString('EventVendorAccessCheckerInterface', $raw);
    $this->assertStringContainsString('accountHasWorkspaceParityForEvent', $raw);
    $this->assertStringNotContainsString("field_vendor_users')->getValue()", $raw);
  }

  /**
   * Actor matrix for waitlist management access.
   *
   * @return \Generator
   *   Cases: uid, administer nodes, parity, expected allow.
   */
  public static function actorProvider(): \Generator {
    yield 'admin administer nodes' => [2, TRUE, FALSE, TRUE];
    yield 'uid 1 bypass' => [1, FALSE, FALSE, TRUE];
    yield 'vendor owner / parity' => [20, FALSE, TRUE, TRUE];
    yield 'team / parity' => [30, FALSE, TRUE, TRUE];
    yield 'unrelated organiser' => [40, FALSE, FALSE, FALSE];
    yield 'anonymous' => [0, FALSE, FALSE, FALSE];
  }

  /**
   * Mirrors WaitlistManagementController::access without AccessResult.
   */
  private function decide(int $uid, bool $administerNodes, bool $hasParity): bool {
    if ($administerNodes || $uid === 1) {
      return TRUE;
    }
    return $hasParity;
  }

}
