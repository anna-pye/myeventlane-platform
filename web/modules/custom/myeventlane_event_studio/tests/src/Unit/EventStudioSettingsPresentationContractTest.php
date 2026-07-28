<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the approved Event Studio Settings hierarchy.
 */
final class EventStudioSettingsPresentationContractTest extends TestCase {

  public function testSettingsUsesGuidedVisibilityCardsAndClearPublishingLanguage(): void {
    $settings = file_get_contents(__DIR__ . '/../../../src/Form/EventSettingsForm.php');
    $publishing = file_get_contents(__DIR__ . '/../../../src/Form/EventStudioPublishForm.php');
    $styles = file_get_contents(__DIR__ . '/../../../css/mel-event-studio-shell.css');

    self::assertIsString($settings);
    self::assertIsString($publishing);
    self::assertIsString($styles);

    self::assertStringContainsString("Visibility & access", $settings);
    self::assertStringContainsString('mel-visibility-option__description', $settings);
    self::assertStringContainsString('Choose how people find and access this event.', $settings);
    self::assertStringNotContainsString("visibility_help", $settings);

    self::assertStringContainsString("Publishing status", $publishing);
    self::assertStringContainsString("Move event back to draft", $publishing);
    self::assertStringContainsString("takes effect when you save settings", $publishing);

    self::assertStringContainsString('.mel-visibility-radios .form-item:has(input:checked)', $styles);
    self::assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr))', $styles);
  }

}
