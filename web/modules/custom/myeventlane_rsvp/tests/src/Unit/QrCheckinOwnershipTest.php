<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_rsvp\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_checkout_flow\Service\MelAttendeeOperationsAccessInterface;
use Drupal\myeventlane_rsvp\Controller\QrCheckinController;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Ownership matrix for QrCheckinController::canManageEvent.
 *
 * @group myeventlane_rsvp
 */
final class QrCheckinOwnershipTest extends TestCase {

  /**
   * Verifies RSVP product/admin gates plus Mel ownership hop.
   *
   * @dataProvider actorProvider
   */
  public function testCanManageEventMatrix(
    array $permissions,
    bool $hasOwnership,
    bool $expected,
  ): void {
    $controller = $this->controller($permissions, $hasOwnership);
    $method = new \ReflectionMethod(QrCheckinController::class, 'canManageEvent');
    $method->setAccessible(TRUE);
    $this->assertSame($expected, $method->invoke($controller, $this->event()));
  }

  /**
   * Mel ownership hop must be invoked for non-admin organisers.
   */
  public function testMelOwnershipPathExercised(): void {
    $mel = $this->createMock(MelAttendeeOperationsAccessInterface::class);
    $mel->expects($this->once())
      ->method('accountHasOrganiserOwnership')
      ->willReturn(TRUE);
    $controller = $this->controllerWithMel(
      ['manage own event rsvps' => TRUE],
      $mel,
    );
    $method = new \ReflectionMethod(QrCheckinController::class, 'canManageEvent');
    $method->setAccessible(TRUE);
    $this->assertTrue($method->invoke($controller, $this->event()));
  }

  /**
   * Unrelated organisers with manage permission but no ownership are denied.
   */
  public function testUnrelatedOrganiserDenied(): void {
    $controller = $this->controller(
      ['manage own event rsvps' => TRUE],
      hasOwnership: FALSE,
    );
    $method = new \ReflectionMethod(QrCheckinController::class, 'canManageEvent');
    $method->setAccessible(TRUE);
    $this->assertFalse($method->invoke($controller, $this->event()));
  }

  /**
   * Actor matrix for QR validate ownership.
   *
   * @return \Generator
   *   Cases: permission map, ownership, expected allow.
   */
  public static function actorProvider(): \Generator {
    yield 'admin rsvps' => [['administer rsvps' => TRUE], FALSE, TRUE];
    yield 'admin nodes' => [['administer nodes' => TRUE], FALSE, TRUE];
    yield 'author/owner with manage + ownership' => [['manage own event rsvps' => TRUE], TRUE, TRUE];
    yield 'team with manage + ownership' => [['manage own event rsvps' => TRUE], TRUE, TRUE];
    yield 'manage without ownership' => [['manage own event rsvps' => TRUE], FALSE, FALSE];
    yield 'ownership without manage perm' => [[], TRUE, FALSE];
  }

  /**
   * Builds a controller with stub Mel ownership.
   *
   * @param array<string, bool> $permissions
   *   Permission map.
   * @param bool $hasOwnership
   *   Whether Mel reports organiser ownership.
   */
  private function controller(array $permissions, bool $hasOwnership): QrCheckinController {
    $mel = $this->createMock(MelAttendeeOperationsAccessInterface::class);
    $mel->method('accountHasOrganiserOwnership')->willReturn($hasOwnership);
    return $this->controllerWithMel($permissions, $mel);
  }

  /**
   * Builds a controller with an injected Mel mock.
   *
   * @param array<string, bool> $permissions
   *   Permission map.
   * @param \Drupal\myeventlane_checkout_flow\Service\MelAttendeeOperationsAccessInterface $mel
   *   Mel attendee operations access mock.
   */
  private function controllerWithMel(
    array $permissions,
    MelAttendeeOperationsAccessInterface $mel,
  ): QrCheckinController {
    $inner = $this->createMock(AccountInterface::class);
    $inner->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => !empty($permissions[$permission]),
    );

    $proxy = $this->createMock(AccountProxyInterface::class);
    $proxy->method('getAccount')->willReturn($inner);

    return new QrCheckinController(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(FloodInterface::class),
      $proxy,
      NULL,
      NULL,
      $mel,
    );
  }

  /**
   * Builds a stub event node.
   */
  private function event(): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('id')->willReturn(1);
    return $event;
  }

}
