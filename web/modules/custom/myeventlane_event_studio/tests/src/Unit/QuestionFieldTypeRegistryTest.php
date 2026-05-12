<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_studio\Service\QuestionFieldTypeRegistry;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/src/Service/QuestionFieldTypeRegistry.php';

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\QuestionFieldTypeRegistry
 *
 * @group myeventlane_event_studio
 */
final class QuestionFieldTypeRegistryTest extends TestCase {

  /**
   * @covers ::normalize
   * @covers ::isLegacy
   * @covers ::options
   */
  public function testRegistryKeepsCanonicalStorageValuesStable(): void {
    $registry = $this->registry();

    $this->assertSame('textfield', $registry->normalize('text'));
    $this->assertSame('checkboxes', $registry->normalize('checkbox'));
    $this->assertSame('radios', $registry->normalize('radio'));
    $this->assertSame('tel', $registry->normalize('tel'));
    $this->assertTrue($registry->isLegacy('tel'));
    $this->assertArrayNotHasKey('tel', $registry->options());
    $this->assertArrayHasKey('tel', $registry->options(TRUE));
  }

  /**
   * @covers ::requiresOptions
   * @covers ::supportsValidation
   */
  public function testRegistryDeclaresCapabilitiesWithoutApplicability(): void {
    $registry = $this->registry();

    $this->assertTrue($registry->requiresOptions('select'));
    $this->assertTrue($registry->requiresOptions('checkbox'));
    $this->assertFalse($registry->requiresOptions('email'));
    $this->assertTrue($registry->supportsValidation('number'));
  }

  private function registry(): QuestionFieldTypeRegistry {
    $registry = new QuestionFieldTypeRegistry();
    $registry->setStringTranslation($this->translator());
    return $registry;
  }

  private function translator(): TranslationInterface {
    $translator = $this->createMock(TranslationInterface::class);
    $translator
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    return $translator;
  }

}
