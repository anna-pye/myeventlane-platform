<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Launch Centre composition contracts (Sprint 3C.1).
 *
 * @group myeventlane_event_studio
 */
final class EventStudioLaunchCentreTest extends UnitTestCase {

  public function testPublishingHubUsesLaunchCentreThemeNotSettingsForm(): void {
    $renderer = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertIsString($renderer);
    $method = $this->extractMethodBody($renderer, 'buildPublishingHub');
    $this->assertStringContainsString('mel_event_studio_launch_centre', $method);
    $this->assertStringContainsString('EventLaunchVisibilityForm::class', $method);
    $this->assertStringContainsString('buildLaunchCentreViewModel', $method);
    $this->assertStringNotContainsString('EventSettingsForm::class', $method);
    $this->assertStringNotContainsString('data-mel-card-publish-action', $method);
    $this->assertStringNotContainsString('Publish now', $method);
  }

  public function testLaunchCentreStatePrefersReadinessOverPublished(): void {
    $renderer = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertIsString($renderer);
    $method = $this->extractMethodBody($renderer, 'buildLaunchCentreViewModel');
    $this->assertStringContainsString("\$state = !\$ready ? 'needs_attention' : (\$published ? 'live' : 'ready')", $method);
    $this->assertStringNotContainsString("\$published ? 'live' : (\$ready ? 'ready' : 'needs_attention')", $method);
    $this->assertStringContainsString('$checklist_open = !$ready', $method);
  }

  public function testLaunchCopyHandlesLivePlusNeedsAttention(): void {
    $renderer = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertIsString($renderer);
    $headline = $this->extractMethodBody($renderer, 'launchHeadline');
    $this->assertStringContainsString('Your event is live — one thing needs attention', $headline);
    $this->assertStringContainsString('Your event is live — a few things need attention', $headline);
    $hint = $this->extractMethodBody($renderer, 'launchHeroHint');
    $this->assertStringContainsString('Continue setup from the header to fix what needs attention.', $hint);
    $this->assertStringContainsString('Use Publish event in the header when you are ready.', $hint);
    $this->assertStringContainsString('Use Share event in the header to spread the word.', $hint);
  }

  public function testLaunchHeroHintDefersPublishToHeader(): void {
    $renderer = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertIsString($renderer);
    $method = $this->extractMethodBody($renderer, 'launchHeroHint');
    $this->assertStringContainsString('Use Publish event in the header when you are ready.', $method);
    $this->assertStringContainsString('Use Share event in the header to spread the word.', $method);
    $this->assertStringContainsString('Publish is unavailable until the checklist is clear.', $method);
  }

  public function testPublishAjaxIncludesLaunchCentrePayload(): void {
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioPublishController.php');
    $this->assertIsString($controller);
    $this->assertStringContainsString('buildLaunchCentreViewModel', $controller);
    $this->assertStringContainsString('buildDegradedLaunchCentreViewModel', $controller);
    $this->assertStringContainsString("'launch_centre' => \$launch_centre", $controller);
    $js = file_get_contents(dirname(__DIR__, 3) . '/js/mel-event-studio-shell.js');
    $this->assertIsString($js);
    $this->assertStringContainsString('function updateLaunchCentre(shell, launch, result)', $js);
    $this->assertStringContainsString('function applyDegradedLaunchCentre(root, result)', $js);
    $this->assertStringContainsString('function syncLaunchChecklistList(list, checklist, readiness, ready)', $js);
    $this->assertStringContainsString('function syncLaunchAfterBand(root, after, published)', $js);
    $this->assertStringContainsString('function collectLaunchFixLinksFromList(list)', $js);
    $this->assertStringContainsString('function resolveLaunchFixLinkClient(label)', $js);
    $this->assertStringContainsString('function vendorConsolePathFromPublishUrl(publishUrl, segment)', $js);
    $this->assertStringContainsString('vendorConsolePathFromPublishUrl(publishUrl, \'payments\')', $js);
    $this->assertStringContainsString('vendorConsolePathFromPublishUrl(publishUrl, \'settings\')', $js);
    $this->assertStringNotContainsString("path = '/vendor/payments'", $js);
    $this->assertStringNotContainsString("path = '/vendor/settings'", $js);
    $this->assertStringContainsString('buildLaunchChecklistItemsFromReadiness(readiness, preservedFixLinks)', $js);
    $this->assertStringContainsString('updateLaunchCentre(shell, result.launch_centre, result)', $js);
    $this->assertGreaterThanOrEqual(3, substr_count($js, 'updateLaunchCentre(shell, result.launch_centre, result)'));
    $this->assertStringContainsString('While your event is live', $js);
    $this->assertStringContainsString('Share your event from the header', $js);
  }

