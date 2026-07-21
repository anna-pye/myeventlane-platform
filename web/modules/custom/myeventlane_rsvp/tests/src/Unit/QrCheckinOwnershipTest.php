<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_rsvp\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_rsvp\Controller\QrCheckinController;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Ownership matrix for QrCheckinController::canManageEvent.
 *
 * @group myeventlane_rsvp
 */
final class QrCheckinOwnershipTest extends TestCase {

  /**
   * Verifies RSVP product/admin gates plus workspace parity.
   *
   * @dataProvider actorProvider
   */
  public function testCanManageEventMatrix(
    array $permissions,
    bool $hasParity,
    bool $expected,
  ): void {
    $controller = $this->controller($permissions, $hasParity);
    $method = new \ReflectionMethod(QrCheckinController::class, 'canManageEvent');
    $method->setAccessible(TRUE);
    $this->assertSame($expected, $method->invoke($controller, $this->event()));
  }

  /**
   * Workspace parity must be invoked for non-admin organisers.
   */
  public function testParityPathExercised(): void {
    $checker = $this->createMock(EventVendorAccessCheckerInterface::class);
    $checker->expects($this->once())
      ->method('accountHasWorkspaceParityForEvent')
      ->willReturn(TRUE);
    $controller = $this->controllerWithChecker(
      ['manage own event rsvps' => TRUE],
      $checker,
    );
    $method = new \ReflectionMethod(QrCheckinController::class, 'canManageEvent');
    $method->setAccessible(TRUE);
    $this->assertTrue($method->invoke($controller, $this->event()));
  }

  /**
   * Unrelated organisers with manage permission but no parity are denied.
   */
  public function testUnrelatedOrganiserDenied(): void {
    $controller = $this->controller(
      ['manage own event rsvps' => TRUE],
      hasParity: FALSE,
    );
    $method = new \ReflectionMethod(QrCheckinController::class, 'canManageEvent');
    $method->setAccessible(TRUE);
    $this->assertFalse($method->invoke($controller, $this->event()));
  }

  /**
   * Ownership must not depend on optional Mel / checkout_flow.
   */
  public function testDoesNotRequireMelAttendeeOps(): void {
    $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controller/QrCheckinController.php');
    $this->assertStringContainsString('EventVendorAccessCheckerInterface', $raw);
    $this->assertStringContainsString('accountHasWorkspaceParityForEvent', $raw);
    $this->assertStringNotContainsString('MelAttendeeOperationsAccess', $raw);
    $this->assertStringNotContainsString('attendee_operations_access', $raw);
  }

  /**
   * Actor matrix for QR validate ownership.
   *
   * @return \Generator
   *   Cases: permission map, parity, expected allow.
   */
  public static function actorProvider(): \Generator {
    yield 'admin rsvps' => [['administer rsvps' => TRUE], FALSE, TRUE];
    yield 'admin nodes' => [['administer nodes' => TRUE], FALSE, TRUE];
    yield 'author/owner with manage + parity' => [['manage own event rsvps' => TRUE], TRUE, TRUE];
    yield 'team with manage + parity' => [['manage own event rsvps' => TRUE], TRUE, TRUE];
    yield 'manage without parity' => [['manage own event rsvps' => TRUE], FALSE, FALSE];
    yield 'parity without manage perm' => [[], TRUE, FALSE];
  }

  /**
   * Builds a controller with stub workspace parity.
   *
   * @param array<string, bool> $permissions
   *   Permission map.
   * @param bool $hasParity
   *   Whether the checker reports workspace parity.
   */
  private function controller(array $permissions, bool $hasParity): QrCheckinController {
    $checker = $this->createMock(EventVendorAccessCheckerInterface::class);
    $checker->method('accountHasWorkspaceParityForEvent')->willReturn($hasParity);
    return $this->controllerWithChecker($permissions, $checker);
  }

  /**
   * Builds a controller with an injected checker mock.
   *
   * @param array<string, bool> $permissions
   *   Permission map.
   * @param \Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface $checker
   *   Event vendor access checker mock.
   */
  private function controllerWithChecker(
    array $permissions,
    EventVendorAccessCheckerInterface $checker,
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
      $checker,
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
