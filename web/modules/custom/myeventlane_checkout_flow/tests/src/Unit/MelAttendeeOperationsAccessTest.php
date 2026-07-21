<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_checkout_flow\Service\MelAttendeeOperationsAccess;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_checkout_flow\Service\MelAttendeeOperationsAccess
 *
 * @group myeventlane_checkout_flow
 */
final class MelAttendeeOperationsAccessTest extends UnitTestCase {

  /**
   * Sets up cache_contexts_manager for AccessResult without full bootstrap.
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
   * @covers ::canViewAttendees
   */
  public function testNonEventBundleIsForbidden(): void {
    $event = $this->mockNode('article');
    $account = $this->createMock(AccountInterface::class);
    $access = $this->makeAccess(workspaceParity: FALSE);
    $result = $access->canViewAttendees($event, $account);
    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::canViewAttendees
   */
  public function testAdminPermissionAllows(): void {
    $event = $this->mockNode('event');
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnMap([
        [MelAttendeeOperationsAccess::PERM_ADMIN, TRUE],
        [MelAttendeeOperationsAccess::PERM_ADMIN_COMMERCE, FALSE],
      ]);
    $access = $this->makeAccess(workspaceParity: FALSE);
    $result = $access->canViewAttendees($event, $account);
    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::canViewAttendees
   */
  public function testAnonymousIsForbidden(): void {
    $event = $this->mockNode('event');
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $account->method('isAnonymous')->willReturn(TRUE);
    $access = $this->makeAccess(workspaceParity: FALSE);
    $result = $access->canViewAttendees($event, $account);
    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::canViewAttendees
   * @covers ::canExportAttendees
   * @covers ::canCheckInAttendees
   * @covers ::canCancelAttendance
   */
  public function testWorkspaceParityAllows(): void {
    $event = $this->mockNode('event');
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $account->method('isAnonymous')->willReturn(FALSE);
    $access = $this->makeAccess(workspaceParity: TRUE);
    $this->assertTrue($access->canViewAttendees($event, $account)->isAllowed());
    $this->assertTrue($access->canExportAttendees($event, $account)->isAllowed());
    $this->assertTrue($access->canCheckInAttendees($event, $account)->isAllowed());
    $this->assertTrue($access->canCancelAttendance($event, $account)->isAllowed());
  }

  /**
   * @covers ::canViewAttendees
   */
  public function testAuthenticatedNoOwnershipFailsClosed(): void {
    $event = $this->mockNode('event');
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $account->method('isAnonymous')->willReturn(FALSE);
    $access = $this->makeAccess(workspaceParity: FALSE);
    $result = $access->canViewAttendees($event, $account);
    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::accountHasOrganiserOwnership
   */
  public function testAccountHasOrganiserOwnershipDelegatesToChecker(): void {
    $event = $this->mockNode('event');
    $account = $this->createMock(AccountInterface::class);
    $access = $this->makeAccess(workspaceParity: TRUE);
    $this->assertTrue($access->accountHasOrganiserOwnership($event, $account));
    $denied = $this->makeAccess(workspaceParity: FALSE);
    $this->assertFalse($denied->accountHasOrganiserOwnership($event, $account));
  }

  /**
   * @covers ::canViewAttendeeRow
   */
  public function testAttendeeRowWithoutEventFailsClosed(): void {
    $attendee = $this->createMock(EventAttendee::class);
    $attendee->method('getEvent')->willReturn(NULL);
    $attendee->method('getCacheContexts')->willReturn([]);
    $attendee->method('getCacheTags')->willReturn([]);
    $attendee->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $account = $this->createMock(AccountInterface::class);
    $access = $this->makeAccess(workspaceParity: TRUE);
    $result = $access->canViewAttendeeRow($attendee, $account);
    $this->assertTrue($result->isForbidden());
  }

  /**
   * Builds MelAttendeeOperationsAccess with a stubbed parity checker.
   */
  private function makeAccess(bool $workspaceParity): MelAttendeeOperationsAccess {
    $checker = $this->createMock(EventVendorAccessCheckerInterface::class);
    $checker->method('accountHasWorkspaceParityForEvent')->willReturn($workspaceParity);
    return new MelAttendeeOperationsAccess(
      $checker,
      $this->createMock(LoggerChannelInterface::class),
    );
  }

  /**
   * Builds a stub node of the given bundle.
   */
  private function mockNode(string $bundle): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn($bundle);
    $event->method('id')->willReturn(1);
    $event->method('getCacheContexts')->willReturn([]);
    $event->method('getCacheTags')->willReturn([]);
    $event->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    return $event;
  }

}
