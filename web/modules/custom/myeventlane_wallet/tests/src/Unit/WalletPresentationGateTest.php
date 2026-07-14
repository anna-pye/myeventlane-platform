<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_wallet\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\myeventlane_wallet\Service\WalletPresentationGate;
use Drupal\myeventlane_wallet\Service\WalletSigner;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \Drupal\myeventlane_wallet\Service\WalletPresentationGate
 * @group myeventlane_wallet
 */
final class WalletPresentationGateTest extends UnitTestCase {

  private string $tempDir = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tempDir = sys_get_temp_dir() . '/mel_wallet_gate_' . uniqid('', TRUE);
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
   * @covers ::isAppleWalletAvailable
   * @covers ::isGoogleWalletAvailable
   */
  public function testUnavailableWhenCredentialsNotReady(): void {
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

    $this->assertFalse($gate->isAppleWalletAvailable());
    $this->assertFalse($gate->isGoogleWalletAvailable());
    $this->assertFalse($gate->shouldEmitWalletActions());
  }

  /**
   * @covers ::isAppleWalletAvailable
   * @covers ::isGoogleWalletAvailable
   */
  public function testPresentableWhenProviderCredentialsReady(): void {
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

    $this->assertTrue($gate->isAppleWalletAvailable());
    $this->assertTrue($gate->isGoogleWalletAvailable());
    $this->assertTrue($gate->shouldEmitWalletActions());
  }

  /**
   * @covers ::isAppleWalletAvailable
   * @covers ::isGoogleWalletAvailable
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

    $this->assertTrue($gate->isAppleWalletAvailable());
    $this->assertFalse($gate->isGoogleWalletAvailable());
    $this->assertTrue($gate->shouldEmitWalletActions());
  }

  /**
   * @covers ::isGoogleWalletAvailable
   */
  public function testRejectsOauthClientSecretIssuer(): void {
    $this->writeServiceAccount();
    new Settings([
      'myeventlane_wallet' => [
        'google_service_account_json_path' => $this->tempDir . '/sa.json',
      ],
    ]);
    $gate = $this->gate([
      'apple_enabled' => FALSE,
      'google_enabled' => TRUE,
      'apple_team_id' => '',
      'apple_pass_type_id' => '',
      'google_issuer_id' => 'GOCSPX-not-an-issuer',
      'show_wallet_buttons' => TRUE,
      'show_wallet_in_email' => TRUE,
    ]);
    $this->assertFalse($gate->isGoogleWalletAvailable());
  }

  /**
   * @param array<string, mixed> $values
   *   Config values keyed by setting name.
   */
  private function gate(array $values): WalletPresentationGate {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn(string $key): mixed => $values[$key] ?? NULL,
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('myeventlane_wallet.settings')->willReturn($config);

    // Real signer — final class cannot be mocked.
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
