<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_vendor\Service\VendorMarketingHubBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_vendor\Service\VendorMarketingHubBuilder
 *
 * @group myeventlane_vendor
 */
final class VendorMarketingHubBuilderTest extends UnitTestCase {

  /**
   * @covers ::computeMarketingScore
   */
  public function testMarketingScoreWeightsVisibilitySignals(): void {
    $builder = $this->createBuilderWithoutConstructor();
    $method = new \ReflectionMethod(VendorMarketingHubBuilder::class, 'computeMarketingScore');
    $method->setAccessible(TRUE);

    $this->assertSame(0, $method->invoke($builder, 0, FALSE, FALSE, FALSE, FALSE));
    $this->assertSame(35, $method->invoke($builder, 1, FALSE, FALSE, FALSE, FALSE));
    $this->assertSame(60, $method->invoke($builder, 1, TRUE, FALSE, FALSE, FALSE));
    $this->assertSame(100, $method->invoke($builder, 1, TRUE, TRUE, TRUE, TRUE));
  }

  /**
   * Instantiates the builder without mocking final collaborators.
   */
  private function createBuilderWithoutConstructor(): VendorMarketingHubBuilder {
    $reflection = new \ReflectionClass(VendorMarketingHubBuilder::class);
    /** @var \Drupal\myeventlane_vendor\Service\VendorMarketingHubBuilder $builder */
    $builder = $reflection->newInstanceWithoutConstructor();
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')->willReturnCallback(
      static fn(string $string, array $args = [], array $options = []) => strtr($string, $args),
    );
    $builder->setStringTranslation($translation);
    return $builder;
  }

}
