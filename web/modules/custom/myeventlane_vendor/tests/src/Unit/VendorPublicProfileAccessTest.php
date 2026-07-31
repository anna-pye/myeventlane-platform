<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Entity\VendorAccessControlHandler;
use Drupal\Tests\UnitTestCase;

/**
 * Verifies that organiser profile access fails closed.
 *
 * @group myeventlane_vendor
 */
final class VendorPublicProfileAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $cache_contexts = $this->createMock(CacheContextsManager::class);
    $cache_contexts->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts);
    \Drupal::setContainer($container);
  }

  /**
   * Private profiles remain owner/admin-only; published profiles are public.
   */
  public function testPublicProfileAccessRequiresExplicitPublication(): void {
    $entity_type = $this->createMock(EntityTypeInterface::class);
    $handler = new class($entity_type) extends VendorAccessControlHandler {

      public function checkVendorAccess(Vendor $vendor, string $operation, AccountInterface $account) {
        return $this->checkAccess($vendor, $operation, $account);
      }

    };

    $private_vendor = $this->vendor(FALSE, 10);
    $published_vendor = $this->vendor(TRUE, 10);

    $owner = $this->account(10, FALSE, TRUE);
    $viewer = $this->account(20, FALSE, TRUE);
    $admin = $this->account(30, TRUE, TRUE);

    $this->assertTrue($handler->checkVendorAccess($private_vendor, 'view', $owner)->isAllowed());
    $this->assertFalse($handler->checkVendorAccess($private_vendor, 'view', $viewer)->isAllowed());
    $this->assertTrue($handler->checkVendorAccess($private_vendor, 'view', $admin)->isAllowed());
    $this->assertTrue($handler->checkVendorAccess($published_vendor, 'view', $viewer)->isAllowed());
  }

  /**
   * Builds a vendor mock with publication and ownership state.
   */
  private function vendor(bool $published, int $owner_id): Vendor {
    $vendor = $this->createMock(Vendor::class);
    $vendor->method('isPublicProfilePublished')->willReturn($published);
    $vendor->method('getOwnerId')->willReturn($owner_id);
    $vendor->method('getCacheContexts')->willReturn([]);
    $vendor->method('getCacheTags')->willReturn(['myeventlane_vendor:1']);
    $vendor->method('getCacheMaxAge')->willReturn(-1);
    return $vendor;
  }

  /**
   * Builds an account mock with the permissions relevant to public access.
   */
  private function account(int $uid, bool $admin, bool $access_content): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => match ($permission) {
        'administer myeventlane vendor' => $admin,
        'access content' => $access_content,
        default => FALSE,
      },
    );
    return $account;
  }

}
