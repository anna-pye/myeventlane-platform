<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

require_once dirname(__DIR__, 3) . '/src/Service/ProBillingSchedule.php';
require_once dirname(__DIR__, 3) . '/src/Service/ProProductResolver.php';
require_once dirname(__DIR__, 3) . '/src/Service/ProActivationReadinessChecker.php';

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\myeventlane_pro\Service\ProActivationReadinessChecker;
use Drupal\myeventlane_pro\Service\ProProductResolver;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_pro\Service\ProActivationReadinessChecker
 *
 * @group myeventlane_pro
 */
final class ProActivationReadinessCheckerTest extends UnitTestCase {

  /**
   * @covers ::check
   */
  public function testStagingReportPassesWithRuntimeRestrictedKeys(): void {
    $checker = $this->checker('test', 'rk_test_standard', 'rk_test_recurring');

    $report = $checker->check('staging');

    self::assertTrue($report['ready']);
    self::assertSame([], array_values(array_filter(
      $report['checks'],
      static fn (array $check): bool => $check['status'] === 'fail',
    )));
  }

  /**
   * @covers ::check
   */
  public function testProductionRejectsTestModeAndSharedUnrestrictedKey(): void {
    $checker = $this->checker('test', 'sk_test_shared', 'sk_test_shared');

    $report = $checker->check('production');
    $failed = array_column(array_filter(
      $report['checks'],
      static fn (array $check): bool => $check['status'] === 'fail',
    ), 'id');

    self::assertFalse($report['ready']);
    self::assertContains('gateway.mode', $failed);
    self::assertContains('gateway.stripe.least_privilege', $failed);
    self::assertContains('gateway.stripe_pe_recurring.least_privilege', $failed);
    self::assertContains('gateway.key_separation', $failed);
  }

  /**
   * @covers ::check
   */
  public function testProductionRejectsRestrictedTestKeysInLiveMode(): void {
    $checker = $this->checker('live', 'rk_test_standard', 'rk_test_recurring');

    $report = $checker->check('production');
    $failed = array_column(array_filter(
      $report['checks'],
      static fn (array $check): bool => $check['status'] === 'fail',
    ), 'id');

    self::assertFalse($report['ready']);
    self::assertContains('gateway.stripe.key_environment', $failed);
    self::assertContains('gateway.stripe.least_privilege', $failed);
    self::assertContains('gateway.stripe_pe_recurring.key_environment', $failed);
    self::assertContains('gateway.stripe_pe_recurring.least_privilege', $failed);
  }

  /**
   * @covers ::check
   */
  public function testProductionAcceptsSeparatedRestrictedLiveKeys(): void {
    $checker = $this->checker('live', 'rk_live_standard', 'rk_live_recurring');

    $report = $checker->check('production');

    self::assertTrue($report['ready']);
    self::assertSame([], array_values(array_filter(
      $report['checks'],
      static fn (array $check): bool => $check['status'] === 'fail',
    )));
  }

  /**
   * @covers ::check
   */
  public function testEnabledConnectGatewayRequiresThirdSeparatedKey(): void {
    $checker = $this->checker(
      'live',
      'rk_live_platform',
      'rk_live_pro',
      'rk_live_platform',
      TRUE,
    );

    $report = $checker->check('production');
    $failed = array_column(array_filter(
      $report['checks'],
      static fn (array $check): bool => $check['status'] === 'fail',
    ), 'id');

    self::assertFalse($report['ready']);
    self::assertContains('gateway.key_separation', $failed);
  }

