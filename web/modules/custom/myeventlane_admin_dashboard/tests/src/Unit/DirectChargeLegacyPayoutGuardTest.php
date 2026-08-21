<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_admin_dashboard\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the fail-closed boundary between direct charges and MEL transfers.
 */
final class DirectChargeLegacyPayoutGuardTest extends TestCase {

  public function testAllLegacyPayoutRoutesUseTheDirectChargeAccessGuard(): void {
    $routing = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_admin_dashboard.routing.yml');
    self::assertIsString($routing);
    self::assertSame(13, substr_count($routing, 'LegacyPayoutAccess::access'));
  }

  public function testTransferExecutorsAlsoGuardProgrammaticCalls(): void {
    $single = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/PayoutTransferController.php');
    $batch = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/PayoutBatchController.php');
    $workflow = file_get_contents(dirname(__DIR__, 3) . '/src/Service/PayoutBatchWorkflowService.php');

    self::assertIsString($single);
    self::assertIsString($batch);
    self::assertIsString($workflow);
    self::assertStringContainsString("get('direct_charge_enabled')", $single);
    self::assertStringContainsString("get('direct_charge_enabled')", $batch);
    self::assertStringContainsString('assertLegacyTransfersAllowed', $workflow);
  }

  public function testMigrationSwitchDefaultsOff(): void {
    $install = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_core/config/install/myeventlane_core.settings.yml');
    self::assertIsString($install);
    self::assertStringContainsString('direct_charge_enabled: false', $install);
    self::assertStringContainsString('direct_charge_fee_model_approved: false', $install);
  }

}