  public function testDegradedLaunchCentreIncludesChecklistItems(): void {
    $renderer = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertIsString($renderer);
    $method = $this->extractMethodBody($renderer, 'buildDegradedLaunchCentreViewModel');
    $this->assertStringContainsString('buildLaunchChecklistItems', $method);
    $this->assertStringContainsString("'items' => \$checklist_items", $method);
    $this->assertStringNotContainsString('Omits checklist items', $method);
  }

  public function testLaunchVisibilityFormOmitsPublishCard(): void {
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/EventLaunchVisibilityForm.php');
    $this->assertIsString($form);
    $this->assertStringContainsString('extends EventSettingsForm', $form);
    $this->assertStringContainsString('buildVisibilityControls', $form);
    $this->assertStringNotContainsString('parent::buildWizardStepContent', $form);
    $this->assertStringNotContainsString('data-mel-card-publish-action', $form);
    $this->assertStringNotContainsString('Publish now', $form);
    $this->assertStringContainsString('workspace_publishing', $form);
    $this->assertStringContainsString('isDraftWizardSave', $form);
    $this->assertStringContainsString('return TRUE;', $form);
    $this->assertStringContainsString("unset(\$mel['status'])", $form);
    $this->assertStringContainsString('parent::persistWizardMel($form_state, TRUE)', $form);
    $this->assertStringNotContainsString("\$form['mel']['status']", $form);
  }

  public function testLaunchAfterGuidanceUsesLiveWordingWhenPublished(): void {
    $renderer = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertIsString($renderer);
    $method = $this->extractMethodBody($renderer, 'launchAfterGuidance');
    $this->assertStringContainsString('While your event is live', $method);
    $this->assertStringContainsString('Share your event from the header', $method);
    $this->assertStringContainsString("You'll be able to share your event", $method);
    $this->assertStringContainsString('After you publish', $method);
    $this->assertStringNotContainsString("'After publishing'", $method);
  }

  public function testLaunchCentreTemplateHasNoPublishButton(): void {
    $twig = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-launch-centre.html.twig');
    $this->assertIsString($twig);
    $this->assertStringContainsString('mel-launch-centre', $twig);
    $this->assertStringContainsString('data-mel-launch-hero-hint', $twig);
    $this->assertStringContainsString('data-mel-launch-eyebrow', $twig);
    $this->assertStringContainsString('data-mel-launch-headline', $twig);
    $this->assertStringContainsString('Who can find this?', $twig);
    $this->assertStringContainsString('After you publish', $twig);
    $this->assertStringContainsString('data-mel-launch-checklist', $twig);
    $this->assertStringContainsString('data-mel-launch-visibility', $twig);
    $this->assertStringContainsString('mel-launch-centre__controls', $twig);
    $this->assertStringContainsString('eyebrow_repeats_headline', $twig);
    $this->assertStringContainsString('L.eyebrow == L.headline', $twig);
    $this->assertStringNotContainsString('data-mel-publish-action', $twig);
    $this->assertStringNotContainsString('data-mel-card-publish-action', $twig);
    $this->assertStringNotContainsString('Publish now', $twig);
    $this->assertStringNotContainsString('data-mel-unpublish-action', $twig);
  }

