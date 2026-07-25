<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_vendor\Service\VendorSettingsHubBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_vendor\Service\VendorSettingsHubBuilder
 *
 * @group myeventlane_vendor
 */
final class VendorSettingsHubBuilderTest extends UnitTestCase {

  /**
   * @covers ::section
   */
  public function testSectionOmitsLinksWithoutUrls(): void {
    $builder = $this->createBuilderWithoutConstructor();
    $method = new \ReflectionMethod(VendorSettingsHubBuilder::class, 'section');
    $method->setAccessible(TRUE);

    /** @var array<string, mixed> $section */
    $section = $method->invoke(
      $builder,
      'brand',
      'Brand',
      'Body',
      'Manage brand',
      '/vendor/settings/profile#visual-assets',
      [
        ['label' => 'Messages brand', 'url' => '/vendor/dashboard/messaging/brand'],
        ['label' => 'Missing', 'url' => NULL],
      ],
      [
        'future' => ['Brand colour themes'],
      ],
    );

    $this->assertSame('brand', $section['id']);
    $this->assertCount(1, $section['links']);
    $this->assertSame('Messages brand', $section['links'][0]['label']);
    $this->assertSame(['Brand colour themes'], $section['future']);
  }

  /**
   * Instantiates the builder without running its service constructor.
   */
  private function createBuilderWithoutConstructor(): VendorSettingsHubBuilder {
    $reflection = new \ReflectionClass(VendorSettingsHubBuilder::class);
    /** @var \Drupal\myeventlane_vendor\Service\VendorSettingsHubBuilder $builder */
    $builder = $reflection->newInstanceWithoutConstructor();
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')->willReturnCallback(
      static fn(string $string, array $args = [], array $options = []) => strtr($string, $args),
    );
    $builder->setStringTranslation($translation);
    return $builder;
  }

}
