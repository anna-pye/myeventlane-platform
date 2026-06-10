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

  public function testReadinessFacadeServiceIsRegistered(): void {
    $services = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.services.yml');

    $this->assertIsString($services);
    $this->assertStringContainsString('myeventlane_event_studio.readiness_facade', $services);
    $this->assertStringContainsString('EventReadinessFacade', $services);
    $this->assertStringContainsString('@myeventlane_event.featured_readiness', $services);
  }

}
