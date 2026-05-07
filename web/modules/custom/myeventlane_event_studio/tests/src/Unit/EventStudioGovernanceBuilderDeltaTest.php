<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\myeventlane_event_studio\Service\EventStudioGovernanceBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\EventStudioGovernanceBuilder
 *
 * @group myeventlane_event_studio
 */
final class EventStudioGovernanceBuilderDeltaTest extends TestCase {

  /**
   * @covers ::computeJsDelta
   */
  public function testComputeJsDeltaIncludesChangedKeys(): void {
    $next = [
      'version' => 2,
      'nextBestText' => 'a',
      'nested' => ['x' => 1],
    ];
    $baseline = [
      'version' => 2,
      'nextBestText' => 'b',
      'nested' => ['x' => 1],
    ];
    $delta = EventStudioGovernanceBuilder::computeJsDelta($next, $baseline);
    $this->assertArrayHasKey('nextBestText', $delta);
    $this->assertSame('a', $delta['nextBestText']);
    $this->assertArrayNotHasKey('nested', $delta);
  }

  /**
   * @covers ::computeJsDelta
   */
  public function testComputeJsDeltaEmptyWhenIdentical(): void {
    $payload = ['version' => 2, 'checklist' => [['id' => 'x']]];
    $delta = EventStudioGovernanceBuilder::computeJsDelta($payload, $payload);
    $this->assertSame([], $delta);
  }

}
