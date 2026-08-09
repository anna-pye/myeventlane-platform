<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects public event inclusion in the XML sitemap.
 *
 * @group myeventlane_front
 */
final class XmlSitemapControllerContractTest extends TestCase {

  /**
   * Confirms the sitemap filters events through the canonical SEO policy.
   */
  public function testSitemapUsesCanonicalSeoIndexableEvents(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $controller = (string) file_get_contents($moduleRoot . '/src/Controller/XmlSitemapController.php');

    $this->assertStringContainsString("->condition('type', 'event')", $controller);
    $this->assertStringContainsString('publicEventVisibility->isSeoIndexable', $controller);
    $this->assertStringContainsString("toUrl('canonical', ['absolute' => TRUE])", $controller);
  }

}
