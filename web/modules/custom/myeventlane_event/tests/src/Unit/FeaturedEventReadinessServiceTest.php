<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event\Service\BoostedEventQualityGate;
use Drupal\myeventlane_event\Service\FeaturedEventReadinessService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event\Service\FeaturedEventReadinessService
 * @group myeventlane_event
 */
final class FeaturedEventReadinessServiceTest extends UnitTestCase {

  /**
   * @covers ::isReady
   * @covers ::getReadinessChecklist
   */
  public function testIncompleteEventNeedsAttention(): void {
    $event = $this->createEventMock([
      'published' => FALSE,
      'field_event_start' => NULL,
    ]);
    $service = $this->createService(hero: FALSE, focal: FALSE);

    self::assertFalse($service->isReady($event));
    $checklist = $service->getReadinessChecklist($event);
    self::assertSame('Readable hero image missing', $checklist[0]['label_incomplete']);
    self::assertSame('Focal point missing', $checklist[1]['label_incomplete']);
    self::assertSame('Needs attention before homepage promotion', $service->getPresentation($event)['status_label']);
  }

  /**
   * @covers ::isReady
   */
  public function testReadyEventWhenRequiredChecksPass(): void {
    $event = $this->createEventMock([
      'published' => TRUE,
      'field_event_start' => '2026-12-01T10:00:00',
      'field_event_summary' => 'Summary',
      'field_category' => '1',
      'field_location' => '123 Main St',
    ]);
    $service = $this->createService(hero: TRUE, focal: TRUE);

    self::assertTrue($service->isReady($event));
    self::assertSame('Ready for homepage promotion', $service->getPresentation($event)['status_label']);
  }

  /**
   * @param array<string, mixed> $values
   */
  private function createEventMock(array $values): NodeInterface {
    $fields = [
      'field_event_start',
      'field_event_summary',
      'field_category',
      'field_location',
      'field_venue',
      'field_venue_name',
    ];

    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('isPublished')->willReturn((bool) ($values['published'] ?? FALSE));
    $event->method('getCacheTags')->willReturn(['node:1']);
    $event->method('hasField')->willReturnCallback(
      static fn (string $field): bool => in_array($field, $fields, TRUE),
    );
    $event->method('get')->willReturnCallback(function (string $field) use ($values): FieldItemListInterface {
      $fieldList = $this->createMock(FieldItemListInterface::class);
      $raw = $values[$field] ?? NULL;
      $fieldList->method('isEmpty')->willReturn($raw === NULL || $raw === '');
      if ($field === 'field_event_summary' && is_string($raw)) {
        $fieldList->value = $raw;
      }
      if ($field === 'field_venue_name' && is_string($raw)) {
        $fieldList->method('value')->willReturn($raw);
      }
      return $fieldList;
    });

    return $event;
  }

  private function createService(bool $hero, bool $focal): FeaturedEventReadinessService {
    $qualityGate = $this->createMock(BoostedEventQualityGate::class);
    $qualityGate->method('hasHeroImage')->willReturn($hero);
    $qualityGate->method('hasFocalPoint')->willReturn($focal);
    $qualityGate->method('isMarketplaceReady')->willReturn($hero && $focal);

    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')->willReturnCallback(
      static fn (string $string): string => $string,
    );

    return new FeaturedEventReadinessService($qualityGate, $translation);
  }

}
