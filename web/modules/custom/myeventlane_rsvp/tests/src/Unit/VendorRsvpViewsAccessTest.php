<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_rsvp\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_rsvp\Plugin\views\access\VendorAccess;
use PHPUnit\Framework\TestCase;

/**
 * Page-level access for vendor RSVP Views.
 *
 * @coversDefaultClass \Drupal\myeventlane_rsvp\Plugin\views\access\VendorAccess
 *
 * @group myeventlane_rsvp
 */
final class VendorRsvpViewsAccessTest extends TestCase {

  /**
   * @covers ::access
   */
  public function testAnonymousDenied(): void {
    $plugin = $this->plugin();
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $account->method('isAuthenticated')->willReturn(FALSE);
    $this->assertFalse($plugin->access($account));
  }

  /**
   * @covers ::access
   */
  public function testVendorWithCreateEventAllowed(): void {
    $plugin = $this->plugin();
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $perm): bool => $perm === 'create event content'
    );
    $this->assertTrue($plugin->access($account));
  }

  /**
   * @covers ::access
   */
  public function testAuthenticatedWithoutOrganiserCapabilityDenied(): void {
    $plugin = $this->plugin();
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('hasPermission')->willReturn(FALSE);
    $this->assertFalse($plugin->access($account));
  }

  private function plugin(): VendorAccess {
    return new VendorAccess([], 'myeventlane_rsvp_vendor_access', []);
  }

}
