<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the Saved Events continuation path.
 *
 * @group myeventlane_surface
 */
final class MelSavedEventsWorkflowContractTest extends TestCase {

  public function testBrowseEventsActionDoesNotLoopBackToSavedEvents(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $registry = (string) file_get_contents($moduleRoot . '/src/MelWorkflowRegistry.php');
    $savedWorkflowStart = strpos($registry, "'first_saved_event' =>");
    $this->assertNotFalse($savedWorkflowStart);
    $savedWorkflowEnd = strpos($registry, "'calendar_connected' =>", $savedWorkflowStart);
    $this->assertNotFalse($savedWorkflowEnd);
    $savedWorkflow = substr($registry, $savedWorkflowStart, $savedWorkflowEnd - $savedWorkflowStart);

    $this->assertStringContainsString(
      "primaryCtaRouteName: 'view.upcoming_events.page_events'",
      $savedWorkflow,
    );
    $this->assertStringContainsString("primaryCtaTitle: 'Browse events'", $savedWorkflow);
    $this->assertStringNotContainsString(
      "primaryCtaRouteName: 'view.mel_saved_events.page_1'",
      $savedWorkflow,
    );
  }

}
