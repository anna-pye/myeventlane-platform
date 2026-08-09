<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects metadata on the public HTML sitemap.
 *
 * @group myeventlane_front
 */
final class HtmlSitemapMetadataContractTest extends TestCase {

  /**
   * Confirms the sitemap provides a useful search description.
   */
  public function testSitemapAttachesMetaDescription(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $controller = (string) file_get_contents($moduleRoot . '/src/Controller/SitemapController.php');

    self::assertStringContainsString("'name' => 'description'", $controller);
    self::assertStringContainsString('Explore MyEventLane events, organiser resources', $controller);
  }

}
