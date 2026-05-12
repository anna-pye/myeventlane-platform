<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioQuestionTemplateManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 3) . '/src/Service/EventStudioQuestionTemplateManager.php';

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\EventStudioQuestionTemplateManager
 *
 * @group myeventlane_event_studio
 */
final class EventStudioQuestionTemplateManagerTest extends TestCase {

  /**
   * @covers ::normalizeType
   */
  public function testNormalizeTypeMapsGovernedAliases(): void {
    $manager = $this->manager();

    $this->assertSame('textfield', $manager->normalizeType('text'));
    $this->assertSame('checkboxes', $manager->normalizeType('checkbox'));
    $this->assertSame('radios', $manager->normalizeType('radio'));
    $this->assertSame('number', $manager->normalizeType('number'));
    $this->assertSame('textfield', $manager->normalizeType('made_up'));
  }

  /**
   * @covers ::applicabilityOptions
   */
  public function testApplicabilityOptionsIncludePerOrderMetadataOption(): void {
    $manager = $this->manager();

    $this->assertArrayHasKey(EventStudioQuestionTemplateManager::APPLIES_PER_ORDER, $manager->applicabilityOptions());
  }

  /**
   * @covers ::normalizeOptions
   */
  public function testNormalizeOptionsTrimsAndDeduplicatesLines(): void {
    $manager = $this->manager();

    $this->assertSame(['VIP', 'General'], $manager->normalizeOptions(" VIP \n\nGeneral\nVIP"));
  }

  /**
   * @covers ::normalizeTicketTypeIds
   */
  public function testNormalizeTicketTypeIdsAcceptsCheckboxShapes(): void {
    $manager = $this->manager();

    $this->assertSame([10, 20], $manager->normalizeTicketTypeIds([
      10 => '10',
      20 => 20,
      30 => 0,
    ]));
  }

  private function manager(): EventStudioQuestionTemplateManager {
    $manager = new EventStudioQuestionTemplateManager(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerInterface::class),
    );
    $manager->setStringTranslation($this->translator());
    return $manager;
  }

  private function translator(): TranslationInterface {
    $translator = $this->createMock(TranslationInterface::class);
    $translator
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    return $translator;
  }

}
