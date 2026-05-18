<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_event_studio\Service\EventStyleAccessManager;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\EventStyleAccessManager
 *
 * @group myeventlane_event_studio
 */
final class EventStyleAccessManagerTest extends UnitTestCase {

  /**
   * @covers ::canUseImmersiveStyle
   */
  public function testDefaultFalseForNormalVendorAccount(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('administer nodes')->willReturn(FALSE);

    $event = $this->createMock(NodeInterface::class);
    $manager = new EventStyleAccessManager(NULL);

    $this->assertFalse($manager->canUseImmersiveStyle($event, $account));
  }

  /**
   * @covers ::canUseImmersiveStyle
   */
  public function testTrueForAdminPermission(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('administer nodes')->willReturn(TRUE);

    $event = $this->createMock(NodeInterface::class);
    $manager = new EventStyleAccessManager();

    $this->assertTrue($manager->canUseImmersiveStyle($event, $account));
  }

  /**
   * @covers ::canUseImmersiveStyle
   */
  public function testUnknownFeatureDoesNotGrantViaMissingProService(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $event = $this->createMock(NodeInterface::class);
    $manager = new EventStyleAccessManager(NULL);

    $this->assertFalse($manager->canUseImmersiveStyle($event, $account));
  }

}
