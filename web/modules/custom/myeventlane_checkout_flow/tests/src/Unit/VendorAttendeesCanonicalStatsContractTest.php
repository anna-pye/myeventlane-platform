<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the canonical statistics boundary on the attendee portfolio.
 */
final class VendorAttendeesCanonicalStatsContractTest extends TestCase {

  private function repositoryRoot(): string {
    return dirname(__DIR__, 7);
  }

  public function testControllerDoesNotRecalculateCommerceTotals(): void {
    $controller = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_checkout_flow/src/Controller/VendorAttendeesController.php');
    $template = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-vendor-attendees-dashboard.html.twig');

    self::assertIsString($controller);
    self::assertIsString($template);

    self::assertStringContainsString('buildStatsForEvents(array_values($events))', $controller);
    self::assertStringContainsString("'#mel_stats_unavailable' => !\$statsAvailable", $controller);
    self::assertStringNotContainsString('calculateEventStats', $controller);
    self::assertStringNotContainsString('getOrder()', $controller);
    self::assertStringNotContainsString("getStorage('commerce_order_item')", $controller);

    self::assertStringContainsString('{% if mel_stats_unavailable %}', $template);
    self::assertStringContainsString('Attendee overview temporarily unavailable', $template);
    self::assertStringContainsString('Your event and attendee records are safe.', $template);
  }

  public function testAttendeePortfolioUsesSharedOrganiserPageHierarchy(): void {
    $template = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-vendor-attendees-dashboard.html.twig');
    $cardTemplate = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_checkout_flow/templates/components/mel-attendees-event-card.html.twig');

    self::assertIsString($template);
    self::assertIsString($cardTemplate);
    self::assertStringContainsString('mel-organiser-page', $template);
    self::assertStringContainsString('mel-organiser-page__metrics', $template);
    self::assertStringContainsString("{{ 'Across your events'|t }}", $template);
    self::assertStringContainsString("{{ 'Choose an event'|t }}", $template);
    self::assertStringContainsString('mel.attendees_url', $cardTemplate);
  }

}
