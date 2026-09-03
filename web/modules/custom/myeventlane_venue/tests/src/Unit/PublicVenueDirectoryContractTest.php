<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_venue\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the public venue directory contract.
 *
 * @group myeventlane_venue
 */
final class PublicVenueDirectoryContractTest extends TestCase {

  /**
   * The public route only queries explicitly public venues.
   */
  public function testDirectoryUsesStrictPublicVisibilityBoundary(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $routes = (string) file_get_contents($moduleRoot . '/myeventlane_venue.routing.yml');
    $controller = (string) file_get_contents(
      $moduleRoot . '/src/Controller/PublicVenuesController.php',
    );
    $accessHandler = (string) file_get_contents(
      $moduleRoot . '/src/Entity/VenueAccessControlHandler.php',
    );
    $accessResolver = (string) file_get_contents(
      $moduleRoot . '/src/Service/VenueAccessResolver.php',
    );

    self::assertStringContainsString('myeventlane_venue.public_directory:', $routes);
    self::assertStringContainsString("path: '/venues'", $routes);
    self::assertStringContainsString("_access: 'TRUE'", $routes);
    self::assertStringContainsString(
      "->condition('visibility', Venue::VISIBILITY_PUBLIC)",
      $controller,
    );
    self::assertStringContainsString('->accessCheck(FALSE)', $controller);
    self::assertStringContainsString('!$venue->isPublic()', $controller);
    self::assertStringContainsString('->pager(self::PAGE_SIZE)', $controller);
    self::assertStringNotContainsString('getAccessibleVenues', $controller);
    self::assertStringContainsString(
      "if (\$venue->isPublic()) {\n      return AccessResult::allowed()",
      $accessHandler,
    );
    self::assertStringContainsString(
      "if (\$venue->isPublic()) {\n      return TRUE;",
      $accessResolver,
    );
  }

  /**
   * Cards remain image-led, responsive and useful without imagery.
   */
  public function testDirectoryUsesEventStyleVenueCards(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $template = (string) file_get_contents(
      $moduleRoot . '/templates/myeventlane-venue-public-directory.html.twig',
    );
    $styles = (string) file_get_contents($moduleRoot . '/css/public-venues.css');

    self::assertStringContainsString('mel-public-venue-card__media', $template);
    self::assertStringContainsString('mel-public-venue-card__placeholder', $template);
    self::assertStringContainsString('{{ venue.address }}', $template);
    self::assertStringContainsString('{{ venue.summary }}', $template);
    self::assertStringContainsString("{{ 'Explore venue'|t }}", $template);
    self::assertStringContainsString("{{ 'More venues are coming'|t }}", $template);
    self::assertStringContainsString('{{ pager }}', $template);

    self::assertStringContainsString(
      'grid-template-columns: repeat(3, minmax(0, 1fr));',
      $styles,
    );
    self::assertStringContainsString('@media (max-width: 960px)', $styles);
    self::assertStringContainsString('@media (max-width: 620px)', $styles);
    self::assertStringContainsString(':focus-visible', $styles);
    self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
  }

  /**
   * The public directory is discoverable from site navigation.
   */
  public function testDirectoryIsLinkedFromPublicNavigation(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $projectRoot = dirname($moduleRoot, 4);
    $menuLinks = (string) file_get_contents($moduleRoot . '/myeventlane_venue.links.menu.yml');
    $footerBuilder = (string) file_get_contents(
      $projectRoot . '/web/modules/custom/myeventlane_front/src/Service/PublicFooterNavigationBuilder.php',
    );

    self::assertStringContainsString('menu_name: main', $menuLinks);
    self::assertStringContainsString(
      'route_name: myeventlane_venue.public_directory',
      $menuLinks,
    );
    self::assertStringContainsString(
      "\$this->routeLink('Venues', 'myeventlane_venue.public_directory')",
      $footerBuilder,
    );
  }

}
