<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;

/**
 * Single source of truth for wallet availability and presentation gating.
 *
 * Wallet download routes and access checks remain unchanged; this service only
 * controls whether customer-facing actions and guidance prompts are emitted.
 */
final class WalletPresentationGate {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
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
   * Whether Apple pass generation is implemented for production use.
   */
  private function isAppleWalletFunctional(): bool {
    // WalletSigner::sign() is a stub and PkPassBuilder emits scaffold JSON only.
    // @todo Return TRUE when signed .pkpass generation is implemented.
    return FALSE;
  }

  /**
   * Whether Google Wallet save link generation is implemented for production use.
   */
  private function isGoogleWalletFunctional(): bool {
    // GoogleWalletBuilder::generateSaveLink() returns a frozen placeholder URL.
    // @todo Return TRUE when JWT save link generation is implemented.
    return FALSE;
  }

  /**
   * Loaded wallet settings config.
   */
  private function settings(): ImmutableConfig {
    return $this->configFactory->get('myeventlane_wallet.settings');
  }

}
