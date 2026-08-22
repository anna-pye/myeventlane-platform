<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_pro\Service\ProActiveResolver;
use Drupal\myeventlane_pro\Service\ProBillingSchedule;
use Drupal\myeventlane_pro\Service\ProEntitlementManager;
use Drupal\myeventlane_pro\Service\ProSubscriptionStateResolver;
use Drupal\myeventlane_pro\Service\ProSubscriptionStatusService;
use Drupal\myeventlane_vendor\Service\VendorSubscriptionService;
use Drupal\user\UserInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests organiser Pro status without subscription history.
 *
 * @group myeventlane_pro
 */
final class ProSubscriptionStatusServiceTest extends TestCase {

  /**
   * An authenticated organiser without a subscription is inactive.
   */
  public function testUserWithoutSubscriptionReturnsInactiveStatus(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('commerce_subscription')
      ->willReturn($storage);
    $entityTypeManager->method('hasDefinition')
      ->with('myeventlane_vendor')
      ->willReturn(FALSE);

    $stateResolver = new ProSubscriptionStateResolver(new NullLogger());
    $vendorSubscription = new VendorSubscriptionService(
      $entityTypeManager,
      $this->createMock(ModuleHandlerInterface::class),
      new NullLogger(),
    );
    $activeResolver = new ProActiveResolver(
      $stateResolver,
      $entityTypeManager,
      $vendorSubscription,
      new ProEntitlementManager(),
      new NullLogger(),
    );

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('myeventlane_pro.settings')
      ->willReturn($config);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(2_000_000_000);

    $service = new ProSubscriptionStatusService(
      $entityTypeManager,
      $stateResolver,
      $activeResolver,
      $configFactory,
      $this->createMock(AccountProxyInterface::class),
      $time,
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(TranslationInterface::class),
    );

    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(66);
    $user->method('isAnonymous')->willReturn(FALSE);
    $user->method('getRoles')->willReturn([]);
    $user->method('hasField')->willReturn(FALSE);

    $status = $service->getStatusForUser($user);

    self::assertFalse($status['is_pro']);
    self::assertFalse($status['has_active_subscription']);
    self::assertSame('inactive', $status['state']);
    self::assertSame(ProBillingSchedule::TRIAL, $status['billing_schedule']);
  }

}
