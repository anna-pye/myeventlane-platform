<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Service;

use Drupal\myeventlane_venue\Exception\UnsafeRemoteUrlException;

/**
 * Validates public HTTPS URLs and resolves them to safe public IPv4 targets.
 */
final class PublicRemoteUrlGuard {

  public function __construct(
    private readonly ?\Closure $resolver = NULL,
  ) {}

  /**
   * Validates a URL and returns a normalized URL plus its resolved addresses.
   *
   * @return array{url: string, host: string, ips: string[]}
   *   The normalized URL, domain and validated IPv4 addresses.
   */
  public function validate(string $url): array {
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048) {
      throw new UnsafeRemoteUrlException('The website URL is missing or too long.');
    }

    $parts = parse_url($url);
    if (!is_array($parts)
      || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
      || isset($parts['user'])
      || isset($parts['pass'])) {
      throw new UnsafeRemoteUrlException('Only public HTTPS website URLs are supported.');
    }

    $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
    $port = isset($parts['port']) ? (int) $parts['port'] : 443;
    if ($host === '' || $port !== 443 || filter_var($host, FILTER_VALIDATE_IP)) {
      throw new UnsafeRemoteUrlException('The website must use a public domain over HTTPS.');
    }
    if (!str_contains($host, '.') || $host === 'localhost' || str_ends_with($host, '.localhost')) {
      throw new UnsafeRemoteUrlException('The website must use a public domain.');
    }

    $ips = $this->resolver !== NULL
      ? ($this->resolver)($host)
      : gethostbynamel($host);
    if (!is_array($ips) || $ips === []) {
      throw new UnsafeRemoteUrlException('The website domain could not be resolved.');
    }
    $ips = array_values(array_unique($ips));
    foreach ($ips as $ip) {
      if (!$this->isPublicIp($ip)) {
        throw new UnsafeRemoteUrlException('The website resolves to a private or reserved address.');
      }
    }

    return [
      'url' => $url,
      'host' => $host,
      'ips' => $ips,
    ];
  }

  /**
   * Returns TRUE only for globally routable IP addresses.
   */
  public function isPublicIp(string $ip): bool {
    if (filter_var(
      $ip,
      FILTER_VALIDATE_IP,
      FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
    ) === FALSE) {
      return FALSE;
    }

    // PHP's reserved-range flag does not consistently cover documentation,
    // benchmarking and carrier-grade NAT ranges across supported versions.
    $blocked = [
      '0.0.0.0/8',
      '100.64.0.0/10',
      '192.0.0.0/24',
      '192.0.2.0/24',
      '192.88.99.0/24',
      '198.18.0.0/15',
      '198.51.100.0/24',
      '203.0.113.0/24',
      '224.0.0.0/4',
      '240.0.0.0/4',
    ];
    foreach ($blocked as $range) {
      [$network, $prefix] = explode('/', $range, 2);
      $mask = (0xFFFFFFFF << (32 - (int) $prefix)) & 0xFFFFFFFF;
      if (((int) ip2long($ip) & $mask) === ((int) ip2long($network) & $mask)) {
        return FALSE;
      }
    }

    return TRUE;
  }

}
