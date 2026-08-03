<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_wallet\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Site\Settings;
use Drupal\myeventlane_wallet\Service\WalletActionBuilder;
use Drupal\myeventlane_wallet\Service\WalletPresentationGate;
use Drupal\myeventlane_wallet\Service\WalletSigner;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \Drupal\myeventlane_wallet\Service\WalletActionBuilder
 *
 * @group myeventlane_wallet
 */
final class WalletActionBuilderTest extends UnitTestCase {

  private string $tempDir = '';

  /**
   * Official wallet badge assets retain their required presentation contract.
   */
  public function testOfficialBadgePresentationContract(): void {
    $moduleRoot = DRUPAL_ROOT . '/modules/custom/myeventlane_wallet';
    $template = file_get_contents($moduleRoot . '/templates/wallet-buttons.html.twig');
    $css = file_get_contents($moduleRoot . '/css/wallet-badges.css');
    $googleSize = getimagesize($moduleRoot . '/assets/web/add-to-google-wallet.png');

    $this->assertIsString($template);
    $this->assertIsString($css);
    $this->assertSame([283, 50], [$googleSize[0], $googleSize[1]]);
    $this->assertStringContainsString('width="156"', $template);
    $this->assertStringContainsString('width="272"', $template);
    $this->assertSame(2, substr_count($template, 'height="48"'));
    $this->assertStringContainsString('margin: 8px;', $css);
    $this->assertStringNotContainsString('mel-wallet-badge__fallback', $template);
    $this->assertStringNotContainsString('mel-wallet-badge__fallback', $css);

    $passAssets = array_map('basename', glob($moduleRoot . '/assets/pass/*') ?: []);
    sort($passAssets);
    $this->assertSame([
      'icon.png',
      'icon@2x.png',
      'icon@3x.png',
      'logo.png',
      'logo@2x.png',
    ], $passAssets, 'Pass bundles must contain only reviewed Apple Wallet artwork.');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tempDir = sys_get_temp_dir() . '/mel_wallet_actions_' . uniqid('', TRUE);
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
   * @covers ::buildForOrderItem
   */
  public function testGateOffReturnsEmptyActions(): void {
    new Settings(['myeventlane_wallet' => []]);
    $builder = $this->builder([
      'apple_enabled' => TRUE,
      'google_enabled' => TRUE,
      'apple_team_id' => 'ABCDE12345',
      'apple_pass_type_id' => 'pass.com.example.mel',
      'google_issuer_id' => '3388000000000000000',
      'show_wallet_buttons' => FALSE,
      'show_wallet_in_email' => TRUE,
    ]);

    $this->assertSame(
      ['apple' => NULL, 'google' => NULL],
      $builder->buildForOrderItem(55, WalletActionBuilder::SURFACE_ACTIONS),
    );
  }

  /**
   * @covers ::buildForOrderItem
   */
  public function testActionsSurfaceEmitsCanonicalShape(): void {
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

    $builder = $this->builder([
      'apple_enabled' => TRUE,
      'google_enabled' => TRUE,
      'apple_team_id' => 'ABCDE12345',
      'apple_pass_type_id' => 'pass.com.example.mel',
      'google_issuer_id' => '3388000000000000000',
      'show_wallet_buttons' => TRUE,
      'show_wallet_in_email' => TRUE,
    ]);

    $actions = $builder->buildForOrderItem(55, WalletActionBuilder::SURFACE_ACTIONS);
    $this->assertSame('Add to Apple Wallet', $actions['apple']['label']);
    $this->assertSame('Add to Apple Wallet', $actions['apple']['aria_label']);
    $this->assertSame('myeventlane_wallet.apple', $actions['apple']['route']);
    $this->assertSame('/wallet/apple/55', $actions['apple']['url']);
    $this->assertSame('Add to Google Wallet', $actions['google']['label']);
    $this->assertSame('myeventlane_wallet.google', $actions['google']['route']);
    $this->assertSame('/wallet/google/55', $actions['google']['url']);
  }

  /**
   * Absolute mode omits relative wallet routes but keeps recoverable badge paths.
   *
   * Unit tests lack a full URL generator, so generation throws. Wallet route
   * CTAs must not emit host-relative `/wallet/...` fallbacks for email. Badge
   * assets keep their site-relative path so OrderConfirmationQueueBuilder can
   * rewrite them through walletEmailBadgeUrl()/buildPublicUrl().
   *
   * @covers ::buildForOrderItem
   */
  public function testAbsoluteModeOmitsRelativeRouteFallbacksButKeepsBadgePaths(): void {
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

    $builder = $this->builder([
      'apple_enabled' => TRUE,
      'google_enabled' => TRUE,
      'apple_team_id' => 'ABCDE12345',
      'apple_pass_type_id' => 'pass.com.example.mel',
      'google_issuer_id' => '3388000000000000000',
      'show_wallet_buttons' => TRUE,
      'show_wallet_in_email' => TRUE,
    ]);

    $this->assertFileExists(
      DRUPAL_ROOT . '/modules/custom/myeventlane_wallet/assets/web/add-to-apple-wallet.svg',
    );
    $this->assertFileExists(
      DRUPAL_ROOT . '/modules/custom/myeventlane_wallet/assets/web/add-to-google-wallet.png',
    );

    $actions = $builder->buildForOrderItem(55, WalletActionBuilder::SURFACE_EMAIL, TRUE);
    $this->assertSame('', $actions['apple']['url']);
    $this->assertSame('', $actions['google']['url']);
    $this->assertSame(
      '/modules/custom/myeventlane_wallet/assets/web/add-to-apple-wallet.svg',
      $actions['apple']['badge']['src'],
    );
    $this->assertSame(
      '/modules/custom/myeventlane_wallet/assets/web/add-to-google-wallet.png',
      $actions['google']['badge']['src'],
    );
  }

  /**
   * @covers ::buildForOrderItem
   */
  public function testEmailSurfaceRespectsEmailFlag(): void {
    $this->writeAppleCredentials();
    new Settings([
      'myeventlane_wallet' => [
        'apple_certificate_path' => $this->tempDir . '/pass_cert.pem',
        'apple_private_key_path' => $this->tempDir . '/pass_key.pem',
        'apple_wwdr_certificate_path' => $this->tempDir . '/wwdr.pem',
      ],
    ]);

    $builder = $this->builder([
      'apple_enabled' => TRUE,
      'google_enabled' => FALSE,
      'apple_team_id' => 'ABCDE12345',
      'apple_pass_type_id' => 'pass.com.example.mel',
      'show_wallet_buttons' => TRUE,
      'show_wallet_in_email' => FALSE,
    ]);

    $this->assertSame(
      ['apple' => NULL, 'google' => NULL],
      $builder->buildForOrderItem(55, WalletActionBuilder::SURFACE_EMAIL),
    );
  }

  /**
   * @param array<string, mixed> $values
   */
  private function builder(array $values): WalletActionBuilder {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn (string $key): mixed => $values[$key] ?? NULL,
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('myeventlane_wallet.settings')->willReturn($config);
    $signer = new WalletSigner($config_factory, new NullLogger());
    $gate = new WalletPresentationGate($config_factory, $signer);
    $modules = $this->createMock(ModuleExtensionList::class);
    $modules->method('getPath')->with('myeventlane_wallet')->willReturn('modules/custom/myeventlane_wallet');
    return new WalletActionBuilder($gate, $modules);
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
