<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_analytics\Kernel\Phase7;

use Drupal\commerce_store\Entity\Store;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_analytics\Phase7\Guard\AnalyticsQueryGuard;
use Drupal\myeventlane_analytics\Phase7\Guard\AnalyticsQueryGuardInterface;
use Drupal\myeventlane_analytics\Phase7\Scope\AnalyticsScopeResolver;
use Drupal\myeventlane_analytics\Phase7\Scope\AnalyticsScopeResolverInterface;
use Drupal\myeventlane_analytics\Phase7\Service\AnalyticsQueryService;
use Drupal\myeventlane_analytics\Phase7\Service\AnalyticsQueryServiceInterface;
use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Kernel base for Phase 7 analytics guardrail tests.
 *
 * This harness validates strict, fail-closed behaviour BEFORE any metric
 * queries exist.
 *
 * @group myeventlane_analytics
 */
abstract class AnalyticsKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Minimal runtime modules only (plus required core services).
   */
  protected static $modules = [
    // Core.
    'system',
    'user',
    'field',
    'node',
    'options',
    'path',
    'path_alias',
    // Contrib.
    'address',
    // Commerce store (data source for vendor scoping).
    'commerce',
    'commerce_price',
    'commerce_store',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Ensure Phase7 classes are loadable even though we do not enable the full
    // myeventlane_analytics module (to keep dependencies minimal).
    $this->requirePhase7Classes();

    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('commerce_store');
    $this->ensureOnlineStoreType();
  }

  /**
   * Ensures the Commerce store type 'online' exists for test fixtures.
   */
  private function ensureOnlineStoreType(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_store_type');
    if ($storage->load('online')) {
      return;
    }

    $storage->create([
      'id' => 'online',
      'label' => 'Online',
    ])->save();
  }

  /**
   * Creates a user account.
   *
   * Note: First created user will typically be UID 1, which our scope resolver
   * treats as an admin override.
   *
   * @param string $name
   *   Username.
   *
   * @return \Drupal\user\UserInterface
   *   The saved user.
   */
  protected function createUserAccount(string $name): UserInterface {
    $user = User::create([
      'name' => $name,
      'mail' => $name . '@example.test',
      'status' => 1,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Creates a Commerce store of type "online" owned by the given user.
   *
   * @param \Drupal\user\UserInterface $owner
   *   Store owner.
   * @param string $name
   *   Store name.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface
   *   The saved store.
   */
  protected function createOnlineStore(UserInterface $owner, string $name): StoreInterface {
    $store = Store::create([
      'type' => 'online',
      'name' => $name,
      'mail' => 'store-' . $name . '@example.test',
      'default_currency' => 'AUD',
      'uid' => $owner->id(),
      'status' => 1,
      'is_default' => FALSE,
    ]);
    $store->save();
    return $store;
  }

  /**
   * Sets the current user for the kernel container.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   */
  protected function switchToUser(UserInterface $account): void {
    $this->container->get('current_user')->setAccount($account);
  }

  /**
   * Creates a Phase 7 scope resolver.
   */
  protected function createScopeResolver(): AnalyticsScopeResolverInterface {
    return new AnalyticsScopeResolver(
      $this->container->get('current_user'),
      $this->container->get('entity_type.manager'),
    );
  }

  /**
   * Creates a Phase 7 guard (the only component that logs violations).
   */
  protected function createGuard(): AnalyticsQueryGuardInterface {
    $logger = $this->container->get('logger.factory')->get('myeventlane_analytics');
    return new AnalyticsQueryGuard($logger);
  }

  /**
   * Creates the Phase 7 query service shell.
   */
  protected function createQueryService(): AnalyticsQueryServiceInterface {
    return new AnalyticsQueryService(
      $this->createScopeResolver(),
      $this->createGuard(),
    );
  }

  /**
   * Convenience builder for an AnalyticsQuery.
   *
   * @param string $scope
   *   One of \Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery::SCOPE_*.
   * @param list<int> $store_ids
   *   Requested store IDs (admin scope only).
   * @param int|null $start_ts
   *   Range start timestamp.
   * @param int|null $end_ts
   *   Range end timestamp / point-in-time timestamp.
   * @param string|null $currency
   *   Currency (money metrics only).
   */
  protected function buildQuery(
    string $scope,
    array $store_ids = [],
    ?int $start_ts = 1,
    ?int $end_ts = 2,
    ?string $currency = 'AUD',
  ): AnalyticsQuery {
    return new AnalyticsQuery(
      scope: $scope,
      store_ids: $store_ids,
      start_ts: $start_ts,
      end_ts: $end_ts,
      currency: $currency,
    );
  }

  /**
   * Requires Phase 7 class files directly (minimal module load).
   */
  private function requirePhase7Classes(): void {
    $module_root = dirname(__DIR__, 4);

    // Value objects.
    require_once $module_root . '/src/Phase7/Value/AnalyticsQuery.php';

    // Exceptions.
    require_once $module_root . '/src/Phase7/Exception/AnalyticsException.php';
    require_once $module_root . '/src/Phase7/Exception/AccessDeniedAnalyticsException.php';
    require_once $module_root . '/src/Phase7/Exception/InvalidScopeException.php';
    require_once $module_root . '/src/Phase7/Exception/InvalidTimeWindowException.php';
    require_once $module_root . '/src/Phase7/Exception/MissingCurrencyException.php';
    require_once $module_root . '/src/Phase7/Exception/InvariantViolationException.php';

    // Interfaces + concrete classes under test.
    require_once $module_root . '/src/Phase7/Guard/AnalyticsQueryGuardInterface.php';
    require_once $module_root . '/src/Phase7/Scope/AnalyticsScopeResolverInterface.php';
    require_once $module_root . '/src/Phase7/Service/AnalyticsQueryServiceInterface.php';
    require_once $module_root . '/src/Phase7/Guard/AnalyticsQueryGuard.php';
    require_once $module_root . '/src/Phase7/Scope/AnalyticsScopeResolver.php';
    require_once $module_root . '/src/Phase7/Service/AnalyticsQueryService.php';
  }

}

