<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_core\Unit;

use Drupal\myeventlane_core\Service\LanguageStyleService;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_core\Service\LanguageStyleService
 * @group myeventlane_core
 */
final class LanguageStyleServiceTest extends TestCase {

  /**
   * @covers ::looksLikeHtml
   * @covers ::replace
   */
  public function testHtmlBlobsAreDetectedAndVendorPathsSurviveSkip(): void {
    $service = new LanguageStyleService();
    $html = '<p><a href="https://example.test/vendors/anna">Contact organiser</a></p>';

    $this->assertTrue($service->looksLikeHtml($html));
    $this->assertSame('organiser', $service->replace('vendor'));
    $this->assertStringContainsString(
      '/organisers/',
      $service->replace($html),
      'Bare replace() corrupts vendor paths inside HTML (documented hazard).'
    );

    $preserved = $html;
    if (!$service->looksLikeUrl($preserved) && !$service->looksLikeHtml($preserved)) {
      $preserved = $service->replace($preserved);
    }
    $this->assertSame($html, $preserved);
    $this->assertStringContainsString('/vendors/anna', $preserved);
  }

  /**
   * @covers ::looksLikeUrl
   */
  public function testUrlStringsAreDetected(): void {
    $service = new LanguageStyleService();
    $this->assertTrue($service->looksLikeUrl('https://example.test/vendors/anna'));
    $this->assertTrue($service->looksLikeUrl('/vendors/anna'));
    $this->assertFalse($service->looksLikeUrl('Browse vendors'));
  }

}
