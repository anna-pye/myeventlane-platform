<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the Event Studio venue replacement contract.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioVenueResetContractTest extends TestCase {

  /**
   * Both supported forms expose a non-submitting reset control.
   */
  public function testBothVenueFormsExposeTheResetControl(): void {
    $module_root = dirname(__DIR__, 3);
    $information_form = (string) file_get_contents($module_root . '/src/Form/EventInformationForm.php');
    $legacy_form = (string) file_get_contents($module_root . '/src/Form/EventStudioForm.php');

    foreach ([$information_form, $legacy_form] as $form) {
      self::assertStringContainsString("'#value' => \$this->t('Reset venue')", $form);
      self::assertStringContainsString("'data-mel-reset-venue' => '1'", $form);
      self::assertStringContainsString("'type' => 'button'", $form);
    }
    self::assertStringContainsString('mel_event_studio_workspace_location', $legacy_form);
  }

  /**
   * Reset invalidates the visible and canonical venue fields together.
   */
  public function testResetClearsAllCanonicalVenueState(): void {
    $module_root = dirname(__DIR__, 3);
    $script = (string) file_get_contents($module_root . '/js/mel-event-studio-workspace-location.js');

    foreach ([
      'mel[venue_saved]',
      'mel[venue_create_name]',
      'mel[location_search]',
      'mel[field_location]',
      'mel[field_location_latitude]',
      'mel[field_location_longitude]',
    ] as $field_name) {
      self::assertStringContainsString($field_name, $script);
    }
    self::assertStringContainsString("input.addEventListener('input'", $script);
    self::assertStringContainsString("input.addEventListener('place_selected'", $script);
  }

  /**
   * Saved venue changes take coordinates from the selected primary location.
   */
  public function testSavedVenueCoordinatesComeFromItsPrimaryLocation(): void {
    $module_root = dirname(__DIR__, 3);
    $save_service = (string) file_get_contents($module_root . '/src/Service/EventStudioSaveService.php');

    self::assertStringContainsString('$primary?->getLatitude()', $save_service);
    self::assertStringContainsString('$primary?->getLongitude()', $save_service);
    self::assertStringContainsString('$this->applyCoordinates(', $save_service);
  }

}
