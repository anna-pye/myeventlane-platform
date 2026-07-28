<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the organiser event-index presentation hierarchy.
 *
 * @group myeventlane_vendor
 */
final class VendorEventsPresentationContractTest extends TestCase {

  public function testEventCardsLeadToWorkspaceAndDiscloseSecondaryActions(): void {
    $webRoot = dirname(__DIR__, 6);
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/templates/myeventlane-vendor-events-grid.html.twig',
    );

    self::assertStringContainsString("{{ 'Open workspace'|t }}", $template);
    self::assertStringNotContainsString("{{ 'Manage'|t }}", $template);
    self::assertStringContainsString('<details class="mel-vendor-events-v2__more-actions">', $template);
    self::assertStringContainsString("{{ 'More actions'|t }}", $template);
    self::assertStringContainsString("ev.status|default('') == 'past'", $template);

    foreach (['links.edit', 'links.tickets', 'links.rsvps', 'links.orders', 'links.attendees', 'links.analytics'] as $link) {
      self::assertStringContainsString($link, $template);
    }
    self::assertStringContainsString('mel-event-card-removal-dialog.html.twig', $template);
  }

  public function testEventGridUsesTwoColumnsWithoutAThreeColumnOverride(): void {
    $webRoot = dirname(__DIR__, 6);
    $styles = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/src/scss/pages/_vendor-events.scss',
    );

    self::assertStringContainsString('repeat(2, minmax(0, 1fr))', $styles);
    self::assertStringNotContainsString('repeat(3, 1fr)', $styles);
    self::assertStringContainsString('@media (max-width: 480px)', $styles);
    self::assertStringContainsString('grid-template-columns: minmax(0, 1fr);', $styles);
  }

  public function testEventIndexDefaultsToNewestCreated(): void {
    $webRoot = dirname(__DIR__, 6);
    $controller = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Controller/VendorEventsController.php',
    );
    $builder = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Service/VendorEventIndexViewModelBuilder.php',
    );

    self::assertStringContainsString("\$request->query->get('sort') ?? 'created'", $controller);
    self::assertStringContainsString("'_created' => \$node->getCreatedTime()", $builder);
    self::assertStringContainsString("(int) (\$b['_created'] ?? 0) <=> (int) (\$a['_created'] ?? 0)", $builder);
    self::assertStringContainsString("'Newest created'", $builder);
  }

}
