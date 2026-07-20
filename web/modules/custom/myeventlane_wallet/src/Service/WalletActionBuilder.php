<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Service;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Url;

/**
 * Canonical builder for Apple / Google Wallet customer CTAs.
 *
 * All post-purchase surfaces (Digital Pass, booking confirmation, confirmation
 * email) must consume this service — do not duplicate route/label/badge logic.
 *
 * Signing, PKCS#7, certificate loading, and Google JWT generation are owned
 * elsewhere and must not be changed from presentation code.
 */
final class WalletActionBuilder {

  public const SURFACE_ACTIONS = 'actions';

  public const SURFACE_EMAIL = 'email';

  public function __construct(
    private readonly WalletPresentationGate $presentationGate,
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {}

  /**
   * Builds gated wallet actions for an order item.
   *
   * @param int $order_item_id
   *   Commerce order item ID used by wallet routes.
   * @param string $surface
   *   self::SURFACE_ACTIONS (Digital Pass / confirmation) or
   *   self::SURFACE_EMAIL (confirmation email).
   * @param bool $absolute
   *   When TRUE, action URLs and badge asset URLs are absolute.
   *
   * @return array{
   *   apple: array<string, mixed>|null,
   *   google: array<string, mixed>|null
   * }
   */
  public function buildForOrderItem(
    int $order_item_id,
    string $surface = self::SURFACE_ACTIONS,
    bool $absolute = FALSE,
  ): array {
    $empty = [
      'apple' => NULL,
      'google' => NULL,
    ];
    if ($order_item_id < 1) {
      return $empty;
    }

    $emit = $surface === self::SURFACE_EMAIL
      ? $this->presentationGate->shouldEmitWalletInEmail()
      : $this->presentationGate->shouldEmitWalletActions();
    if (!$emit) {
      return $empty;
    }

    $actions = $empty;
    if ($this->presentationGate->isAppleWalletPresentable()) {
      $actions['apple'] = $this->buildProviderAction(
        'apple',
        'Add to Apple Wallet',
        'myeventlane_wallet.apple',
        $order_item_id,
        '/wallet/apple/' . $order_item_id,
        $absolute,
        'add-to-apple-wallet.svg',
      );
    }
    if ($this->presentationGate->isGoogleWalletPresentable()) {
      // PNG preferred in email clients that struggle with SVG.
      $badge = $surface === self::SURFACE_EMAIL
        ? 'add-to-google-wallet.png'
        : 'add-to-google-wallet.svg';
      $actions['google'] = $this->buildProviderAction(
        'google',
        'Add to Google Wallet',
        'myeventlane_wallet.google',
        $order_item_id,
        '/wallet/google/' . $order_item_id,
        $absolute,
        $badge,
      );
    }
    return $actions;
  }

  /**
   * Relative module path to a web badge asset when the file exists.
   */
  public function badgeRelativePath(string $filename): ?string {
    $relative = $this->moduleExtensionList->getPath('myeventlane_wallet') . '/assets/web/' . $filename;
    $absolute = DRUPAL_ROOT . '/' . $relative;
    return is_file($absolute) ? $relative : NULL;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildProviderAction(
    string $provider,
    string $label,
    string $route_name,
    int $order_item_id,
    string $fallback_path,
    bool $absolute,
    string $badge_filename,
  ): array {
    $badge_src = $this->resolveBadgeUrl($badge_filename, $absolute);
    // Fall back to SVG if a preferred PNG is missing.
    if ($badge_src === NULL && str_ends_with($badge_filename, '.png')) {
      $badge_src = $this->resolveBadgeUrl(preg_replace('/\.png$/', '.svg', $badge_filename) ?: $badge_filename, $absolute);
    }

    return [
      'provider' => $provider,
      'label' => $label,
      'aria_label' => $label,
      'route' => $route_name,
      'url' => $this->routeUrl($route_name, ['order_item_id' => $order_item_id], $fallback_path, $absolute),
      'badge' => $badge_src !== NULL ? [
        'src' => $badge_src,
        'alt' => $label,
        'filename' => $badge_filename,
      ] : NULL,
    ];
  }

  /**
   * @param array<string, int> $parameters
   */
  private function routeUrl(string $route_name, array $parameters, string $fallback, bool $absolute): string {
    try {
      $url = Url::fromRoute($route_name, $parameters, ['absolute' => $absolute]);
      if ($absolute) {
        return $url->toString(TRUE)->getGeneratedUrl();
      }
      return $url->toString();
    }
    catch (\Throwable) {
      // Site-relative wallet routes are valid on rendered pages but unusable in
      // email clients. Omit the CTA URL when an absolute URL was required;
      // OrderConfirmationQueueBuilder recovers via absoluteWalletUrl() instead.
      return $absolute ? '' : $fallback;
    }
  }

  private function resolveBadgeUrl(string $filename, bool $absolute): ?string {
    $relative = $this->badgeRelativePath($filename);
    if ($relative === NULL) {
      return NULL;
    }
    $relative_url = '/' . ltrim($relative, '/');
    try {
      $url = Url::fromUri('base:/' . ltrim($relative, '/'), ['absolute' => $absolute]);
      if ($absolute) {
        return $url->toString(TRUE)->getGeneratedUrl();
      }
      return $url->toString();
    }
    catch (\Throwable) {
      // Keep the site-relative asset path when absolute generation fails.
      // Confirmation email assembly rewrites this through
      // walletEmailBadgeUrl()/buildPublicUrl() onto the public domain. Returning
      // NULL here would drop branded Google Wallet images even when the file
      // exists. (Unlike wallet route CTAs, badge paths are recoverable.)
      return $relative_url;
    }
  }

}