  public function testLaunchCentrePresentationUsesWideMatchedControls(): void {
    $scss = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/src/scss/components/_mel-event-studio-launch-centre.scss');
    $this->assertIsString($scss);
    $this->assertStringContainsString('.mel-launch-centre__controls', $scss);
    $this->assertStringContainsString('max-width: none', $scss);
    $this->assertStringContainsString('min-height: 50px', $scss);
    $this->assertStringContainsString('.mel-launch-centre__checklist-summary::before', $scss);
    $this->assertStringContainsString('.mel-launch-centre__visibility-summary::before', $scss);
    $this->assertStringContainsString('@media (max-width: 767px)', $scss);
  }

  public function testThemeHookRegistersLaunchCentre(): void {
    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.module');
    $this->assertIsString($module);
    $this->assertStringContainsString("'mel_event_studio_launch_centre'", $module);
    $this->assertStringContainsString('mel-event-studio-launch-centre', $module);
  }

  public function testSettingsFormStillOwnsFullSettingsPath(): void {
    $renderer = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertIsString($renderer);
    $method = $this->extractMethodBody($renderer, 'buildSettingsSection');
    $this->assertStringContainsString('EventSettingsForm::class', $method);
  }

  public function testAuthoritativeCtaResolverUntouched(): void {
    $overview = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventWorkspaceOverviewBuilder.php');
    $this->assertIsString($overview);
    $this->assertStringContainsString('function resolveAuthoritativePrimaryCta', $overview);
    $eligibility = file_get_contents(dirname(__DIR__, 3) . '/src/Service/PublishEligibilityEvaluator.php');
    $this->assertIsString($eligibility);
    $this->assertStringContainsString('VendorPublishRequirementsGate', $eligibility);
  }

  public function testLaunchFixLinkDoesNotMatchEndInsideAttendee(): void {
    $renderer = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertIsString($renderer);
    $method = $this->extractMethodBody($renderer, 'resolveLaunchFixLink');
    $this->assertStringNotContainsString("str_contains(\$lower, 'end')", $method);
    $this->assertStringNotContainsString("str_contains(\$lower, 'start')", $method);
    $this->assertStringContainsString('isLaunchScheduleFixLabel', $method);
    $schedule = $this->extractMethodBody($renderer, 'isLaunchScheduleFixLabel');
    $this->assertStringContainsString('start date', $schedule);
    $this->assertStringContainsString('end date', $schedule);
    $this->assertStringContainsString('\\bdates?\\b', $schedule);
  }

  public function testLaunchChecklistProgressCountsRequiredItemsOnly(): void {
    $renderer = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertIsString($renderer);
    $method = $this->extractMethodBody($renderer, 'buildLaunchCentreViewModel');
    $this->assertStringContainsString("in_array(\$item['tone'] ?? '', ['success', 'attention'], TRUE)", $method);
    $this->assertStringContainsString('required_items', $method);
  }

  public function testOrganiserFixFallbackIsNotConnectStripe(): void {
    $renderer = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertIsString($renderer);
    $method = $this->extractMethodBody($renderer, 'resolveLaunchFixLink');
    $this->assertStringContainsString('settings_profile', $method);
    $this->assertStringContainsString('Organiser/terms blockers must not fall through to Stripe Connect', $method);
    // Catch block must keep the Open account label, never rebadge as Connect Stripe.
    $catchPos = strpos($method, 'catch (\Throwable)');
    $this->assertNotFalse($catchPos);
    $catchBody = substr($method, $catchPos);
    $this->assertStringNotContainsString('Connect Stripe', $catchBody);
    $this->assertStringNotContainsString('console.payments', $catchBody);
  }

  private function extractMethodBody(string $source, string $methodName): string {
    $pattern = '/(?:private|public|protected) function ' . preg_quote($methodName, '/') . '\(/';
    if (!preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
      $this->fail("Method {$methodName} not found");
    }
    $start = (int) $match[0][1];
    $brace = strpos($source, '{', $start);
    $this->assertNotFalse($brace);
    $depth = 0;
    $len = strlen($source);
    for ($i = (int) $brace; $i < $len; $i++) {
      $ch = $source[$i];
      if ($ch === '{') {
        $depth++;
      }
      elseif ($ch === '}') {
        $depth--;
        if ($depth === 0) {
          return substr($source, $start, $i - $start + 1);
        }
      }
    }
    $this->fail("Could not extract {$methodName}");
  }

}
