<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_wallet\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\myeventlane_wallet\Service\WalletSigner;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \Drupal\myeventlane_wallet\Service\WalletSigner
 * @group myeventlane_wallet
 */
final class WalletSignerTest extends UnitTestCase {

  private string $tempDir = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tempDir = sys_get_temp_dir() . '/mel_wallet_signer_' . uniqid('', TRUE);
    mkdir($this->tempDir);
    $this->writeEphemeralAppleCredentials($this->tempDir);
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
   * @covers ::isReady
   * @covers ::sign
   */
  public function testSignsManifestWhenCredentialsPresent(): void {
    new Settings([
      'myeventlane_wallet' => [
        'apple_certificate_path' => $this->tempDir . '/pass_cert.pem',
        'apple_private_key_path' => $this->tempDir . '/pass_key.pem',
        'apple_wwdr_certificate_path' => $this->tempDir . '/wwdr.pem',
      ],
    ]);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['apple_team_id', 'ABCDE12345'],
      ['apple_pass_type_id', 'pass.com.example.mel.test'],
    ]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('myeventlane_wallet.settings')->willReturn($config);

    $signer = new WalletSigner($factory, new NullLogger());
    $this->assertTrue($signer->isReady());

    $manifest = $this->tempDir . '/manifest.json';
    file_put_contents($manifest, '{"pass.json":"abc"}');
    $signature = $signer->sign($manifest);
    $this->assertNotSame('', $signature);
    $this->assertGreaterThan(64, strlen($signature));
  }

  /**
   * @covers ::isReady
   */
  public function testNotReadyWithoutCredentialPaths(): void {
    new Settings(['myeventlane_wallet' => []]);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['apple_team_id', 'ABCDE12345'],
      ['apple_pass_type_id', 'pass.com.example.mel.test'],
    ]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('myeventlane_wallet.settings')->willReturn($config);

    $signer = new WalletSigner($factory, new NullLogger());
    $this->assertFalse($signer->isReady());
  }

  private function writeEphemeralAppleCredentials(string $dir): void {
    $config = [
      'digest_alg' => 'sha256',
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];
    $passKey = openssl_pkey_new($config);
    $wwdrKey = openssl_pkey_new($config);
    $this->assertNotFalse($passKey);
    $this->assertNotFalse($wwdrKey);

    $passCsr = openssl_csr_new(['CN' => 'Pass Type ID: pass.com.example.mel.test'], $passKey, $config);
    $wwdrCsr = openssl_csr_new(['CN' => 'Apple WWDR Test CA'], $wwdrKey, $config);
    $wwdrCert = openssl_csr_sign($wwdrCsr, NULL, $wwdrKey, 3650, $config, 1);
    $passCert = openssl_csr_sign($passCsr, $wwdrCert, $wwdrKey, 3650, $config, 2);
    openssl_x509_export($passCert, $passPem);
    openssl_x509_export($wwdrCert, $wwdrPem);
    openssl_pkey_export($passKey, $keyPem);
    file_put_contents($dir . '/pass_cert.pem', $passPem);
    file_put_contents($dir . '/pass_key.pem', $keyPem);
    file_put_contents($dir . '/wwdr.pem', $wwdrPem);
  }

}
