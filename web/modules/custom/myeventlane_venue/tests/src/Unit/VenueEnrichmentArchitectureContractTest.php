<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_venue\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the approved first-slice boundaries.
 */
#[Group('myeventlane_venue')]
final class VenueEnrichmentArchitectureContractTest extends TestCase {

  /**
   * Tests access control and the lack of a runtime map-provider dependency.
   */
  public function testSuggestionsAreAccessCheckedAndProviderIndependent(): void {
    $root = dirname(__DIR__, 3);
    $routing = file_get_contents($root . '/myeventlane_venue.routing.yml');
    $services = file_get_contents($root . '/myeventlane_venue.services.yml');
    $repository = file_get_contents($root . '/src/Service/OverturePlaceRepository.php');

    self::assertIsString($routing);
    self::assertStringContainsString('myeventlane_venue.suggestions:', $routing);
    self::assertStringContainsString("_custom_access: 'myeventlane_vendor.access.vendor_console:access'", $routing);
    self::assertIsString($services);
    self::assertStringContainsString("'@database'", $services);
    self::assertStringNotContainsString("'@http_client'", $services);
    self::assertIsString($repository);
    self::assertStringContainsString('Queries the locally imported', $repository);
  }

  /**
   * Tests field-by-field review and the excluded enrichment fields.
   */
  public function testReviewIsFieldByFieldAndExcludesImagesAndDescriptions(): void {
    $root = dirname(__DIR__, 3);
    $javascript = file_get_contents($root . '/js/quick-create.js');
    $form = file_get_contents($root . '/src/Form/VenueQuickCreateForm.php');
    $controller = file_get_contents($root . '/src/Controller/VendorVenuesController.php');
    $template = file_get_contents($root . '/templates/myeventlane-venue-vendor-list.html.twig');

    self::assertIsString($javascript);
    self::assertStringContainsString("this.button(Drupal.t('Use'))", $javascript);
    self::assertStringContainsString("'data-mel-address' => 'field_location'", $form);
    self::assertStringContainsString('data-overture-accepted-fields', $form);
    self::assertStringContainsString('$this->setRequestStack($request_stack);', $form);
    self::assertStringNotContainsString('protected RequestStack $requestStack', $form);
    self::assertStringNotContainsString("['image',", $javascript);
    self::assertStringNotContainsString("['description',", $javascript);
    self::assertIsString($controller);
    self::assertStringContainsString("Url::fromRoute('myeventlane_venue.quick_create')", $controller);
    self::assertIsString($template);
    self::assertStringContainsString('class="use-ajax mel-btn mel-btn--primary"', $template);
  }

  /**
   * Tests Event Studio venue profiles converge with canonical locations.
   */
  public function testVenueProfilePrefillsAddressAndOffersReviewedDetails(): void {
    $root = dirname(__DIR__, 3);
    $form = file_get_contents($root . '/src/Form/VenueForm.php');
    $manager = file_get_contents($root . '/src/Service/VenueManager.php');
    $install = file_get_contents($root . '/myeventlane_venue.install');
    $javascript = file_get_contents($root . '/js/quick-create.js');

    self::assertIsString($form);
    self::assertStringContainsString("'#title' => \$this->t('Search address')", $form);
    self::assertStringContainsString("'currentVenueId' =>", $form);
    self::assertStringContainsString("'data-overture-accepted-fields' => 'true'", $form);
    self::assertStringContainsString('applyEnrichmentProvenance', $form);
    self::assertStringContainsString('syncPrimaryLocation', $form);

    self::assertIsString($manager);
    self::assertStringContainsString("'primary_address' => trim((string) (\$locationData['address_text'] ?? ''))", $manager);
    self::assertStringContainsString('public function syncPrimaryLocation(', $manager);

    self::assertIsString($install);
    self::assertStringContainsString('function myeventlane_venue_update_10004', $install);
    self::assertStringContainsString("\$venue->set('primary_address', trim(\$address))", $install);

    self::assertIsString($javascript);
    self::assertStringContainsString("url.searchParams.set('exclude_venue_id', settings.currentVenueId)", $javascript);
  }

}
