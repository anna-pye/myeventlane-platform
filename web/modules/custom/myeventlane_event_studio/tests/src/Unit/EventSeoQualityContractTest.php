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
  public function testPublishingSurfaceContainsPreviewsAndSearchHealth(): void {
    $root = dirname(__DIR__, 3);
    $template = file_get_contents($root . '/templates/mel-event-studio-launch-centre.html.twig');
    $readiness = file_get_contents($root . '/src/Service/EventReadinessService.php');
    $previewBuilder = file_get_contents($root . '/src/Service/EventSeoPreviewBuilder.php');

    self::assertIsString($template);
    self::assertStringContainsString("'SEO preview'|t", $template);
    self::assertStringContainsString("'Search result'|t", $template);
    self::assertStringContainsString("'Social share'|t", $template);
    self::assertSame(2, substr_count($template, 'seo.preview_details'));
    self::assertSame(2, substr_count($template, 'mel-launch-centre__preview-details'));
    self::assertStringContainsString("'Event search health'|t", $template);
    self::assertStringContainsString('seo.search_description', $template);
    self::assertStringContainsString('seo.social_description', $template);
    self::assertStringContainsString('seo.health', $template);

    self::assertIsString($readiness);
    self::assertStringContainsString('Add a useful event summary for search and social previews.', $readiness);
    self::assertStringContainsString('Improve the event image alt text', $readiness);

    self::assertIsString($previewBuilder);
    self::assertStringContainsString('EventMetadataBuilderInterface $metadataBuilder', $previewBuilder);
    self::assertStringContainsString("'preview_details' => [", $previewBuilder);
    self::assertStringContainsString("'label' => (string) \$this->t('Date and time')", $previewBuilder);
    self::assertStringContainsString("'label' => (string) \$this->t('Venue')", $previewBuilder);
    self::assertStringContainsString("preg_match('/\\bfree\\b/ui'", $previewBuilder);
    self::assertStringContainsString('Your event copy mentions “free”, but paid tickets are active.', $previewBuilder);
  }

}
