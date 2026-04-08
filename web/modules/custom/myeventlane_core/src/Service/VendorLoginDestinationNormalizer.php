<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Validates login ?destination= values for organiser (vendor host) flows.
 *
 * Used when a ticket-buyer session must log out before signing in as an organiser.
 */
final class VendorLoginDestinationNormalizer {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MelDestinationNormalizer $destinationNormalizer,
  ) {}

  /**
   * Returns a trusted absolute URL if the value targets organiser/vendor areas.
   */
  public function trustedOrganiserDestination(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '' || str_starts_with($raw, '//')) {
      return NULL;
    }

    if (preg_match('#^https?://#i', $raw)) {
      $normalized = $this->destinationNormalizer->validateTrustedAbsoluteUrl($raw, NULL);
      if ($normalized === NULL) {
        return NULL;
      }
      return $this->isOrganiserDestinationUrl($normalized) ? $normalized : NULL;
    }

    $pathOnly = str_contains($raw, '?') ? strstr($raw, '?', TRUE) : $raw;
    $pathOnly = '/' . ltrim((string) $pathOnly, '/');
    $absolute = $this->destinationNormalizer->absoluteFromInternalPathVendorPublicSplit($pathOnly);
    if ($absolute === NULL) {
      return NULL;
    }

    return $this->isOrganiserDestinationUrl($absolute) ? $absolute : NULL;
  }

  private function isOrganiserDestinationUrl(string $absoluteUrl): bool {
    $parts = parse_url($absoluteUrl);
    if ($parts === FALSE) {
      return FALSE;
    }
    $path = isset($parts['path']) ? '/' . trim((string) $parts['path'], '/') : '/';
    if ($path === '/create-event' || str_starts_with($path, '/vendor')) {
      return TRUE;
    }
    $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
    $vendorHost = $this->vendorConfiguredHost();
    return $vendorHost !== NULL && $host === $vendorHost;
  }

  private function vendorConfiguredHost(): ?string {
    $raw = trim((string) $this->configFactory->get('myeventlane_core.domain_settings')->get('vendor_domain'));
    if ($raw === '') {
      return NULL;
    }
    $h = parse_url($raw, PHP_URL_HOST);
    return is_string($h) && $h !== '' ? strtolower($h) : NULL;
  }

}
