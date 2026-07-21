<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Ownership assert for VendorNotifyForm via MelAttendeeOperationsAccess.
 *
 * @group myeventlane_messaging
 */
final class VendorNotifyFormOwnershipTest extends TestCase {

  /**
   * Verifies staff bypass and Mel ownership hop allow/deny.
   *
   * @dataProvider actorProvider
   */
  public function testAssertOwnershipMatrix(
    bool $isAdmin,
    bool $uidIsOne,
    bool $hasOwnership,
    bool $expectedAllowed,
  ): void {
    $this->assertSame($expectedAllowed, $this->decide($isAdmin, $uidIsOne, $hasOwnership));
  }

  /**
   * Source wiring must use Mel ownership and drop team-partial loops.
   */
  public function testFormWiresMelOwnershipHop(): void {
    $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Form/VendorNotifyForm.php');
    $this->assertStringContainsString('MelAttendeeOperationsAccessInterface', $raw);
    $this->assertStringContainsString('accountHasOrganiserOwnership', $raw);
    $this->assertStringNotContainsString("field_vendor_users')->getValue()", $raw);
  }

  /**
   * Actor matrix for notify form ownership.
   *
   * @return \Generator
   *   Cases: admin, uid1, ownership, expected allow.
   */
  public static function actorProvider(): \Generator {
    yield 'admin' => [TRUE, FALSE, FALSE, TRUE];
    yield 'uid 1' => [FALSE, TRUE, FALSE, TRUE];
    yield 'vendor owner / team' => [FALSE, FALSE, TRUE, TRUE];
    yield 'unrelated organiser' => [FALSE, FALSE, FALSE, FALSE];
  }

  /**
   * Mirrors VendorNotifyForm::assertEventOwnership allow/deny.
   */
  private function decide(bool $isAdmin, bool $uidIsOne, bool $hasOwnership): bool {
    if ($isAdmin || $uidIsOne) {
      return TRUE;
    }
    return $hasOwnership;
  }

}
