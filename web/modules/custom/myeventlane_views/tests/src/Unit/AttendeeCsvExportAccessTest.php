<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_views\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Cross-organiser decisions for legacy attendee CSV export access.
 *
 * Mirrors AttendeeCsvExportAccess without AccessResult cacheability (no container).
 *
 * @group myeventlane_views
 */
final class AttendeeCsvExportAccessTest extends TestCase {

  public function testForeignEventDownloadDenied(): void {
    $this->assertFalse($this->decideDownload(FALSE, TRUE, TRUE));
  }

  public function testOwnedEventDownloadAllowed(): void {
    $this->assertTrue($this->decideDownload(FALSE, TRUE, TRUE, TRUE));
  }

  public function testMissingEventDownloadForbiddenWithoutDisclosure(): void {
    $this->assertFalse($this->decideDownload(FALSE, TRUE, FALSE));
  }

  public function testAnonymousDenied(): void {
    $this->assertFalse($this->decideDownload(FALSE, FALSE, TRUE, TRUE));
  }

  public function testAdminAllowed(): void {
    $this->assertTrue($this->decideDownload(TRUE, FALSE, FALSE));
  }

  public function testAccessClassWiresParityAndNoAccessContent(): void {
    $access = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Access/AttendeeCsvExportAccess.php');
    $routing = (string) file_get_contents(dirname(__DIR__, 3) . '/myeventlane_views.routing.yml');
    $this->assertStringContainsString('accountHasWorkspaceParityForEvent', $access);
    $this->assertStringContainsString('_custom_access', $routing);
    $this->assertStringNotContainsString("access content", $routing);
  }

  public function testLegacyListRedirectsToCanonicalEventPortfolio(): void {
    $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controller/AttendeeCsvController.php');
    $this->assertStringContainsString("new LocalRedirectResponse('/vendor/events')", $controller);
    $this->assertStringNotContainsString("views_embed_view('attendee_answer'", $controller);
  }

  /**
   * Mirrors AttendeeCsvExportAccess download branch.
   */
  private function decideDownload(
    bool $isAdmin,
    bool $authenticated,
    bool $eventExists,
    bool $hasParity = FALSE,
  ): bool {
    if ($isAdmin) {
      return TRUE;
    }
    if (!$authenticated) {
      return FALSE;
    }
    if (!$eventExists) {
      return FALSE;
    }
    return $hasParity;
  }

}
