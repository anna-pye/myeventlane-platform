<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_rsvp\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_rsvp\Service\RsvpOrganiserViewScope;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQueryInterface;
use Drupal\views\Plugin\views\query\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for organiser RSVP Views scoping.
 *
 * @coversDefaultClass \Drupal\myeventlane_rsvp\Service\RsvpOrganiserViewScope
 *
 * @group myeventlane_rsvp
 */
final class RsvpOrganiserViewScopeTest extends TestCase {

  /**
   * @covers ::accountBypassesOrganiserScope
   */
  public function testStaffBypassesScope(): void {
    $scope = new RsvpOrganiserViewScope($this->createMock(UserVendorMembershipQueryInterface::class));
    $admin = $this->createMock(AccountInterface::class);
    $admin->method('hasPermission')->willReturnCallback(
      static fn (string $perm): bool => $perm === 'administer nodes'
    );
    $this->assertTrue($scope->accountBypassesOrganiserScope($admin));
  }

  /**
   * @covers ::getManagedEventIds
   */
  public function testAnonymousHasNoManagedEvents(): void {
    $membership = $this->createMock(UserVendorMembershipQueryInterface::class);
    $membership->expects($this->never())->method('getManagedEventNodeIds');
    $scope = new RsvpOrganiserViewScope($membership);
    $anon = $this->createMock(AccountInterface::class);
    $anon->method('id')->willReturn(0);
    $this->assertSame([], $scope->getManagedEventIds($anon));
  }

  /**
   * @covers ::applyToViewsQuery
   */
  public function testApplyFailsClosedWhenNoManagedEvents(): void {
    $membership = $this->createMock(UserVendorMembershipQueryInterface::class);
    $membership->method('getManagedEventNodeIds')->willReturn([]);
    $scope = new RsvpOrganiserViewScope($membership);

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(10);
    $account->method('hasPermission')->willReturn(FALSE);

    $query = $this->getMockBuilder(Sql::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['ensureTable', 'addWhereExpression', 'addWhere'])
      ->getMock();
    $query->method('ensureTable')->willReturn('rsvp_submission');
    $query->expects($this->once())->method('addWhereExpression')->with(0, '1 = 0');
    $query->expects($this->never())->method('addWhere');

    $scope->applyToViewsQuery($query, $account);
  }

  /**
   * @covers ::applyToViewsQuery
   */
  public function testApplyScopesToManagedEventIdsOnly(): void {
    $membership = $this->createMock(UserVendorMembershipQueryInterface::class);
    $membership->method('getManagedEventNodeIds')->with(10, FALSE)->willReturn([55, 66]);
    $scope = new RsvpOrganiserViewScope($membership);

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(10);
    $account->method('hasPermission')->willReturn(FALSE);

    $query = $this->getMockBuilder(Sql::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['ensureTable', 'addWhereExpression', 'addWhere'])
      ->getMock();
    $query->method('ensureTable')->willReturn('rsvp_submission');
    $query->expects($this->never())->method('addWhereExpression');
    $query->expects($this->once())
      ->method('addWhere')
      ->with(0, 'rsvp_submission.event_id', [55, 66], 'IN');

    $scope->applyToViewsQuery($query, $account);
  }

  /**
   * @covers ::applyToViewsQuery
   */
  public function testStaffDoesNotAddOrganiserWhere(): void {
    $membership = $this->createMock(UserVendorMembershipQueryInterface::class);
    $membership->expects($this->never())->method('getManagedEventNodeIds');
    $scope = new RsvpOrganiserViewScope($membership);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $perm): bool => $perm === 'administer rsvps'
    );

    $query = $this->getMockBuilder(Sql::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['ensureTable', 'addWhereExpression', 'addWhere'])
      ->getMock();
    $query->expects($this->never())->method('addWhereExpression');
    $query->expects($this->never())->method('addWhere');

    $scope->applyToViewsQuery($query, $account);
  }

}
