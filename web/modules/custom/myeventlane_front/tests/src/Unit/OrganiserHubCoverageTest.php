<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @group myeventlane_front
 */
final class OrganiserHubCoverageTest extends TestCase {

  /**
   * Public category coverage must count published, access-checked nodes only.
   */
  public function testCategoryCoverageQueryUsesPublishedAccessCheckedNodes(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/OrganiserHubEditorialService.php');
    $this->assertIsString($source);
    $this->assertStringContainsString('public function getCategoryCoverage(): array', $source);
    $this->assertStringContainsString("->accessCheck(TRUE)", $source);
    $this->assertStringContainsString("->condition('status', 1)", $source);
    $this->assertStringNotContainsString("->accessCheck(FALSE)", $source);
  }

}
