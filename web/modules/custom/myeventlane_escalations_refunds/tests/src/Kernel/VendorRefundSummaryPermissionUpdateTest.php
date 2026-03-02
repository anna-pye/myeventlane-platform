<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_escalations_refunds\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies vendor refund summary permission grant update.
 *
 * @group myeventlane_escalations_refunds
 */
#[RunTestsInSeparateProcesses]
final class VendorRefundSummaryPermissionUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'myeventlane_escalations_refunds',
  ];

  /**
   * Grants missing permission to the vendor role.
   */
  public function testUpdate9001GrantsVendorPermission(): void {
    $this->installEntitySchema('user');
    $this->installConfig(['user']);

    $vendor = Role::create([
      'id' => 'vendor',
      'label' => 'Vendor',
    ]);
    $vendor->grantPermission('access content');
    $vendor->save();

    $this->assertFalse($vendor->hasPermission('view vendor refund summary'));

    myeventlane_escalations_refunds_update_9001();

    $reloaded = Role::load('vendor');
    $this->assertNotNull($reloaded);
    $this->assertTrue($reloaded->hasPermission('view vendor refund summary'));
  }

}

