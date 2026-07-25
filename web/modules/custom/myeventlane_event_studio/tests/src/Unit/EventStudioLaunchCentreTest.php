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
    $this->assertStringContainsString('$checklist_open = !$ready', $method);
    $this->assertStringNotContainsString('EventSettingsForm::class', $method);
    $this->assertStringNotContainsString('data-mel-card-publish-action', $method);
    $this->assertStringNotContainsString('Publish now', $method);
  }

  public function testLaunchHeroHintDefersPublishToHeader(): void {
    $renderer = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertIsString($renderer);
    $method = $this->extractMethodBody($renderer, 'launchHeroHint');
    $this->assertStringContainsString('Use Publish event in the header when you are ready.', $method);
    $this->assertStringContainsString('Use Share event in the header to spread the word.', $method);
    $this->assertStringContainsString('Publish is unavailable until the checklist is clear.', $method);
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
  }

  public function testLaunchCentreTemplateHasNoPublishButton(): void {
    $twig = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-launch-centre.html.twig');
    $this->assertIsString($twig);
    $this->assertStringContainsString('mel-launch-centre', $twig);
    $this->assertStringContainsString('data-mel-launch-hero-hint', $twig);
    $this->assertStringContainsString('Who can find this?', $twig);
    $this->assertStringContainsString('After you publish', $twig);
    $this->assertStringContainsString('data-mel-launch-checklist', $twig);
    $this->assertStringContainsString('data-mel-launch-visibility', $twig);
    $this->assertStringNotContainsString('data-mel-publish-action', $twig);
    $this->assertStringNotContainsString('data-mel-card-publish-action', $twig);
    $this->assertStringNotContainsString('Publish now', $twig);
    $this->assertStringNotContainsString('data-mel-unpublish-action', $twig);
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
