<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_venue\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the organiser venue library presentation contract.
 *
 * @group myeventlane_venue
 */
final class VendorVenueLibraryPresentationContractTest extends TestCase {

  /**
   * Ensures managed and shared venues have distinct sections.
   */
  public function testVenueLibrarySeparatesManagedAndSharedVenues(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $controller = (string) file_get_contents($moduleRoot . '/src/Controller/VendorVenuesController.php');
    $template = (string) file_get_contents(
      $moduleRoot . '/templates/myeventlane-venue-vendor-list.html.twig',
    );

    self::assertStringContainsString("'#owned_venues' => \$ownedRows", $controller);
    self::assertStringContainsString("'#shared_venues' => \$sharedRows", $controller);
    self::assertStringContainsString("{{ 'Venues you manage'|t }}", $template);
    self::assertStringContainsString("{{ 'Shared with you'|t }}", $template);
    self::assertStringContainsString('aria-label="{{ \'Actions for @venue\'|t', $template);
  }

  /**
   * Ensures the card grid remains responsive and accessible.
   */
  public function testVenueLibraryUsesResponsiveCardsAndAccessibleActions(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $styles = (string) file_get_contents($moduleRoot . '/css/vendor-venues.css');

    self::assertStringContainsString('grid-template-columns: repeat(3, minmax(0, 1fr));', $styles);
    self::assertStringContainsString('@media (max-width: 1100px)', $styles);
    self::assertStringContainsString('@media (max-width: 700px)', $styles);
    self::assertStringContainsString('min-height: 44px;', $styles);
    self::assertStringContainsString(':focus-visible', $styles);
    self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
  }

}
