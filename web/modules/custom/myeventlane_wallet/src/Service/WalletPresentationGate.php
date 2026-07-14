<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use JsonException;

/**
 * Single source of truth for wallet availability and presentation gating.
 *
 * Wallet download routes and access checks remain unchanged; this service only
 * controls whether customer-facing actions and guidance prompts are emitted.
 *
 * Capability is diagnosed from configuration plus signer/JWT readiness — never
 * hard-coded environment-specific booleans.
 *
 * Google readiness is probed from settings here (not via GoogleWalletBuilder) so
 * the container avoids the UTVM → gate → Google → UTVM cycle. Probe rules must
 * stay aligned with GoogleWalletBuilder::isReady().
 */
final class WalletPresentationGate {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly WalletSigner $walletSigner,
  ) {}

  /**
   * Whether Apple Wallet is enabled, configured, and operational.
   */
  public function isAppleWalletAvailable(): bool {
    if (!$this->isAppleWalletEnabled()) {
      return FALSE;
    }
    return $this->isAppleWalletFunctional();
  }

  /**
   * Whether Google Wallet is enabled, configured, and operational.
   */
  public function isGoogleWalletAvailable(): bool {
    if (!$this->isGoogleWalletEnabled()) {
      return FALSE;
    }
    return $this->isGoogleWalletFunctional();
  }

  /**
   * Whether any wallet provider is available for customer presentation.
   */
  public function isWalletPresentationAvailable(): bool {
    return $this->isAppleWalletAvailable() || $this->isGoogleWalletAvailable();
  }

  /**
   * Whether Apple Wallet actions or links may be shown to customers.
   */
  public function isAppleWalletPresentable(): bool {
    return $this->isAppleWalletAvailable();
  }

  /**
   * Whether Google Wallet actions or links may be shown to customers.
   */
  public function isGoogleWalletPresentable(): bool {
    return $this->isGoogleWalletAvailable();
  }

  /**
   * Whether any wallet provider is enabled and operational.
   */
  public function anyWalletPresentable(): bool {
    return $this->isWalletPresentationAvailable();
  }

  /**
   * Whether ticket view models should include wallet action metadata.
   */
  public function shouldEmitWalletActions(): bool {
    if (!(bool) $this->settings()->get('show_wallet_buttons')) {
      return FALSE;
    }
    return $this->isWalletPresentationAvailable();
  }

  /**
   * Whether post-purchase wallet guidance prompts may be shown.
   */
  public function shouldEmitWalletPrompt(): bool {
    return $this->isWalletPresentationAvailable();
  }

  /**
   * Whether confirmation emails may include wallet links (Phase B consumer).
   */
  public function shouldEmitWalletInEmail(): bool {
    if (!(bool) $this->settings()->get('show_wallet_in_email')) {
      return FALSE;
    }
    return $this->isWalletPresentationAvailable();
  }

  /**
   * Whether Apple Wallet is enabled in settings.
   */
  private function isAppleWalletEnabled(): bool {
    return (bool) $this->settings()->get('apple_enabled');
  }

  /**
   * Whether Google Wallet is enabled in settings.
   */
  private function isGoogleWalletEnabled(): bool {
    return (bool) $this->settings()->get('google_enabled');
  }

  /**
   * Whether Apple pass generation can succeed with current configuration.
   *
   * Requires team ID, pass type ID, and WalletSigner credential initialisation
   * (certificate, private key, WWDR certificate paths).
   */
  private function isAppleWalletFunctional(): bool {
    $settings = $this->settings();
    if (trim((string) $settings->get('apple_team_id')) === '') {
      return FALSE;
    }
    if (trim((string) $settings->get('apple_pass_type_id')) === '') {
      return FALSE;
    }
    return $this->walletSigner->isReady();
  }

  /**
   * Whether Google Wallet save JWT generation can succeed.
   *
   * Requires issuer ID, a loadable service account, and a successful RS256
   * probe signature. Aligned with GoogleWalletBuilder::isReady() (no builder
   * injection — avoids UTVM → gate → Google → UTVM cycle).
   */
  private function isGoogleWalletFunctional(): bool {
    $issuer_id = trim((string) $this->settings()->get('google_issuer_id'));
    if ($issuer_id === '' || str_starts_with($issuer_id, 'GOCSPX-') || str_contains($issuer_id, ' ')) {
      return FALSE;
    }

    $wallet = Settings::get('myeventlane_wallet', []);
    if (!is_array($wallet)) {
      return FALSE;
    }
    $path = trim((string) ($wallet['google_service_account_json_path'] ?? ''));
    if ($path === '' || !is_file($path) || !is_readable($path)) {
      return FALSE;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
      return FALSE;
    }
    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (JsonException) {
      return FALSE;
    }
    $email = trim((string) ($decoded['client_email'] ?? ''));
    $key = (string) ($decoded['private_key'] ?? '');
    if ($email === '' || $key === '' || ($decoded['type'] ?? '') !== 'service_account') {
      return FALSE;
    }
    $private_key = @openssl_pkey_get_private($key);
    if ($private_key === FALSE) {
      return FALSE;
    }
    // Capability: JWT signing must succeed (brief requirement), not only key load.
    $probe = '';
    return @openssl_sign('mel.wallet.gate.probe', $probe, $private_key, OPENSSL_ALGO_SHA256) === TRUE
      && $probe !== '';
  }

  /**
   * Loaded wallet settings config.
   */
  private function settings(): ImmutableConfig {
    return $this->configFactory->get('myeventlane_wallet.settings');
  }

}
