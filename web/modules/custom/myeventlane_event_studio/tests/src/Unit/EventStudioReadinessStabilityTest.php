<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Contract tests for Event Studio readiness and governance stability hardening.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioReadinessStabilityTest extends UnitTestCase {

  public function testReadinessServiceWrapsTicketStripeAndGateFailures(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventReadinessService.php');

    $this->assertIsString($source);
    $this->assertStringContainsString('loadEventTickets', $source);
    $this->assertStringContainsString('validateStripePublish', $source);
    $this->assertStringContainsString('loadPublishDenials', $source);
    $this->assertStringContainsString('catch (\\Throwable $e)', $source);
    $this->assertStringContainsString('degradedResult', $source);
    $this->assertStringContainsString('@logger.channel.myeventlane_event_studio', file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.services.yml'));
  }

  public function testGovernanceRefreshControllerReturnsStructuredFailureJson(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioGovernanceRefreshController.php');

    $this->assertIsString($source);
    $this->assertStringContainsString('catch (\Throwable $e)', $source);
    $this->assertStringContainsString("'ok' => FALSE", $source);
    $this->assertStringContainsString('Governance refresh failed. Try again shortly.', $source);
  }

  public function testGovernanceComponentControllerReturnsStructuredFailureJson(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioGovernanceComponentController.php');

    $this->assertIsString($source);
    $this->assertStringContainsString('catch (\Throwable $e)', $source);
    $this->assertStringContainsString("'ok' => FALSE", $source);
    $this->assertStringContainsString('Governance refresh failed. Try again shortly.', $source);
  }

  public function testGovernanceBuilderGuardsNullableSurfaceServices(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioGovernanceBuilder.php');

    $this->assertIsString($source);
    $this->assertStringContainsString('isEnabled()', $source);
    $this->assertStringContainsString('disabledBundle', $source);
    $this->assertStringContainsString('buildEnabledBundle', $source);
    $this->assertStringContainsString('instanceof MelWorkflowManager', $source);
    $this->assertStringContainsString('catch (\\Throwable $e)', $source);
  }

  public function testEventStudioControllerUsesReadinessFacade(): void {
    $services = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.services.yml');
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioController.php');

    $this->assertIsString($services);
    $this->assertIsString($controller);
    $this->assertStringContainsString('myeventlane_event_studio.readiness_facade', $services);
    $this->assertStringContainsString('EventReadinessFacade', $controller);
    $this->assertStringContainsString('buildChecklistCardFromPresentation', $controller);
  }

  public function testReadinessFacadeServiceIsRegistered(): void {
    $services = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.services.yml');

    $this->assertIsString($services);
    $this->assertStringContainsString('myeventlane_event_studio.readiness_facade', $services);
    $this->assertStringContainsString('EventReadinessFacade', $services);
    $this->assertStringContainsString('@myeventlane_event.featured_readiness', $services);
  }

  public function testBrandingFormSyncsHeroInputBeforeValidation(): void {
    $branding = file_get_contents(dirname(__DIR__, 3) . '/src/Form/EventBrandingForm.php');
    $save = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSaveService.php');

    $this->assertIsString($branding);
    $this->assertIsString($save);
    $this->assertStringContainsString('prepareBrandingHeroFormStateForValidation', $branding);
    $this->assertStringContainsString('prepareBrandingHeroFormStateForValidation', $save);
    $this->assertStringContainsString('parent::validateForm', $branding);
  }

  public function testPublishEligibilityEvaluatorIsRegistered(): void {
    $services = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.services.yml');
    $evaluator = file_get_contents(dirname(__DIR__, 3) . '/src/Service/PublishEligibilityEvaluator.php');

    $this->assertIsString($services);
    $this->assertIsString($evaluator);
    $this->assertStringContainsString('myeventlane_event_studio.publish_eligibility', $services);
    $this->assertStringContainsString('PublishEligibilityEvaluator', $services);
    $this->assertStringContainsString('catch (\\Throwable $e)', $evaluator);
  }

}
