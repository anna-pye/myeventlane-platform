<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the Free versus Pro Event Studio analytics boundary.
 */
final class EventStudioAnalyticsEntitlementContractTest extends TestCase {

  private function repositoryRoot(): string {
    return dirname(__DIR__, 7);
  }

  public function testProProjectionUsesCanonicalResolverAndController(): void {
    $renderer = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSectionRenderer.php');
    $controller = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_vendor/src/Controller/VendorEventAnalyticsController.php');

    self::assertIsString($renderer);
    self::assertIsString($controller);
    self::assertStringContainsString('currentUserHasActivePro()', $renderer);
    self::assertStringContainsString('buildStudioContent($event)', $renderer);
    self::assertStringContainsString('public function currentUserHasActivePro(): bool', $controller);
    self::assertStringContainsString('$this->proActiveResolver->isUserProActive($user)', $controller);
    self::assertStringContainsString("\$content = \$page['#content'] ?? [];", $controller);
    $template = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/templates/event/analytics.html.twig');
    self::assertIsString($template);
    self::assertStringContainsString('mel-studio-analytics__group', $template);
    self::assertStringContainsString("<summary>{{ 'Ticket sales'|t }}</summary>", $template);
  }

  public function testFreePulseDoesNotExposeProProjection(): void {
    $renderer = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSectionRenderer.php');
    $template = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_event_studio/templates/mel-event-studio-analytics-pulse.html.twig');

    self::assertIsString($renderer);
    self::assertIsString($template);
    self::assertStringContainsString("'#theme' => 'mel_event_studio_analytics_pulse'", $renderer);
    self::assertStringContainsString('Registrations', $renderer);
    self::assertStringContainsString('Tickets sold', $renderer);
    self::assertStringContainsString('Explore Pro analytics', $template);
    self::assertStringNotContainsString('export_pdf_url', $template);
    self::assertStringNotContainsString('export_excel_url', $template);
  }

}
