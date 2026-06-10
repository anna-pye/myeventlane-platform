<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_boost\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Contract tests for boost display readiness facade consolidation.
 *
 * @group myeventlane_boost
 */
final class BoostDisplayReadinessConsolidationTest extends UnitTestCase {

  public function testBoostControllersUseReadinessFacadeForHomepageCard(): void {
    $boost = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/BoostController.php');
    $success = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/BoostPurchaseSuccessController.php');
    $info = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_boost.info.yml');

    $this->assertIsString($boost);
    $this->assertIsString($success);
    $this->assertIsString($info);
    $this->assertStringContainsString('EventReadinessFacade', $boost);
    $this->assertStringContainsString('buildChecklistCardFromPresentation', $boost);
    $this->assertStringNotContainsString('buildChecklistCard(', $boost);
    $this->assertStringContainsString('EventReadinessFacade', $success);
    $this->assertStringContainsString('buildChecklistCardFromPresentation', $success);
    $this->assertStringNotContainsString('buildChecklistCard(', $success);
    $this->assertStringContainsString('myeventlane_event_studio:myeventlane_event_studio', $info);
  }

}
