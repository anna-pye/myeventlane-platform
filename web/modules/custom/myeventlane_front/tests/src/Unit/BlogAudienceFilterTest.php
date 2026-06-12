<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @group myeventlane_front
 */
final class BlogAudienceFilterTest extends TestCase {

  public function testMelBlogAudienceAlterIsWiredInModuleHook(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_front.module');
    $this->assertIsString($source);
    $this->assertStringContainsString('myeventlane_front.blog_audience_filter', $source);
    $this->assertStringContainsString('alterMelBlogView', $source);
  }

  public function testStructuredDataBreadcrumbUsesBlogLandingRoute(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/BlogStructuredDataBuilder.php');
    $this->assertIsString($source);
    $this->assertStringContainsString("Url::fromRoute('myeventlane_front.blog_landing'", $source);
    $this->assertStringNotContainsString("Url::fromRoute('view.mel_blog.page_blog'", $source);
  }

  public function testResourceDownloadsAreNotPlaybookGatedInPresentation(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/BlogArticlePresentationService.php');
    $this->assertIsString($source);
    $this->assertStringContainsString('$resourceDownloads = $this->resourceDownloads->build($node);', $source);
    $this->assertStringNotContainsString('$isPlaybook ? $this->resourceDownloads->build($node)', $source);
  }

}
