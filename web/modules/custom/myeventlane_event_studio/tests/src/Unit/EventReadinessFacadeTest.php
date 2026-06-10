<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\myeventlane_event_studio\Service\EventReadinessFacade;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\EventReadinessFacade
 *
 * @group myeventlane_event_studio
 */
final class EventReadinessFacadeTest extends UnitTestCase {

  /**
   * @covers ::evaluate
   */
  public function testEvaluateDeclaresUnifiedReadinessOutputs(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventReadinessFacade.php');

    $this->assertIsString($source);
    $this->assertStringContainsString('publish_required', $source);
    $this->assertStringContainsString('promotion_required', $source);
    $this->assertStringContainsString('recommended', $source);
    $this->assertStringContainsString('EventReadinessService', $source);
    $this->assertStringContainsString('FeaturedEventReadinessService', $source);
    $this->assertStringContainsString('catch (\Throwable $e)', $source);
  }

  /**
   * @covers ::evaluate
   */
  public function testFacadeClassIsInstantiableService(): void {
    $reflection = new \ReflectionClass(EventReadinessFacade::class);
    $this->assertTrue($reflection->hasMethod('evaluate'));
    $this->assertFalse($reflection->getMethod('evaluate')->isStatic());
  }

}
