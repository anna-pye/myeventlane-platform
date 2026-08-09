<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Protects the Event Studio SEO preview and warning contract.
 *
 * @group myeventlane_event_studio
 */
final class EventSeoQualityContractTest extends UnitTestCase {

  /**
   * Confirms Publishing exposes previews and non-blocking SEO guidance.
   */
  public function testPublishingSurfaceContainsSearchAndSocialPreviews(): void {
    $root = dirname(__DIR__, 3);
    $template = file_get_contents($root . '/templates/mel-event-studio-launch-centre.html.twig');
    $readiness = file_get_contents($root . '/src/Service/EventReadinessService.php');

    self::assertIsString($template);
    self::assertStringContainsString("'SEO preview'|t", $template);
    self::assertStringContainsString("'Search result'|t", $template);
    self::assertStringContainsString("'Social share'|t", $template);

    self::assertIsString($readiness);
    self::assertStringContainsString('Add a useful event summary for search and social previews.', $readiness);
    self::assertStringContainsString('Improve the event image alt text', $readiness);
  }

}
