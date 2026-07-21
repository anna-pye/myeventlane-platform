<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_attendees\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\EventAttendeeAccessControlHandler;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Ownership matrix for EventAttendeeAccessControlHandler.
 *
 * @covers \Drupal\myeventlane_event_attendees\EventAttendeeAccessControlHandler
 *
 * @group myeventlane_event_attendees
 */
final class EventAttendeeAccessControlHandlerOwnershipTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * Verifies view access for admin, product permission, and parity actors.
   *
   * @dataProvider viewActorProvider
   */
  public function testViewAccess(
    bool $isAdmin,
    bool $hasViewOwn,
    bool $hasParity,
    bool $expectedAllowed,
  ): void {
    $handler = $this->handler($hasParity);
    $method = new \ReflectionMethod(EventAttendeeAccessControlHandler::class, 'checkAccess');
    $method->setAccessible(TRUE);
    $result = $method->invoke($handler, $this->attendee(), 'view', $this->account($isAdmin, $hasViewOwn, FALSE));
    $this->assertSame($expectedAllowed, $result->isAllowed());
  }

  /**
   * Vendor owners with view-own + parity may view attendee entities.
   */
  public function testVendorOwnerWithViewOwnAllowed(): void {
    $handler = $this->handler(hasParity: TRUE);
    $method = new \ReflectionMethod(EventAttendeeAccessControlHandler::class, 'checkAccess');
    $method->setAccessible(TRUE);
    $result = $method->invoke(
      $handler,
      $this->attendee(),
      'view',
      $this->account(FALSE, TRUE, FALSE),
    );
    $this->assertTrue($result->isAllowed());
  }

  /**
   * Unrelated organisers without parity remain denied on view.
   */
  public function testUnrelatedDenied(): void {
    $handler = $this->handler(hasParity: FALSE);
    $method = new \ReflectionMethod(EventAttendeeAccessControlHandler::class, 'checkAccess');
    $method->setAccessible(TRUE);
    $result = $method->invoke(
      $handler,
      $this->attendee(),
      'view',
      $this->account(FALSE, TRUE, FALSE),
    );
    $this->assertFalse($result->isAllowed());
  }

  /**
   * Actor matrix for entity view access.
   *
   * @return \Generator
   *   Cases: admin, view-own, parity, expected allow.
   */
  public static function viewActorProvider(): \Generator {
    yield 'admin' => [TRUE, FALSE, FALSE, TRUE];
    yield 'parity + view own' => [FALSE, TRUE, TRUE, TRUE];
    yield 'parity without view own' => [FALSE, FALSE, TRUE, FALSE];
    yield 'view own without parity' => [FALSE, TRUE, FALSE, FALSE];
  }

  /**
   * Builds a handler with a stubbed parity checker.
   */
  private function handler(bool $hasParity): EventAttendeeAccessControlHandler {
    $checker = $this->createMock(EventVendorAccessCheckerInterface::class);
    $checker->method('accountHasWorkspaceParityForEvent')->willReturn($hasParity);
    $entityType = $this->createMock(EntityTypeInterface::class);
    $entityType->method('id')->willReturn('event_attendee');
    $entityType->method('getListCacheContexts')->willReturn([]);
    return new EventAttendeeAccessControlHandler($entityType, $checker);
  }

  /**
   * Builds a stub attendee bound to an event.
   */
  private function attendee(): EventAttendee {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('id')->willReturn(1);

    $attendee = $this->createMock(EventAttendee::class);
    $attendee->method('getEvent')->willReturn($event);
    $attendee->method('getCacheContexts')->willReturn([]);
    $attendee->method('getCacheTags')->willReturn(['event_attendee:1']);
    $attendee->method('getCacheMaxAge')->willReturn(-1);
    return $attendee;
  }

  /**
   * Builds a stub account with attendee product permissions.
   */
  private function account(bool $isAdmin, bool $hasViewOwn, bool $hasManageOwn): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(20);
    $account->method('hasPermission')->willReturnCallback(
      static function (string $permission) use ($isAdmin, $hasViewOwn, $hasManageOwn): bool {
        return match ($permission) {
          'administer event attendees' => $isAdmin,
          'view own event attendees' => $hasViewOwn,
          'manage own event attendees' => $hasManageOwn,
          default => FALSE,
        };
      },
    );
    return $account;
  }

}
