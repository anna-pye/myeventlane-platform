<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * VL-5A Launch Success Alternative A contracts.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioLaunchSuccessTest extends UnitTestCase {

  public function testHandoffBuildsAlternativeAFields(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/EventStudioPreprocess.php');
    $this->assertIsString($source);
    $method = $this->extractMethodBody($source, 'buildPublishSuccessHandoff');
    $this->assertStringContainsString("Your event is now live", $method);
    $this->assertStringContainsString("'people_can'", $method);
    $this->assertStringContainsString("'people_can_intro'", $method);
    $this->assertStringContainsString("'recommended_label'", $method);
    $this->assertStringContainsString("'share_workspace_url'", $method);
    $this->assertStringContainsString("'copy_label'", $method);
    $this->assertStringContainsString("'view_label'", $method);
    $this->assertStringContainsString("'boost_eyebrow'", $method);
    $this->assertStringContainsString("'boost_label'", $method);
    $this->assertStringContainsString("'view_url'", $method);
    $this->assertStringContainsString("'share'", $method);
    $this->assertStringContainsString("'boost_url'", $method);
    $this->assertStringContainsString("'calendar_url'", $method);
    $this->assertStringContainsString('workspace_marketing', $method);
    $this->assertStringContainsString('buildPublishSuccessPeopleCan', $method);
    $this->assertStringNotContainsString('Published successfully', $method);
  }

  public function testPeopleCanVariesByEventType(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/EventStudioPreprocess.php');
    $this->assertIsString($source);
    $method = $this->extractMethodBody($source, 'buildPublishSuccessPeopleCan');
    $this->assertStringContainsString('discover your event', $method);
    $this->assertStringContainsString('share your event', $method);
    $this->assertStringContainsString("\$event_type === 'rsvp'", $method);
    $this->assertStringContainsString("\$event_type === 'paid'", $method);
    $this->assertStringContainsString("\$event_type === 'external'", $method);
    $this->assertStringContainsString('RSVP or buy tickets', $method);
    $this->assertStringContainsString('buy tickets', $method);
    $this->assertStringContainsString('follow your external booking link', $method);
  }

  public function testWorkspaceTemplateUsesAlternativeAStructure(): void {
    $twig = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-workspace.html.twig');
    $this->assertIsString($twig);
    $this->assertStringContainsString('mel-launch-success', $twig);
    $this->assertStringContainsString('data-mel-launch-success', $twig);
    $this->assertStringContainsString('data-mel-publish-success-title', $twig);
    $this->assertStringContainsString('data-mel-publish-success-people', $twig);
    $this->assertStringContainsString('data-mel-publish-success-share', $twig);
    $this->assertStringContainsString('data-mel-publish-success-copy', $twig);
    $this->assertStringContainsString('data-mel-publish-success-view', $twig);
    $this->assertStringContainsString('data-mel-publish-success-boost', $twig);
    $this->assertStringContainsString('aria-live="polite"', $twig);
    $this->assertStringContainsString('mel-outcome', $twig);
    $this->assertStringContainsString('mel-outcome__panel--success', $twig);
    $this->assertStringContainsString('mel-outcome__panel--error', $twig);
    $this->assertStringContainsString('role="alert"', $twig);
    $this->assertStringContainsString('mel-publish-success-title', $twig);
    $this->assertStringContainsString('Share your event', $twig);
    $this->assertStringContainsString('Copy link', $twig);
    $this->assertStringContainsString('View public page', $twig);
    $this->assertStringContainsString('Boost visibility (optional)', $twig);
    $this->assertStringContainsString('Grow later', $twig);
    $this->assertStringContainsString(
      'mel-btn--secondary mel-launch-success__share',
      $twig,
    );
    $this->assertStringContainsString(
      'mel-btn--ghost mel-launch-success__copy',
      $twig,
    );
    $this->assertStringNotContainsString(
      'mel-btn--primary mel-launch-success__share',
      $twig,
    );
    $work_position = strpos(
      $twig,
      '<div class="mel-event-studio-workspace">',
    );
    $outcome_position = strpos($twig, 'data-mel-launch-success');
    $this->assertNotFalse($work_position);
    $this->assertNotFalse($outcome_position);
    $this->assertGreaterThan($work_position, $outcome_position);
    $this->assertStringNotContainsString('Boost my event', $twig);
    $this->assertStringNotContainsString('mel-publish-boost-cta__features', $twig);
    $this->assertStringNotContainsString('data-mel-publish-success-view-row', $twig);
  }

  public function testShellRendersAlternativeAAndFocusesHeading(): void {
    $js = file_get_contents(dirname(__DIR__, 3) . '/js/mel-event-studio-shell.js');
    $this->assertIsString($js);
    $this->assertStringContainsString('function renderPublishSuccessFeedback(shell, handoff)', $js);
    $this->assertStringContainsString('function setOutcomeTone(feedback, tone)', $js);
    $this->assertStringContainsString("setOutcomeTone(feedback, 'error')", $js);
    $this->assertStringContainsString("setOutcomeTone(feedback, 'success')", $js);
    $this->assertStringContainsString('heading.focus({ preventScroll: true })', $js);
    $this->assertStringContainsString("Drupal.t('Unpublished successfully')", $js);
    $this->assertStringContainsString('people_can', $js);
    $this->assertStringContainsString('share_workspace_url', $js);
    $this->assertStringContainsString('[data-mel-publish-success-title]', $js);
    $this->assertStringContainsString('[data-mel-publish-success-share]', $js);
    $this->assertStringContainsString('[data-mel-publish-success-copy]', $js);
    $this->assertStringContainsString('titleEl.focus({ preventScroll: true })', $js);
    $this->assertStringContainsString('prefersReducedMotion()', $js);
    $this->assertStringContainsString('mel-launch-success--enter', $js);
    $this->assertStringContainsString('bindPublishSuccessCopy', $js);
    $this->assertStringContainsString('input.focus()', $js);
    $this->assertStringContainsString("announce(fallbackCopyText(url))", $js);
    $this->assertStringContainsString('Could not copy link.', $js);
    $this->assertStringContainsString("studioSettings().publishHandoff", $js);
    $this->assertStringContainsString('renderPublishSuccessFeedback(shell, handoff)', $js);
    $this->assertStringContainsString('renderPublishSuccessFeedback(shell, result.handoff)', $js);
    $this->assertGreaterThanOrEqual(2, substr_count($js, 'renderPublishSuccessFeedback(shell, result.handoff)'));
  }

  public function testCelebrateQueryStillUsesSameHandoffBuilder(): void {
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioController.php');
    $this->assertIsString($controller);
    $this->assertStringContainsString("query->get('mel_celebrate') === '1'", $controller);
    $this->assertStringContainsString('buildPublishSuccessHandoff($node)', $controller);
    $this->assertStringContainsString("'publishHandoff' => \$publish_handoff", $controller);

    $publish = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioPublishController.php');
    $this->assertIsString($publish);
    $this->assertStringContainsString('buildPublishSuccessHandoff($node)', $publish);
    $this->assertStringContainsString("\$payload['handoff'] = \$handoff", $publish);
  }

  private function extractMethodBody(string $source, string $methodName): string {
    $pattern = '/(?:private|public|protected) function ' . preg_quote($methodName, '/') . '\(/';
    if (!preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
      $this->fail("Method {$methodName} not found");
    }
    $start = $match[0][1];
    $bracePos = strpos($source, '{', $start);
    if ($bracePos === FALSE) {
      $this->fail("Opening brace for {$methodName} not found");
    }
    $depth = 0;
    $len = strlen($source);
    for ($i = $bracePos; $i < $len; $i++) {
      $char = $source[$i];
      if ($char === '{') {
        $depth++;
      }
      elseif ($char === '}') {
        $depth--;
        if ($depth === 0) {
          return substr($source, $bracePos, $i - $bracePos + 1);
        }
      }
    }
    $this->fail("Could not extract body for {$methodName}");
  }

}
