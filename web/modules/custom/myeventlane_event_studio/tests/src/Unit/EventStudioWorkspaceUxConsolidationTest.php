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
    $this->assertStringContainsString('data-mel-mc-details', $template);
    $this->assertStringContainsString('Show details', $template);
    $this->assertStringContainsString('data-mel-mc-quality-badge', $template);
    $this->assertStringNotContainsString('Next step', $template);
    $this->assertStringNotContainsString('Why this matters', $template);
    $this->assertStringNotContainsString('Other improvements', $template);
  }

  public function testMissionControlDisclosureUsesSessionStorage(): void {
    $js = file_get_contents(dirname(__DIR__, 3) . '/js/mel-event-studio-shell.js');
    $this->assertIsString($js);
    $this->assertStringContainsString('mel.eventStudio.missionControl.expanded', $js);
    $this->assertStringContainsString('function bindMissionControlDetails', $js);
    $this->assertStringContainsString('bindMissionControlDetails(shell)', $js);
    $this->assertStringContainsString('sessionStorage', $js);
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

  public function testNonHomeMissionControlReusesReadinessAndSharesHomeGuide(): void {
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
    // Shared Mission Control contract: default includes workspace VM like Home.
    $this->assertStringContainsString('function resolveGuidePublicationStatus', $builder);
    $this->assertStringContainsString('function isEventEnded', $builder);
  }

  public function testPublishAjaxFallsBackToMissionControlWhenHomeSnapshotFails(): void {
    $publish = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioPublishController.php');
    $presentation = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioWorkspacePresentation.php');
    $js = file_get_contents(dirname(__DIR__, 3) . '/js/mel-event-studio-shell.js');
    $this->assertIsString($publish);
    $this->assertIsString($presentation);
    $this->assertIsString($js);
    $this->assertStringContainsString("ajax_readiness['mission_control'] = NULL", $publish);
    $this->assertStringContainsString('buildMissionControl(', $publish);
    $this->assertStringContainsString('FALSE,', $publish);
    $this->assertStringContainsString('Mission Control AJAX fallback failed', $publish);
    $this->assertStringContainsString('buildDegradedMissionControlPayload', $publish);
    $this->assertStringContainsString('function buildDegradedMissionControlPayload', $presentation);
    $this->assertStringContainsString('function buildDegradedMissionControl', $js);
    $this->assertStringContainsString('buildDegradedMissionControl(shell, readiness)', $js);
  }

}
