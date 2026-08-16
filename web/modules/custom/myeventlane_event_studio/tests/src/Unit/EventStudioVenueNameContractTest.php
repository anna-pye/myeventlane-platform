<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects venue-name capture and new-venue hand-off contracts.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioVenueNameContractTest extends TestCase {

  /**
   * Both supported forms capture a one-off venue name.
   */
  public function testBothVenueFormsExposeOneOffVenueName(): void {
    $module_root = dirname(__DIR__, 3);
    $information_form = (string) file_get_contents($module_root . '/src/Form/EventInformationForm.php');
    $legacy_form = (string) file_get_contents($module_root . '/src/Form/EventStudioForm.php');

    foreach ([$information_form, $legacy_form] as $form) {
      self::assertStringContainsString("['venue_one_off_name']", $form);
      self::assertStringContainsString("'Venue or location name'", $form);
      self::assertStringContainsString("'#maxlength' => 255", $form);
    }
  }

  /**
   * Place search fills names without replacing organiser-entered text.
   */
  public function testPlaceSearchPreservesManualVenueNames(): void {
    $module_root = dirname(__DIR__, 3);
    $script = (string) file_get_contents($module_root . '/js/mel-event-studio-workspace-location.js');

    self::assertStringContainsString("mode.value === 'create' ? 'venue_create_name' : 'venue_one_off_name'", $script);
    self::assertStringContainsString("field.dataset.melAutofilledPlaceName", $script);
    self::assertStringContainsString("field.value.trim() !== '' && field.value !== previousAutoValue", $script);
    self::assertStringContainsString("applyPlaceName(form, place.name || '')", $script);
  }

  /**
   * A newly-created venue hands the organiser to its protected edit route.
   */
  public function testNewVenueRedirectReturnsToEventStudio(): void {
    $module_root = dirname(__DIR__, 3);
    $information_form = (string) file_get_contents($module_root . '/src/Form/EventInformationForm.php');

    self::assertStringContainsString("'myeventlane_venue.vendor_venue_edit'", $information_form);
    self::assertStringContainsString("'myeventlane_event_studio.workspace_venue'", $information_form);
    self::assertStringContainsString("'query' => ['destination' => \$return_path]", $information_form);
  }

}
