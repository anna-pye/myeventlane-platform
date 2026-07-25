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

  public function testWorkspaceShellOrdersMissionControlBeforeBoost(): void {
    $workspace = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-workspace.html.twig');
    $this->assertIsString($workspace);
    $missionPos = strpos($workspace, 'mel-event-studio-mission-control');
    $boostPos = strpos($workspace, 'mel-boost-active-banner');
    $this->assertNotFalse($missionPos);
    $this->assertNotFalse($boostPos);
    $this->assertLessThan($boostPos, $missionPos);
    $this->assertStringNotContainsString('mel-event-studio-event-health', $workspace);
    $this->assertStringNotContainsString('mel-event-studio-readiness-strip', $workspace);
    $this->assertStringNotContainsString('mel-event-studio-homepage-readiness', $workspace);
  }

  public function testMissionControlThemeIsRegistered(): void {
    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.module');
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-mission-control.html.twig');
    $this->assertIsString($module);
    $this->assertIsString($template);
    $this->assertStringContainsString('mel_event_studio_mission_control', $module);
    $this->assertStringContainsString('data-mel-mission-control', $template);
    $this->assertStringContainsString('Event Quality', $template);
    $this->assertStringContainsString('Other improvements', $template);
  }

  public function testHomepageReadinessSupportsSummaryOnlyDisplay(): void {
    $checklist = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_event/templates/mel-homepage-readiness-checklist.html.twig');
    $builder = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_event/src/Service/FeaturedEventReadinessRenderBuilder.php');
    $this->assertIsString($checklist);
    $this->assertIsString($builder);
    $this->assertStringContainsString('summary_only', $checklist);
    $this->assertStringContainsString('hide_published_item', $checklist);
    $this->assertStringContainsString('summary_only', $builder);
    $this->assertStringContainsString('buildHomepageReadinessCard', file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioWorkspacePresentation.php'));
  }

  public function testOverviewSectionUsesShorterOrientationCopy(): void {
    $renderer = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertIsString($renderer);
    $this->assertStringContainsString('Suggested next steps', $renderer);
    $this->assertStringNotContainsString('Your event workspace is ready', $renderer);
    $this->assertStringNotContainsString('What lives here', $renderer);
  }

  public function testShellJsUpdatesMissionControlAfterPublish(): void {
    $js = file_get_contents(dirname(__DIR__, 3) . '/js/mel-event-studio-shell.js');
    $this->assertIsString($js);
    $this->assertStringContainsString('function updateMissionControl', $js);
    $this->assertStringContainsString('updateMissionControl(shell, readiness)', $js);
    $this->assertStringContainsString('data-mel-mission-control', $js);
    $this->assertStringContainsString('dataset.melPublished', $js);
  }

  public function testOverviewIncludesSharedMissionControl(): void {
    $overview = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-overview.html.twig');
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventWorkspaceOverviewBuilder.php');
    $this->assertIsString($overview);
    $this->assertIsString($builder);
    $this->assertStringContainsString('mel-event-studio-mission-control.html.twig', $overview);
    $this->assertStringContainsString('function buildMissionControl', $builder);
    $this->assertStringContainsString('assembleMissionControl', $builder);
  }

  public function testNonHomeMissionControlReusesReadinessAndSkipsWorkspaceVm(): void {
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioController.php');
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventWorkspaceOverviewBuilder.php');
    $this->assertIsString($controller);
    $this->assertIsString($builder);
    $this->assertStringContainsString(
      'buildMissionControl($node, $account, $section, $readiness_bundle)',
      $controller,
    );
    $this->assertStringContainsString('?array $readiness_bundle = NULL', $builder);
    $this->assertStringContainsString('bool $include_workspace_model = TRUE', $builder);
    $this->assertStringContainsString(
      'buildGuideCardState($event, $account, $readiness_bundle, FALSE)',
      $builder,
    );
  }

}
