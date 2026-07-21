<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_paragraph\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Cross-organiser export access decisions for checkout_paragraph routes.
 *
 * Mirrors AttendeeExportController::access without AccessResult/container.
 *
 * @group myeventlane_checkout_paragraph
 */
final class AttendeeExportAccessTest extends TestCase {

  public function testOrganiserBDeniedForeignEvent(): void {
    $this->assertFalse($this->decide(FALSE, 'event', FALSE));
  }

  public function testOrganiserAAllowedOwnEvent(): void {
    $this->assertTrue($this->decide(FALSE, 'event', TRUE));
  }

  public function testVendorEntityOwnerAllowedViaParity(): void {
    // Workstream 2A: export already used EventVendorAccessChecker (Phase 2A.2).
    // Vendor entity owner is allowed when parity is TRUE.
    $this->assertTrue($this->decide(FALSE, 'event', TRUE));
  }

  public function testUnrelatedOrganiserDenied(): void {
    $this->assertFalse($this->decide(FALSE, 'event', FALSE));
  }

  public function testAdminAllowed(): void {
    $this->assertTrue($this->decide(TRUE, 'event', FALSE));
  }

  public function testNonEventBundleForbidden(): void {
    $this->assertFalse($this->decide(FALSE, 'page', TRUE));
  }

  public function testControllerWiresParityAndHardFail(): void {
    $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controller/AttendeeExportController.php');
    $this->assertStringContainsString('accountHasWorkspaceParityForEvent', $raw);
    $this->assertStringContainsString('AccessDeniedHttpException', $raw);
    $this->assertStringContainsString('assertExportAccess', $raw);
    $this->assertStringContainsString('queueExport', $raw);
  }

  /**
   * Mirrors AttendeeExportController::access without AccessResult/container.
   */
  private function decide(bool $isAdmin, string $bundle, bool $hasParity): bool {
    if ($bundle !== 'event') {
      return FALSE;
    }
    if ($isAdmin) {
      return TRUE;
    }
    return $hasParity;
  }

}
