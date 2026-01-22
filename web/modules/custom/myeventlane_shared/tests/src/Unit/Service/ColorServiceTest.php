<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_shared\Unit\Service;

use Drupal\myeventlane_shared\Service\ColorService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ColorService.
 *
 * @coversDefaultClass \Drupal\myeventlane_shared\Service\ColorService
 * @group myeventlane
 * @group myeventlane_shared
 */
final class ColorServiceTest extends TestCase {

  /**
   * The color service under test.
   *
   * @var \Drupal\myeventlane_shared\Service\ColorService
   */
  protected $colorService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->colorService = new ColorService();
  }

  /**
   * Tests that getCategoryColors returns expected color map.
   *
   * @covers ::getCategoryColors
   */
  public function testGetCategoryColorsReturnsExpectedMap(): void {
    $colors = $this->colorService->getCategoryColors();

    // Verify structure is an array.
    $this->assertIsArray($colors);

    // Verify expected keys exist.
    $this->assertArrayHasKey('music', $colors);
    $this->assertArrayHasKey('workshop', $colors);
    $this->assertArrayHasKey('food-drink', $colors);
    $this->assertArrayHasKey('lgbtq', $colors);
    $this->assertArrayHasKey('arts', $colors);
    $this->assertArrayHasKey('movie', $colors);
    $this->assertArrayHasKey('community', $colors);

    // Verify specific color values.
    $this->assertEquals('#A8C8FF', $colors['music']);
    $this->assertEquals('#FFE2B2', $colors['workshop']);
    $this->assertEquals('#FBC6C6', $colors['food-drink']);
    $this->assertEquals('#D6C6FF', $colors['lgbtq']);
    $this->assertEquals('#B7F1DC', $colors['arts']);
    $this->assertEquals('#F4B9EF', $colors['movie']);
    $this->assertEquals('#BBE9F3', $colors['community']);
  }

  /**
   * Tests getColorForTerm with various term name formats.
   *
   * @covers ::getColorForTerm
   */
  public function testGetColorForTermReturnsCorrectColor(): void {
    // Test with spaces and ampersand.
    $this->assertEquals('#FBC6C6', $this->colorService->getColorForTerm('Food & Drink'));
    $this->assertEquals('#FBC6C6', $this->colorService->getColorForTerm('food & drink'));

    // Test with lowercase.
    $this->assertEquals('#BBE9F3', $this->colorService->getColorForTerm('community'));
    $this->assertEquals('#BBE9F3', $this->colorService->getColorForTerm('Community'));

    // Test with title case.
    $this->assertEquals('#FFE2B2', $this->colorService->getColorForTerm('Workshop'));
    $this->assertEquals('#FFE2B2', $this->colorService->getColorForTerm('workshop'));

    // Test with hyphenated.
    $this->assertEquals('#FBC6C6', $this->colorService->getColorForTerm('food-drink'));

    // Test with special characters.
    $this->assertEquals('#D6C6FF', $this->colorService->getColorForTerm('LGBTQ+'));
    $this->assertEquals('#D6C6FF', $this->colorService->getColorForTerm('lgbtqi+'));
  }

  /**
   * Tests getColorForTerm returns NULL for unknown terms.
   *
   * @covers ::getColorForTerm
   */
  public function testGetColorForTermReturnsNullOnUnknown(): void {
    $this->assertNull($this->colorService->getColorForTerm('Unknown Category'));
    $this->assertNull($this->colorService->getColorForTerm('NonExistent'));
    $this->assertNull($this->colorService->getColorForTerm(''));
  }

  /**
   * Tests getFallbackColor returns a valid color.
   *
   * @covers ::getFallbackColor
   */
  public function testGetFallbackColorReturnsValidColor(): void {
    $fallback = $this->colorService->getFallbackColor();
    $this->assertIsString($fallback);
    $this->assertStringStartsWith('#', $fallback);
    // #RRGGBB format
    $this->assertEquals(7, strlen($fallback));
  }

}
