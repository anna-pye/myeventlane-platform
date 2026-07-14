<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_surface\MelCustomerContinuityPresenter;
use Drupal\myeventlane_wallet\Service\WalletPresentationGate;
use Drupal\myeventlane_wallet\Service\WalletSigner;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * ACE Phase 4.5 — booking confirmation wallet continuity ownership.
 *
 * @coversDefaultClass \Drupal\myeventlane_surface\MelCustomerContinuityPresenter
 *
 * @group myeventlane_surface
 */
final class MelCustomerContinuityPresenterWalletTest extends UnitTestCase {

  private string $tempDir = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tempDir = sys_get_temp_dir() . '/mel_continuity_wallet_' . uniqid('', TRUE);
    mkdir($this->tempDir);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (glob($this->tempDir . '/*') ?: [] as $file) {
      @unlink($file);
    }
    @rmdir($this->tempDir);
    parent::tearDown();
  }

  /**
   * Gate FALSE → no wallet URLs (parity with Digital Pass / email empty CTAs).
   *
   * @covers ::buildCheckoutCompletionPresentation
   */
  public function testGateOffEmitsNoWalletActions(): void {
    new Settings(['myeventlane_wallet' => []]);
    $gate = $this->gate([
      'apple_enabled' => TRUE,
      'google_enabled' => TRUE,
      'apple_team_id' => 'ABCDE12345',
      'apple_pass_type_id' => 'pass.com.example.mel',
      'google_issuer_id' => '3388000000000000000',
      'show_wallet_buttons' => TRUE,
      'show_wallet_in_email' => TRUE,
    ]);

    $presentation = $this->presenter($gate)->buildCheckoutCompletionPresentation(
      'buyer@example.test',
      '1001',
      42,
      1001,
      TRUE,
      NULL,
      [],
      '/events/1',
      TRUE,
      TRUE,
      55,
    );

    $this->assertFalse($gate->shouldEmitWalletActions());
    $this->assertSame(['apple' => NULL, 'google' => NULL], $presentation['wallet']);
  }

  /**
   * Authenticated + gate on → same structure / routes as UniversalTicketViewModelBuilder.
   *
   * @covers ::buildCheckoutCompletionPresentation
   */
  public function testGateOnEmitsCanonicalWalletActions(): void {
    $this->writeAppleCredentials();
    $this->writeServiceAccount();
    new Settings([
      'myeventlane_wallet' => [
        'apple_certificate_path' => $this->tempDir . '/pass_cert.pem',
        'apple_private_key_path' => $this->tempDir . '/pass_key.pem',
        'apple_wwdr_certificate_path' => $this->tempDir . '/wwdr.pem',
        'google_service_account_json_path' => $this->tempDir . '/sa.json',
      ],
    ]);

    $gate = $this->gate([
      'apple_enabled' => TRUE,
      'google_enabled' => TRUE,
      'apple_team_id' => 'ABCDE12345',
      'apple_pass_type_id' => 'pass.com.example.mel',
      'google_issuer_id' => '3388000000000000000',
      'show_wallet_buttons' => TRUE,
      'show_wallet_in_email' => TRUE,
    ]);

    $presentation = $this->presenter($gate)->buildCheckoutCompletionPresentation(
      'buyer@example.test',
      '1001',
      42,
      1001,
      TRUE,
      NULL,
      [],
      '/events/1',
      TRUE,
      TRUE,
      55,
    );

    $this->assertTrue($gate->shouldEmitWalletActions());
    $this->assertTrue($gate->isAppleWalletPresentable());
    $this->assertTrue($gate->isGoogleWalletPresentable());
    $this->assertSame('Add to Apple Wallet', $presentation['wallet']['apple']['label']);
    $this->assertSame('myeventlane_wallet.apple', $presentation['wallet']['apple']['route']);
    $this->assertSame('/wallet/apple/55', $presentation['wallet']['apple']['url']);
    $this->assertSame('Add to Google Wallet', $presentation['wallet']['google']['label']);
    $this->assertSame('myeventlane_wallet.google', $presentation['wallet']['google']['route']);
    $this->assertSame('/wallet/google/55', $presentation['wallet']['google']['url']);
  }

  /**
   * Guests must never receive wallet URLs even when the gate would emit.
   *
   * @covers ::buildCheckoutCompletionPresentation
   */
  public function testGuestNeverReceivesWalletUrls(): void {
    $this->writeAppleCredentials();
    $this->writeServiceAccount();
    new Settings([
      'myeventlane_wallet' => [
        'apple_certificate_path' => $this->tempDir . '/pass_cert.pem',
        'apple_private_key_path' => $this->tempDir . '/pass_key.pem',
        'apple_wwdr_certificate_path' => $this->tempDir . '/wwdr.pem',
        'google_service_account_json_path' => $this->tempDir . '/sa.json',
      ],
    ]);

    $gate = $this->gate([
      'apple_enabled' => TRUE,
      'google_enabled' => TRUE,
      'apple_team_id' => 'ABCDE12345',
      'apple_pass_type_id' => 'pass.com.example.mel',
      'google_issuer_id' => '3388000000000000000',
      'show_wallet_buttons' => TRUE,
      'show_wallet_in_email' => TRUE,
    ]);
    $this->assertTrue($gate->shouldEmitWalletActions());

    $presentation = $this->presenter($gate)->buildCheckoutCompletionPresentation(
      'guest@example.test',
      '1001',
      NULL,
      1001,
      TRUE,
      NULL,
      [],
      '/events/1',
      TRUE,
      FALSE,
      55,
    );

    $this->assertSame(['apple' => NULL, 'google' => NULL], $presentation['wallet']);
    $this->assertNull($presentation['primary_action']);
  }

  /**
   * Partial provider availability mirrors Digital Pass (Apple only).
   *
   * @covers ::buildCheckoutCompletionPresentation
   */
  public function testPartialProviderAvailability(): void {
    $this->writeAppleCredentials();
    new Settings([
      'myeventlane_wallet' => [
        'apple_certificate_path' => $this->tempDir . '/pass_cert.pem',
        'apple_private_key_path' => $this->tempDir . '/pass_key.pem',
        'apple_wwdr_certificate_path' => $this->tempDir . '/wwdr.pem',
      ],
    ]);

    $gate = $this->gate([
      'apple_enabled' => TRUE,
      'google_enabled' => TRUE,
      'apple_team_id' => 'ABCDE12345',
      'apple_pass_type_id' => 'pass.com.example.mel',
      'google_issuer_id' => '3388000000000000000',
      'show_wallet_buttons' => TRUE,
      'show_wallet_in_email' => TRUE,
    ]);

    $presentation = $this->presenter($gate)->buildCheckoutCompletionPresentation(
      'buyer@example.test',
      '1001',
      42,
      1001,
      TRUE,
      NULL,
      [],
      '/events/1',
      TRUE,
      TRUE,
      77,
    );

    $this->assertTrue($gate->isAppleWalletPresentable());
    $this->assertFalse($gate->isGoogleWalletPresentable());
    $this->assertSame('/wallet/apple/77', $presentation['wallet']['apple']['url']);
    $this->assertNull($presentation['wallet']['google']);
  }

  /**
   * show_wallet_buttons FALSE → Digital Pass / confirmation emit nothing (email uses separate flag).
   *
   * @covers ::buildCheckoutCompletionPresentation
   */
  public function testShowWalletButtonsOffEmitsNothingOnConfirmation(): void {
    $this->writeAppleCredentials();
    $this->writeServiceAccount();
    new Settings([
      'myeventlane_wallet' => [
        'apple_certificate_path' => $this->tempDir . '/pass_cert.pem',
        'apple_private_key_path' => $this->tempDir . '/pass_key.pem',
        'apple_wwdr_certificate_path' => $this->tempDir . '/wwdr.pem',
        'google_service_account_json_path' => $this->tempDir . '/sa.json',
      ],
    ]);

    $gate = $this->gate([
      'apple_enabled' => TRUE,
      'google_enabled' => TRUE,
      'apple_team_id' => 'ABCDE12345',
      'apple_pass_type_id' => 'pass.com.example.mel',
      'google_issuer_id' => '3388000000000000000',
      'show_wallet_buttons' => FALSE,
      'show_wallet_in_email' => TRUE,
    ]);

    $this->assertFalse($gate->shouldEmitWalletActions());
    $this->assertTrue($gate->shouldEmitWalletInEmail());

    $presentation = $this->presenter($gate)->buildCheckoutCompletionPresentation(
      'buyer@example.test',
      '1001',
      42,
      1001,
      TRUE,
      NULL,
      [],
      '/events/1',
      TRUE,
      TRUE,
      55,
    );

    $this->assertSame(['apple' => NULL, 'google' => NULL], $presentation['wallet']);
  }

  private function presenter(WalletPresentationGate $gate): MelCustomerContinuityPresenter {
    return new MelCustomerContinuityPresenter(
      new MelReadinessHelper($this->getStringTranslationStub()),
      $gate,
    );
  }

  /**
   * @param array<string, mixed> $values
   */
  private function gate(array $values): WalletPresentationGate {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn (string $key): mixed => $values[$key] ?? NULL,
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('myeventlane_wallet.settings')->willReturn($config);
    $signer = new WalletSigner($config_factory, new NullLogger());
    return new WalletPresentationGate($config_factory, $signer);
  }

  private function writeAppleCredentials(): void {
    $config = [
      'digest_alg' => 'sha256',
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];
    $passKey = openssl_pkey_new($config);
    $wwdrKey = openssl_pkey_new($config);
    $this->assertNotFalse($passKey);
    $this->assertNotFalse($wwdrKey);
    $passCsr = openssl_csr_new(['CN' => 'Pass Type ID: pass.com.example.mel'], $passKey, $config);
    $wwdrCsr = openssl_csr_new(['CN' => 'Apple WWDR Test CA'], $wwdrKey, $config);
    $wwdrCert = openssl_csr_sign($wwdrCsr, NULL, $wwdrKey, 3650, $config, 1);
    $passCert = openssl_csr_sign($passCsr, $wwdrCert, $wwdrKey, 3650, $config, 2);
    openssl_x509_export($passCert, $passPem);
    openssl_x509_export($wwdrCert, $wwdrPem);
    openssl_pkey_export($passKey, $keyPem);
    file_put_contents($this->tempDir . '/pass_cert.pem', $passPem);
    file_put_contents($this->tempDir . '/pass_key.pem', $keyPem);
    file_put_contents($this->tempDir . '/wwdr.pem', $wwdrPem);
  }

  private function writeServiceAccount(): void {
    $config = [
      'digest_alg' => 'sha256',
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];
    $key = openssl_pkey_new($config);
    $this->assertNotFalse($key);
    openssl_pkey_export($key, $pem);
    file_put_contents($this->tempDir . '/sa.json', json_encode([
      'type' => 'service_account',
      'private_key' => $pem,
      'client_email' => 'wallet-test@mel-wallet-test.iam.gserviceaccount.com',
    ], JSON_THROW_ON_ERROR));
  }

}
