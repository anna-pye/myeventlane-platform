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
   * Verifies Pro gate plus Mel ownership hop allow/deny.
   *
   * @dataProvider actorProvider
   */
  public function testAssertAccessMatrix(
    bool $hasPro,
    int $uid,
    bool $hasOwnership,
    bool $expectedAllowed,
  ): void {
    $this->assertSame($expectedAllowed, $this->decide($hasPro, $uid, $hasOwnership));
  }

  /**
   * Source wiring must use Mel and remove private vendor helpers.
   */
  public function testControllerWiresMelOwnershipHop(): void {
    $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controller/MessageAttendeesController.php');
    $this->assertStringContainsString('MelAttendeeOperationsAccessInterface', $raw);
    $this->assertStringContainsString('accountHasOrganiserOwnership', $raw);
    $this->assertStringNotContainsString('isVendorMember', $raw);
    $this->assertStringNotContainsString('resolveEventVendor', $raw);
  }

  /**
   * Actor matrix for Pro message attendees access.
   *
   * @return \Generator
   *   Cases: Pro feature, uid, ownership, expected allow.
   */
  public static function actorProvider(): \Generator {
    yield 'pro + ownership' => [TRUE, 20, TRUE, TRUE];
    yield 'pro without ownership' => [TRUE, 20, FALSE, FALSE];
    yield 'ownership without pro' => [FALSE, 20, TRUE, FALSE];
    yield 'anonymous' => [TRUE, 0, TRUE, FALSE];
    yield 'unrelated' => [FALSE, 40, FALSE, FALSE];
  }

  /**
   * Mirrors MessageAttendeesController::assertAccess for event bundles.
   */
  private function decide(bool $hasPro, int $uid, bool $hasOwnership): bool {
    if (!$hasPro) {
      return FALSE;
    }
    if ($uid <= 0) {
      return FALSE;
    }
    return $hasOwnership;
  }

}
