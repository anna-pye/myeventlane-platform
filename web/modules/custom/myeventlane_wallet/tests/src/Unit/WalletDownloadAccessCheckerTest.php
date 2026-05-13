<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_wallet\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_wallet\Service\WalletDownloadAccessChecker;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @group myeventlane_wallet
 */
final class WalletDownloadAccessCheckerTest extends UnitTestCase {

  public function testLegacyPathAllowsGuestOrderForAnonymousUser(): void {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getCustomerId')->willReturn(0);

    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getOrder')->willReturn($order);
    $order_item->method('getCreatedTime')->willReturn(time());

    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(FALSE);
    $account->method('id')->willReturn('0');
    $account->method('hasPermission')->willReturn(FALSE);

    $this->checker()->assertAuthorized($order_item, NULL, $account);
  }

  public function testLegacyPathAllowsGuestOrderEmailMatch(): void {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getCustomerId')->willReturn(0);
    $order->method('getEmail')->willReturn('guest@example.test');

    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getOrder')->willReturn($order);
    $order_item->method('getCreatedTime')->willReturn(time());

    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('id')->willReturn('99');
    $account->method('getEmail')->willReturn('guest@example.test');
    $account->method('hasPermission')->willReturn(FALSE);

    $this->checker()->assertAuthorized($order_item, NULL, $account);
  }

  public function testLegacyPathDeniesMismatchedGuestEmail(): void {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getCustomerId')->willReturn(0);
    $order->method('getEmail')->willReturn('guest@example.test');

    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getOrder')->willReturn($order);
    $order_item->method('getCreatedTime')->willReturn(time());

    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('id')->willReturn('99');
    $account->method('getEmail')->willReturn('other@example.test');
    $account->method('hasPermission')->willReturn(FALSE);

    $this->expectException(AccessDeniedHttpException::class);
    $this->checker()->assertAuthorized($order_item, NULL, $account);
  }

  public function testPdfExpiryWindowMarksOldArtifactsExpired(): void {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')
      ->willReturnCallback(static function (string $key, mixed $default = NULL): mixed {
        if ($key === 'pdf_expiry_days') {
          return 1;
        }
        return $default;
      });

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('myeventlane_tickets.settings')
      ->willReturn($settings);

    $checker = new WalletDownloadAccessChecker($factory);
    $this->assertTrue($checker->isExpired(time() - 3 * 86400));
    $this->assertFalse($checker->isExpired(time()));
  }

  private function checker(int $pdf_expiry_days = 0): WalletDownloadAccessChecker {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')
      ->willReturnCallback(static function (string $key, mixed $default = NULL) use ($pdf_expiry_days): mixed {
        if ($key === 'pdf_expiry_days') {
          return $pdf_expiry_days;
        }
        return $default;
      });

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('myeventlane_tickets.settings')
      ->willReturn($settings);

    return new WalletDownloadAccessChecker($factory);
  }

}
