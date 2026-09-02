<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_venue\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the approval, access, ownership and SSRF boundaries.
 */
#[Group('myeventlane_venue')]
final class VenueWebsiteMetadataArchitectureContractTest extends TestCase {

  /**
   * Tests that the venue editor uses the organiser card and action patterns.
   */
  public function testVenueEditorUsesMelOrganiserStructure(): void {
    $root = dirname(__DIR__, 3);
    $form = file_get_contents($root . '/src/Form/VenueForm.php');
    $styles = file_get_contents($root . '/css/quick-create.css');

    self::assertIsString($form);
    self::assertStringContainsString("\$this->t('Settings · Venues')", $form);
    self::assertStringContainsString("\$this->t('Venue details')", $form);
    self::assertStringContainsString("\$this->t('Location and contact')", $form);
    self::assertStringContainsString("\$this->t('Social links')", $form);
    self::assertStringContainsString("hasPermission('administer myeventlane venues')", $form);
    self::assertStringContainsString("'mel-venue-form__actions'", $form);

    self::assertIsString($styles);
    self::assertStringContainsString('.mel-venue-form__section', $styles);
    self::assertStringContainsString('.mel-venue-form__actions', $styles);
    self::assertStringContainsString('@media (width < 576px)', $styles);
  }

  /**
   * Tests that preview and import are separate protected actions.
   */
  public function testRoutesRequireUpdateAccessCsrfAndPost(): void {
    $root = dirname(__DIR__, 3);
    $routing = file_get_contents($root . '/myeventlane_venue.routing.yml');

    self::assertIsString($routing);
    self::assertStringContainsString('myeventlane_venue.website_metadata_preview:', $routing);
    self::assertStringContainsString('myeventlane_venue.website_metadata_import_image:', $routing);
    self::assertGreaterThanOrEqual(2, substr_count($routing, "_entity_access: 'myeventlane_venue.update'"));
    self::assertGreaterThanOrEqual(2, substr_count($routing, "_csrf_request_header_token: 'TRUE'"));
    self::assertGreaterThanOrEqual(2, substr_count($routing, 'methods: [POST]'));
  }

  /**
   * Tests explicit preview, rights confirmation and separate image saving.
   */
  public function testBrowserFlowIsApprovalGated(): void {
    $root = dirname(__DIR__, 3);
    $javascript = file_get_contents($root . '/js/quick-create.js');
    $form = file_get_contents($root . '/src/Form/VenueForm.php');

    self::assertIsString($javascript);
    self::assertStringContainsString("\$this->t('Preview website details')", $form);
    self::assertStringContainsString("Drupal.t('Use description')", $javascript);
    self::assertStringContainsString("Drupal.t('Save image to venue')", $javascript);
    self::assertStringContainsString('I confirm I have permission to reuse', $javascript);
    self::assertStringContainsString('descriptionButton.disabled = true', $javascript);
    self::assertStringContainsString('imageButton.disabled = true', $javascript);
    self::assertStringContainsString('{ confirmRights: true }', $javascript);
    self::assertStringNotContainsString('initWebsiteReview(wrapper)', $form);
  }

  /**
   * Tests server-side URL and organiser Media ownership boundaries.
   */
  public function testRemoteFetchAndMediaImportAreBounded(): void {
    $root = dirname(__DIR__, 3);
    $controller = file_get_contents($root . '/src/Controller/VenueWebsiteMetadataController.php');
    $guard = file_get_contents($root . '/src/Service/PublicRemoteUrlGuard.php');
    $fetcher = file_get_contents($root . '/src/Service/SafeRemoteContentFetcher.php');
    $importer = file_get_contents($root . '/src/Service/VenueWebsiteImageImporter.php');

    self::assertIsString($controller);
    self::assertStringContainsString("\$myeventlane_venue->get('website')->value", $controller);
    self::assertStringNotContainsString("\$request->request->get('url')", $controller);
    self::assertStringContainsString("(\$payload['confirmRights'] ?? FALSE) !== TRUE", $controller);
    self::assertStringContainsString("website_preview', 10, 3600", $controller);
    self::assertStringContainsString("website_image_import', 5, 3600", $controller);

    self::assertIsString($guard);
    self::assertStringContainsString("strtolower((string) (\$parts['scheme'] ?? '')) !== 'https'", $guard);
    self::assertStringContainsString('FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE', $guard);

    self::assertIsString($fetcher);
    self::assertStringContainsString("'allow_redirects' => FALSE", $fetcher);
    self::assertStringContainsString('CURLOPT_RESOLVE => $resolve', $fetcher);
    self::assertStringContainsString('5242880', $fetcher);

    self::assertIsString($importer);
    self::assertStringContainsString("'uid' => max(0, (int) \$venue->getOwnerId())", $importer);
    self::assertStringContainsString("\$venue->set('image_media'", $importer);
    self::assertStringContainsString("\$venue->set('website_metadata_image_source_url'", $importer);
  }

}
