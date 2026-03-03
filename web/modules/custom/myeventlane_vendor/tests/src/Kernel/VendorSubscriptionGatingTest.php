<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\RunTestsInSeparateProcesses;
use Drupal\myeventlane_vendor\Service\VendorSubscriptionService;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses as PhpUnitRunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Route;

/**
 * Verifies Pro subscription gating matrix for vendor analytics access.
 *
 * @group myeventlane_vendor
 */
#[RunTestsInSeparateProcesses]
#[PhpUnitRunTestsInSeparateProcesses]
final class VendorSubscriptionGatingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'flag',
    'myeventlane_core',
    'myeventlane_boost',
    'myeventlane_vendor',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  /**
   * Builds a subscription service with deterministic vendor Pro state.
   */
  private function buildSubscriptionService(array $pro_vendor_ids): VendorSubscriptionService {
    $storage = $this->createMock(\Drupal\Core\Entity\EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(static function (int $vendor_id) use ($pro_vendor_ids) {
      $is_pro = in_array($vendor_id, $pro_vendor_ids, TRUE);
      return new class($is_pro) {

        public function __construct(private readonly bool $isPro) {}

        public function hasField(string $field_name): bool {
          return $field_name === 'is_pro';
        }

        public function get(string $field_name): object {
          return new class($this->isPro) {

            public function __construct(private readonly bool $isPro) {}

            public function isEmpty(): bool {
              return FALSE;
            }

            public function __get(string $property): mixed {
              if ($property === 'value') {
                return $this->isPro ? 1 : 0;
              }
              return NULL;
            }

          };
        }

      };
    });

    $entity_type_manager = $this->createMock(\Drupal\Core\Entity\EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->with('myeventlane_vendor')
      ->willReturn($storage);

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('alter')->willReturn(NULL);

    $logger = $this->createMock(LoggerInterface::class);

    return new VendorSubscriptionService($entity_type_manager, $module_handler, $logger);
  }

  /**
   * Creates a test user with optional Pro analytics permission.
   */
  private function createUserWithProPermission(bool $with_pro_permission): AccountInterface {
    $role_id = $with_pro_permission ? 'vendor_pro_perm' : 'vendor_no_pro_perm';
    $role = Role::load($role_id);
    if (!$role) {
      $role = Role::create(['id' => $role_id, 'label' => $role_id]);
      $role->grantPermission('access content');
      if ($with_pro_permission) {
        $role->grantPermission('use pro financial analytics');
      }
      $role->save();
    }

    $user = User::create([
      'name' => 'vendor_' . $role_id . '_' . substr((string) microtime(TRUE), -6),
      'mail' => $role_id . '@example.com',
      'status' => 1,
      'roles' => [$role_id],
    ]);
    $user->save();

    return $user;
  }

  /**
   * Validates matrix: is_pro=0 + permission=yes => denied.
   */
  public function testNonProVendorDeniedEvenWithPermission(): void {
    $subscription = $this->buildSubscriptionService([1002]);
    $account = $this->createUserWithProPermission(TRUE);
    $route = new Route('/vendor/events/{event}/analytics', [], [
      '_permission' => 'use pro financial analytics',
    ]);

    $route_access = $this->container->get('access_manager')->check($route, $account, NULL, TRUE);
    self::assertInstanceOf(AccessResultInterface::class, $route_access);
    self::assertTrue($route_access->isAllowed(), 'Route permission is satisfied.');

    $this->expectException(AccessDeniedHttpException::class);
    $subscription->requirePro(1001);
  }

  /**
   * Validates matrix: is_pro=1 + permission=no => denied.
   */
  public function testProVendorDeniedWithoutPermission(): void {
    $subscription = $this->buildSubscriptionService([2001]);
    $account = $this->createUserWithProPermission(FALSE);
    $route = new Route('/vendor/events/{event}/analytics', [], [
      '_permission' => 'use pro financial analytics',
    ]);

    $route_access = $this->container->get('access_manager')->check($route, $account, NULL, TRUE);
    self::assertInstanceOf(AccessResultInterface::class, $route_access);
    self::assertFalse($route_access->isAllowed(), 'Route permission blocks access.');
    self::assertTrue($subscription->isProVendor(2001), 'Vendor Pro state is true.');
  }

  /**
   * Validates matrix: is_pro=1 + permission=yes => allowed.
   */
  public function testProVendorAllowedWithPermission(): void {
    $subscription = $this->buildSubscriptionService([3001]);
    $account = $this->createUserWithProPermission(TRUE);
    $route = new Route('/vendor/events/{event}/analytics', [], [
      '_permission' => 'use pro financial analytics',
    ]);

    $route_access = $this->container->get('access_manager')->check($route, $account, NULL, TRUE);
    self::assertInstanceOf(AccessResultInterface::class, $route_access);
    self::assertTrue($route_access->isAllowed(), 'Route permission is satisfied.');

    $subscription->requirePro(3001);
    self::assertTrue($subscription->isProVendor(3001), 'Pro vendor passes service-level check.');
  }

}
