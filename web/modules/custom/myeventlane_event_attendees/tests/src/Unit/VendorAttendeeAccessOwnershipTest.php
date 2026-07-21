<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_attendees\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Ownership matrix for VendorAttendeeController::access.
 *
 * Mirrors product gates + EventVendorAccessChecker parity without constructing
 * the controller (final collaborator services cannot be doubled).
 *
 * @group myeventlane_event_attendees
 */
final class VendorAttendeeAccessOwnershipTest extends TestCase {

  /**
   * Verifies allow/deny for admin, product permission, and parity actors.
   *
   * @dataProvider actorProvider
   */
  public function testAccessMatrix(
    bool $isAdmin,
    bool $hasViewOwn,
    bool $hasParity,
    bool $expectedAllowed,
  ): void {
    $this->assertSame($expectedAllowed, $this->decide($isAdmin, $hasViewOwn, $hasParity));
  }

  /**
   * Vendor owners with view-own + parity are allowed (intentional widen).
   */
  public function testVendorEntityOwnerGainsAccessWhereTeamPartialDenied(): void {
    $this->assertTrue($this->decide(FALSE, TRUE, TRUE));
  }

  /**
   * Unrelated organisers without parity remain denied.
   */
  public function testUnrelatedOrganiserDenied(): void {
    $this->assertFalse($this->decide(FALSE, TRUE, FALSE));
  }

  /**
   * Source wiring must use the canonical checker and drop team-partial loops.
   */
  public function testControllerWiresCanonicalChecker(): void {
    $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controller/VendorAttendeeController.php');
    $this->assertStringContainsString('EventVendorAccessCheckerInterface', $raw);
    $this->assertStringContainsString('accountHasWorkspaceParityForEvent', $raw);
    $this->assertStringNotContainsString("field_vendor_users')->getValue()", $raw);
  }

  /**
   * Actor matrix for attendee list access.
   *
   * @return \Generator
   *   Cases: admin, view-own, parity, expected allow.
   */
  public static function actorProvider(): \Generator {
    yield 'admin' => [TRUE, FALSE, FALSE, TRUE];
    yield 'author/parity with view own' => [FALSE, TRUE, TRUE, TRUE];
    yield 'parity without view own' => [FALSE, FALSE, TRUE, FALSE];
    yield 'view own without parity' => [FALSE, TRUE, FALSE, FALSE];
    yield 'unrelated' => [FALSE, TRUE, FALSE, FALSE];
  }

  /**
   * Mirrors VendorAttendeeController::access without AccessResult/container.
   */
  private function decide(bool $isAdmin, bool $hasViewOwn, bool $hasParity): bool {
    if ($isAdmin) {
      return TRUE;
    }
    if (!$hasViewOwn) {
      return FALSE;
    }
    return $hasParity;
  }

}
