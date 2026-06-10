<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Contract tests for Event Studio workspace UX consolidation.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioWorkspaceUxConsolidationTest extends UnitTestCase {

  public function testWorkspaceShellOrdersStatusBeforeBoost(): void {
    $workspace = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-workspace.html.twig');
    $this->assertIsString($workspace);
    $healthPos = strpos($workspace, 'mel-event-studio-event-health');
    $readinessPos = strpos($workspace, 'mel-event-studio-readiness-strip');
    $homepagePos = strpos($workspace, 'mel-event-studio-homepage-readiness');
    $boostPos = strpos($workspace, 'mel-boost-active-banner');
    $this->assertNotFalse($healthPos);
    $this->assertNotFalse($readinessPos);
    $this->assertNotFalse($homepagePos);
    $this->assertNotFalse($boostPos);
    $this->assertLessThan($readinessPos, $healthPos);
    $this->assertLessThan($boostPos, $readinessPos);
    $this->assertLessThan($boostPos, $homepagePos);
  }

  public function testWorkspaceHidesPublishStripWhenConfigured(): void {
    $workspace = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-workspace.html.twig');
    $presentation = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioWorkspacePresentation.php');
    $this->assertIsString($workspace);
    $this->assertIsString($presentation);
    $this->assertStringContainsString('readiness.show_publish_strip', $workspace);
    $this->assertStringContainsString('shouldShowPublishStrip', $presentation);
    $this->assertStringContainsString('readinessStripTitle', $presentation);
  }

  public function testHomepageReadinessSupportsSummaryOnlyDisplay(): void {
    $checklist = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_event/templates/mel-homepage-readiness-checklist.html.twig');
    $builder = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_event/src/Service/FeaturedEventReadinessRenderBuilder.php');
    $this->assertIsString($checklist);
    $this->assertIsString($builder);
    $this->assertStringContainsString('summary_only', $checklist);
    $this->assertStringContainsString('hide_published_item', $checklist);
    $this->assertStringContainsString('summary_only', $builder);
    $this->assertStringContainsString('buildHomepageReadinessCard', file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioController.php'));
  }

  public function testOverviewSectionUsesShorterOrientationCopy(): void {
    $renderer = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertIsString($renderer);
    $this->assertStringContainsString('Suggested next steps', $renderer);
    $this->assertStringNotContainsString('Your event workspace is ready', $renderer);
    $this->assertStringNotContainsString('What lives here', $renderer);
  }

  public function testShellJsHidesReadinessStripForPublishedReadyEvents(): void {
    $js = file_get_contents(dirname(__DIR__, 3) . '/js/mel-event-studio-shell.js');
    $this->assertIsString($js);
    $this->assertStringContainsString('Published and ready', $js);
    $this->assertStringContainsString('strip.hidden = !showStrip', $js);
    $this->assertStringContainsString('dataset.melPublished', $js);
  }

}
