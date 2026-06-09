<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_wallet\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\myeventlane_wallet\Service\WalletPresentationGate;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_wallet\Service\WalletPresentationGate
 * @group myeventlane_wallet
 */
final class WalletPresentationGateTest extends UnitTestCase {

  /**
   * @covers ::isAppleWalletAvailable
   * @covers ::isGoogleWalletAvailable
   * @covers ::isWalletPresentationAvailable
   * @covers ::shouldEmitWalletActions
   * @covers ::shouldEmitWalletPrompt
   */
  public function testStubImplementationIsNotPresentableEvenWhenEnabled(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['apple_enabled', TRUE],
      ['google_enabled', TRUE],
      ['show_wallet_buttons', TRUE],
      ['show_wallet_in_email', TRUE],
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('myeventlane_wallet.settings')->willReturn($config);

    $gate = new WalletPresentationGate($config_factory);

    $this->assertFalse($gate->isAppleWalletAvailable());
    $this->assertFalse($gate->isGoogleWalletAvailable());
    $this->assertFalse($gate->isWalletPresentationAvailable());
    $this->assertFalse($gate->isAppleWalletPresentable());
    $this->assertFalse($gate->isGoogleWalletPresentable());
    $this->assertFalse($gate->anyWalletPresentable());
    $this->assertFalse($gate->shouldEmitWalletActions());
    $this->assertFalse($gate->shouldEmitWalletPrompt());
    $this->assertFalse($gate->shouldEmitWalletInEmail());
  }

}
