<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Validates login ?destination= values for organiser (vendor host) flows.
 *
 * Used when a ticket-buyer session must log out before signing in as an organiser.
 */
final class VendorLoginDestinationNormalizer {

  public function __construct(
    private readonly DomainDetector $domainDetector,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
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
      $normalized = $this->trustAbsoluteUrl($raw);
      if ($normalized === NULL) {
        return NULL;
      }
      return $this->isOrganiserDestinationUrl($normalized) ? $normalized : NULL;
    }

    $pathOnly = str_contains($raw, '?') ? strstr($raw, '?', TRUE) : $raw;
    $pathOnly = '/' . ltrim((string) $pathOnly, '/');
    try {
      $domainType = (str_starts_with($pathOnly, '/vendor') || $pathOnly === '/create-event') ? 'vendor' : 'public';
      $absolute = $this->domainDetector->buildDomainUrl($pathOnly, $domainType);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Vendor login destination build failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    return $this->isOrganiserDestinationUrl($absolute) ? $absolute : NULL;
  }

  private function trustAbsoluteUrl(string $url): ?string {
    $parts = parse_url($url);
    if ($parts === FALSE || empty($parts['scheme']) || empty($parts['host'])) {
      return NULL;
    }
    $host = strtolower((string) $parts['host']);
    if (!in_array($host, $this->trustedHosts(), TRUE)) {
      $this->logger->warning('Rejected vendor login destination host @host', [
        '@host' => $host,
      ]);
      return NULL;
    }
    return $url;
  }

  /**
   * @return list<string>
   */
  private function trustedHosts(): array {
    $cfg = $this->configFactory->get('myeventlane_core.domain_settings');
    $hosts = [];
    foreach (['public_domain', 'vendor_domain', 'admin_domain'] as $key) {
      $raw = trim((string) $cfg->get($key));
      if ($raw === '') {
        continue;
      }
      $h = parse_url($raw, PHP_URL_HOST);
      if (is_string($h) && $h !== '') {
        $hosts[] = strtolower($h);
      }
    }
    return array_values(array_unique($hosts));
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
