<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Ownership assert for MessageAttendeesController via Mel facade.
 *
 * @group myeventlane_pro
 */
final class MessageAttendeesOwnershipTest extends TestCase {

  /**
   * Verifies ownership hop allow/deny (Pro stub gate removed in VX2-06).
   *
   * @dataProvider actorProvider
   */
  public function testAssertAccessMatrix(
    int $uid,
    bool $hasOwnership,
    bool $expectedAllowed,
  ): void {
    $this->assertSame($expectedAllowed, $this->decide($uid, $hasOwnership));
  }

  /**
   * Source wiring must use Mel and redirect into Messages compose.
   */
  public function testControllerWiresMelOwnershipHop(): void {
    $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controller/MessageAttendeesController.php');
    $this->assertStringContainsString('MelAttendeeOperationsAccessInterface', $raw);
    $this->assertStringContainsString('accountHasOrganiserOwnership', $raw);
    $this->assertStringContainsString('myeventlane_vendor.console.event_promotion', $raw);
    $this->assertStringNotContainsString('isVendorMember', $raw);
    $this->assertStringNotContainsString('resolveEventVendor', $raw);
    $this->assertStringNotContainsString('ProAccessService', $raw);
  }

  /**
   * Actor matrix for Messages redirect access.
   *
   * @return \Generator
   *   Cases: uid, ownership, expected allow.
   */
  public static function actorProvider(): \Generator {
    yield 'ownership' => [20, TRUE, TRUE];
    yield 'without ownership' => [20, FALSE, FALSE];
    yield 'anonymous' => [0, TRUE, FALSE];
    yield 'unrelated' => [40, FALSE, FALSE];
  }

  /**
   * Mirrors MessageAttendeesController::assertAccess for event bundles.
   */
  private function decide(int $uid, bool $hasOwnership): bool {
    if ($uid <= 0) {
      return FALSE;
    }
    return $hasOwnership;
  }

}