  /**
   * Builds a readiness checker with controlled configuration and entities.
   */
  private function checker(
    string $mode,
    string $standardKey,
    string $recurringKey,
    string $connectKey = 'rk_test_connect',
    bool $directChargeEnabled = FALSE,
  ): ProActivationReadinessChecker {
    $configs = [
      'commerce_payment.commerce_payment_gateway.stripe' => [
        'status' => TRUE,
        'plugin' => 'stripe',
        'configuration' => [
          'mode' => $mode,
          'publishable_key' => 'pk_' . $mode . '_standard',
          'secret_key' => $standardKey,
          'webhook_signing_secret' => 'whsec_standard',
        ],
      ],
      'commerce_payment.commerce_payment_gateway.stripe_pe_recurring' => [
        'status' => TRUE,
        'plugin' => 'mel_pro_stripe_payment_element',
        'configuration' => [
          'mode' => $mode,
          'payment_method_usage' => 'off_session',
          'publishable_key' => 'pk_' . $mode . '_recurring',
          'secret_key' => $recurringKey,
          'webhook_signing_secret' => 'whsec_recurring',
        ],
      ],
      'commerce_payment.commerce_payment_gateway.stripe_connect' => [
        'status' => $directChargeEnabled,
        'plugin' => 'stripe_connect',
        'configuration' => [
          'mode' => $mode,
          'payment_method_usage' => 'on_session',
          'publishable_key' => 'pk_' . $mode . '_connect',
          'secret_key' => $connectKey,
          'webhook_signing_secret' => 'whsec_connect',
        ],
      ],
      'myeventlane_core.settings' => [
        'direct_charge_enabled' => $directChargeEnabled,
      ],
      'myeventlane_pro.settings' => [
        'pro_price' => 49,
        'pro_boost_days' => 7,
        'billing_portal_enabled' => TRUE,
        'pro_variation_sku' => '',
      ],
      'commerce_recurring.commerce_billing_schedule.mel_pro_monthly' => $this->schedule(TRUE),
      'commerce_recurring.commerce_billing_schedule.mel_pro_monthly_restart' => $this->schedule(FALSE),
      'commerce_tax.commerce_tax_type.australian_gst' => [
        'status' => TRUE,
        'configuration' => [
          'display_inclusive' => TRUE,
          'rates' => [['percentage' => '0.1']],
        ],
      ],
    ];
    foreach (['commerce_recurring', 'commerce_stripe_webhook_event', 'pro_boost_sync'] as $queue) {
      $configs['advancedqueue.advancedqueue_queue.' . $queue] = [
        'status' => TRUE,
        'processor' => 'cron',
      ];
    }

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(function (string $name) use ($configs): ImmutableConfig {
      $data = $configs[$name] ?? [];
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('isNew')->willReturn($data === []);
      $config->method('get')->willReturnCallback(static fn (string $key): mixed => $data[$key] ?? NULL);
      return $config;
    });

    $variationStorage = $this->createMock(EntityStorageInterface::class);
    $queryTrial = $this->query([1]);
    $queryRestart = $this->query([2]);
    $variationStorage->method('getQuery')->willReturnOnConsecutiveCalls($queryTrial, $queryRestart);
    $variationStorage->method('loadMultiple')->willReturnCallback(fn (array $ids): array => [
      reset($ids) => $this->variation((int) reset($ids)),
    ]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('commerce_product_variation')->willReturn($variationStorage);
    $productResolver = new ProProductResolver($entityTypeManager, $configFactory);

    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(TRUE);
    $database = $this->createMock(Connection::class);
    $database->method('schema')->willReturn($schema);

    $requestTime = 1_787_196_000;
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->with('system.cron_last', 0)->willReturn($requestTime - 60);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($requestTime);

    return new ProActivationReadinessChecker(
      $configFactory,
      new MemoryStorage(),
      new MemoryStorage(),
      $database,
      $state,
      $productResolver,
      $time,
    );
  }

  /**
   * Builds one billing schedule configuration fixture.
   *
   * @return array<string, mixed>
   *   Billing schedule configuration.
   */
  private function schedule(bool $trial): array {
    return [
      'status' => TRUE,
      'configuration' => [
        'trial_interval' => $trial ? ['number' => 30, 'unit' => 'day'] : [],
        'interval' => ['number' => 1, 'unit' => 'month'],
      ],
    ];
  }

  /**
   * Builds a variation query fixture.
   *
   * @param int[] $ids
   *   Variation entity IDs returned by the query.
   */
  private function query(array $ids): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn($ids);
    return $query;
  }

  /**
   * Builds a published Pro variation fixture.
   */
  private function variation(int $id): ProductVariationInterface {
    $product = $this->createMock(ProductInterface::class);
    $product->method('isPublished')->willReturn(TRUE);

    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('id')->willReturn((string) $id);
    $variation->method('bundle')->willReturn('mel_pro_subscription_variation');
    $variation->method('isPublished')->willReturn(TRUE);
    $variation->method('getProduct')->willReturn($product);
    $variation->method('getPrice')->willReturn(new Price('49.00', 'AUD'));
    return $variation;
  }

}
