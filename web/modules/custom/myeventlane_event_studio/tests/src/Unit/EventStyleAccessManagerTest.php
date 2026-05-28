<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_event_studio\Service\EventPageStyleResolver;
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
   * @covers ::canCustomizeEventPage
   * @covers ::canUseImmersiveStyle
   */
  public function testDefaultFalseForNormalVendorAccount(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('administer nodes')->willReturn(FALSE);

    $event = $this->createMock(NodeInterface::class);
    $manager = new EventStyleAccessManager(NULL);

    $this->assertFalse($manager->canCustomizeEventPage($account));
    $this->assertFalse($manager->canUseImmersiveStyle($event, $account));
  }

  /**
   * @covers ::canCustomizeEventPage
   * @covers ::canUseStyle
   * @covers ::canUseColour
   */
  public function testTrueForAdminPermission(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('administer nodes')->willReturn(TRUE);

    $event = $this->createMock(NodeInterface::class);
    $manager = new EventStyleAccessManager();

    $this->assertTrue($manager->canCustomizeEventPage($account));
    $this->assertTrue($manager->canUseStyle(EventPageStyleResolver::STYLE_IMMERSIVE, $account));
    $this->assertTrue($manager->canUseColour(EventPageStyleResolver::COLOUR_PURPLE, $account));
  }

  /**
   * @covers ::canUseStyle
   * @covers ::canUseColour
   */
  public function testNonProMayUseClassicAndCoralOnly(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $manager = new EventStyleAccessManager(NULL);

    $this->assertTrue($manager->canUseStyle(EventPageStyleResolver::STYLE_CLASSIC, $account));
    $this->assertFalse($manager->canUseStyle(EventPageStyleResolver::STYLE_IMMERSIVE, $account));
    $this->assertTrue($manager->canUseColour(EventPageStyleResolver::COLOUR_CORAL, $account));
    $this->assertFalse($manager->canUseColour(EventPageStyleResolver::COLOUR_PURPLE, $account));
  }

}
