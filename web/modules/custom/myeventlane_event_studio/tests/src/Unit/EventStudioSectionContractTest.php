<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioEmptyStateBuilder;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/src/Service/EventStudioEmptyStateBuilder.php';

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Attribute\EventStudioSection
 *
 * @group myeventlane_event_studio
 */
final class EventStudioSectionContractTest extends TestCase {

  /**
   * Ensures section plugins cannot drift back to implicit operational state.
   */
  public function testEveryConcreteSectionDeclaresStateAndBuildContractMetadata(): void {
    $pluginDir = dirname(__DIR__, 3) . '/src/Plugin/EventStudioSection';
    $files = glob($pluginDir . '/*Section.php') ?: [];

    $this->assertNotEmpty($files);
    foreach ($files as $file) {
      if (str_ends_with($file, 'EventStudioSection.php') || str_ends_with($file, 'EventStudioSectionBase.php') || str_ends_with($file, 'EventStudioSectionInterface.php')) {
        continue;
      }
      $contents = file_get_contents($file);
      $this->assertIsString($contents);
      $this->assertStringContainsString('section_state:', $contents, basename($file) . ' must declare section_state.');
      $this->assertStringContainsString('renderTarget:', $contents, basename($file) . ' must declare renderTarget.');
      $this->assertStringContainsString('writable:', $contents, basename($file) . ' must declare writable.');
      $this->assertStringContainsString('empty_state_type:', $contents, basename($file) . ' must declare empty_state_type.');
      $this->assertStringContainsString('mobile_priority:', $contents, basename($file) . ' must declare mobile_priority.');
    }
  }

  /**
   * @covers \Drupal\myeventlane_event_studio\Service\EventStudioEmptyStateBuilder::deferredSection
   */
  public function testDeferredSectionsUseGovernedCopy(): void {
    $builder = new EventStudioEmptyStateBuilder($this->translator());

    $merchandise = $builder->deferredSection('Merchandise', 'merchandise');
    $this->assertSame('Merchandise', $merchandise['#title']);
    $this->assertSame('Merchandise will help organisers sell event products without leaving MEL.', $merchandise['#body']);
    $this->assertSame('Planned capability. It will appear here once product ownership, payments, fulfilment, and access rules are ready.', $merchandise['#prompt']);

    $addons = $builder->deferredSection('Add-ons', 'addons');
    $this->assertSame('Add-ons', $addons['#title']);
    $this->assertSame('Add-ons will support optional upgrades such as parking, meals, or experience extras.', $addons['#body']);
    $this->assertSame('Reserved until add-on pricing, checkout, refunds, and attendee reporting are governed end-to-end.', $addons['#prompt']);
  }

  private function translator(): TranslationInterface {
    $translator = $this->createMock(TranslationInterface::class);
    $translator
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    return $translator;
  }

}
