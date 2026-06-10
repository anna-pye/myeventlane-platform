<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\myeventlane_event_studio\Service\PublishEligibilityEvaluator;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\PublishEligibilityEvaluator
 *
 * @group myeventlane_event_studio
 */
final class PublishEligibilityEvaluatorTest extends UnitTestCase {

  /**
   * @covers ::evaluate
   */
  public function testEvaluatorDeclaresStructuredPublishResult(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/PublishEligibilityEvaluator.php');

    $this->assertIsString($source);
    $this->assertStringContainsString("'allowed'", $source);
    $this->assertStringContainsString("'reason'", $source);
    $this->assertStringContainsString("'messages'", $source);
    $this->assertStringContainsString('getLivePublishDenialReasons', $source);
    $this->assertStringContainsString('validatePaidPublishAllowed', $source);
    $this->assertStringContainsString('$this->eventReadiness->evaluate', $source);
    $this->assertStringContainsString("in_array(\$eventType, ['paid', 'both'], TRUE)", $source);
    $this->assertStringContainsString('catch (\\Throwable $e)', $source);
    $this->assertStringContainsString('evaluation_failed', $source);
  }

  /**
   * @covers ::evaluate
   */
  public function testEvaluatorMapsFailureReasons(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/PublishEligibilityEvaluator.php');

    $this->assertIsString($source);
    $this->assertStringContainsString("'vendor_denied'", $source);
    $this->assertStringContainsString("'stripe'", $source);
    $this->assertStringContainsString("'readiness'", $source);
    $this->assertStringContainsString("'invalid_event'", $source);
  }

  public function testSaveAndPublishPathsDelegateToEvaluatorOnly(): void {
    $save = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSaveService.php');
    $publish = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioPublishController.php');
    $services = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.services.yml');

    $this->assertIsString($save);
    $this->assertIsString($publish);
    $this->assertIsString($services);
    $this->assertStringContainsString('publishEligibilityEvaluator', $save);
    $this->assertStringNotContainsString('publishRequirementsGate', $save);
    $this->assertStringNotContainsString('paidPublishStripeGate', $save);
    $this->assertStringNotContainsString('$this->eventReadiness->evaluate', $save);
    $this->assertStringNotContainsString('if (!$readiness->ready)', $publish);
    $this->assertStringContainsString('return $this->setPublishedState($node, TRUE);', $publish);
    $this->assertStringContainsString('myeventlane_event_studio.publish_eligibility', $services);
  }

  /**
   * @covers ::evaluate
   */
  public function testEvaluatorClassIsInstantiableService(): void {
    $reflection = new \ReflectionClass(PublishEligibilityEvaluator::class);
    $this->assertTrue($reflection->hasMethod('evaluate'));
    $this->assertFalse($reflection->getMethod('evaluate')->isStatic());
  }

  /**
   * @covers ::evaluate
   */
  public function testEvaluatorCoversPaidHybridAndRsvpEventTypes(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/PublishEligibilityEvaluator.php');

    $this->assertIsString($source);
    $this->assertStringContainsString("return 'rsvp';", $source);
    $this->assertStringContainsString("in_array(\$eventType, ['paid', 'both'], TRUE)", $source);
  }

}
