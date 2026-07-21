<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract coverage for Event Studio organiser-owner parity.
 *
 * CurrentVendorResolver is final, so the access checker is not unit-mocked.
 * This guards the Phase 1 decision: workspace parity is sufficient; node
 * entity update access must not block organiser owners or team members.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioAccessParityTest extends TestCase {

  /**
   * Ensures Studio access relies on workspace parity, not node update grants.
   */
  public function testStudioAccessUsesWorkspaceParityWithoutNodeUpdateGate(): void {
    $path = dirname(__DIR__, 3) . '/src/Access/EventStudioAccess.php';
    $this->assertFileExists($path);
    $raw = file_get_contents($path);
    $this->assertIsString($raw);
    $this->assertStringContainsString('accountHasWorkspaceParityForEvent', $raw);
    $this->assertStringContainsString('EventVendorAccessCheckerInterface', $raw);
    $this->assertStringNotContainsString("access('update'", $raw);
    $this->assertStringNotContainsString('access("update"', $raw);
  }

  /**
   * Mutation routes must not AND EventStudioAccess with node.update.
   */
  public function testStudioMutationRoutesDoNotRequireNodeUpdateEntityAccess(): void {
    $path = dirname(__DIR__, 3) . '/myeventlane_event_studio.routing.yml';
    $this->assertFileExists($path);
    $raw = file_get_contents($path);
    $this->assertIsString($raw);
    $this->assertStringNotContainsString("_entity_access: 'node.update'", $raw);
    $this->assertStringNotContainsString('_entity_access: "node.update"', $raw);
    $this->assertStringContainsString('myeventlane_event_studio.publish', $raw);
    $this->assertStringContainsString('EventStudioAccess::access', $raw);
  }

  /**
   * Autosave controller must gate on workspace parity, not node.update.
   */
  public function testAutosaveControllerUsesWorkspaceParityWithoutNodeUpdateGate(): void {
    $path = dirname(__DIR__, 3) . '/src/Controller/EventStudioAutosaveController.php';
    $this->assertFileExists($path);
    $raw = file_get_contents($path);
    $this->assertIsString($raw);
    $this->assertStringContainsString('accountHasWorkspaceParityForEvent', $raw);
    $this->assertStringNotContainsString("access('update'", $raw);
    $this->assertStringNotContainsString('access("update"', $raw);
  }

}
