<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\media\MediaInterface;
use Drupal\myeventlane_vendor\Service\OrganiserMediaAccess;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_vendor\Service\OrganiserMediaAccess
 * @group myeventlane_vendor
 */
final class OrganiserMediaAccessTest extends UnitTestCase {

  /**
   * @covers ::canSelect
   * @covers ::allowedOwnerIds
   */
  public function testOrganiserCanSelectOnlyOwnMedia(): void {
    $account = $this->account(23, FALSE);
    $service = new OrganiserMediaAccess($this->proxy($account));

    $own_media = $this->createMock(MediaInterface::class);
    $own_media->method('getOwnerId')->willReturn(23);
    $other_media = $this->createMock(MediaInterface::class);
    $other_media->method('getOwnerId')->willReturn(99);

    self::assertSame([23], $service->allowedOwnerIds());
    self::assertTrue($service->canSelect($own_media));
    self::assertFalse($service->canSelect($other_media));
  }

  /**
   * @covers ::canSelect
   * @covers ::hasUnrestrictedAccess
   */
  public function testStaffPermissionCanSelectAnyMedia(): void {
    $account = $this->account(7, TRUE);
    $service = new OrganiserMediaAccess($this->proxy($account));
    $media = $this->createMock(MediaInterface::class);
    $media->method('getOwnerId')->willReturn(99);

    self::assertTrue($service->hasUnrestrictedAccess());
    self::assertTrue($service->canSelect($media));
  }

  /**
   * Creates a test account.
   */
  private function account(int $uid, bool $unrestricted): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')
      ->with(OrganiserMediaAccess::ACCESS_ALL_PERMISSION)
      ->willReturn($unrestricted);
    return $account;
  }

  /**
   * Creates the current-user proxy used by the service.
   */
  private function proxy(AccountInterface $account): AccountProxyInterface {
    $proxy = $this->createMock(AccountProxyInterface::class);
    $proxy->method('id')->willReturn($account->id());
    $proxy->method('hasPermission')
      ->with(OrganiserMediaAccess::ACCESS_ALL_PERMISSION)
      ->willReturn($account->hasPermission(OrganiserMediaAccess::ACCESS_ALL_PERMISSION));
    return $proxy;
  }

}
