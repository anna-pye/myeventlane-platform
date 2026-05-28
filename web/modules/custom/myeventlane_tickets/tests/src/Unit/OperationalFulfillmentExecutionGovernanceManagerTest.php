<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Unit;

use Drupal\myeventlane_tickets\Service\OperationalFulfillmentExecutionGovernanceManager;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\OperationalFulfillmentExecutionGovernanceManager
 *
 * @group myeventlane_tickets
 */
final class OperationalFulfillmentExecutionGovernanceManagerTest extends UnitTestCase {

  public function testEmptyBundleIsNominal(): void {
    $gov = new OperationalFulfillmentExecutionGovernanceManager($this->getStringTranslationStub());
    $out = $gov->projectGovernance(['empty' => TRUE]);
    $this->assertSame('nominal', $out['severity']);
    $this->assertSame(0, $out['stalled_executions']);
  }

}
