<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Centralises trusted-destination validation for multi-domain login redirects.
 *
 * Trusted hosts are MEL domain_settings hosts plus myeventlane_auth allowlist
 * when that module is enabled.
 */
final class MelDestinationNormalizer {

  private const LOG_DESTINATION_MAX = 512;

  public function __construct(
    private readonly DomainDetector $domainDetector,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly RequestStack $requestStack,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Hostnames allowed for absolute http(s) destinations.
   *
   * @return list<string>
   */
  public function getTrustedHosts(): array {
    $hosts = $this->melDomainHosts();
    foreach ($this->authAllowlistHosts() as $h) {
      $hosts[] = $h;
    }
    return array_values(array_unique($hosts));
  }

  /**
   * Returns the URL if scheme is http(s) and host is trusted; otherwise NULL.
   *
   * Logs safe diagnostics when the URL is rejected (malformed or untrusted host).
   */
  public function validateTrustedAbsoluteUrl(string $url, ?string $requestHost = NULL): ?string {
    if ($requestHost === NULL) {
      $req = $this->requestStack->getCurrentRequest();
      $requestHost = $req ? $req->getHost() : '';
    }

    $parts = parse_url($url);
    if ($parts === FALSE || empty($parts['scheme']) || empty($parts['host'])) {
      $this->logInvalidDestination($url, (string) $requestHost);
      return NULL;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
      $this->logInvalidDestination($url, (string) $requestHost);
      return NULL;
    }
    $host = strtolower((string) $parts['host']);
    if (!in_array($host, $this->getTrustedHosts(), TRUE)) {
      $this->logInvalidDestination($url, (string) $requestHost);
      return NULL;
    }
    return $url;
  }

  /**
   * Maps an internal path to an absolute URL using post-login routing rules.
   */
  public function absoluteFromInternalPathPostLogin(string $path): ?string {
    try {
      if (str_starts_with($path, '/vendor') || $path === '/vendor') {
        return $this->domainDetector->buildDomainUrl($path, 'vendor');
      }
      if (str_starts_with($path, '/admin')) {
        return $this->domainDetector->buildDomainUrl($path, 'admin');
      }
      return $this->domainDetector->buildDomainUrl($path, 'public');
    }
    catch (\Throwable $e) {
      $this->logger->error('Post-login path redirect failed for @path: @message', [
        '@path' => $path,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Maps an internal path to vendor or public domain (vendor vs public split).
   */
  public function absoluteFromInternalPathVendorPublicSplit(string $path): ?string {
    try {
      $domainType = (str_starts_with($path, '/vendor') || $path === '/create-event') ? 'vendor' : 'public';
      return $this->domainDetector->buildDomainUrl($path, $domainType);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Vendor login destination build failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * @return list<string>
   */
  private function melDomainHosts(): array {
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

  /**
   * Hostnames from myeventlane_auth.settings allowed_redirect_hosts.
   *
   * @return list<string>
   */
  private function authAllowlistHosts(): array {
    if (!$this->moduleHandler->moduleExists('myeventlane_auth')) {
      return [];
    }
    $hosts = $this->configFactory->get('myeventlane_auth.settings')->get('allowed_redirect_hosts') ?: [];
    if (!is_array($hosts)) {
      return [];
    }
    $out = [];
    foreach ($hosts as $line) {
      $h = $this->normalizeAuthHostnameLine((string) $line);
      if ($h !== NULL) {
        $out[] = $h;
      }
    }
    return $out;
  }

  private function normalizeAuthHostnameLine(string $line): ?string {
    $line = trim($line);
    if ($line === '') {
      return NULL;
    }
    if (str_contains($line, '://')) {
      $host = parse_url($line, PHP_URL_HOST);
      return is_string($host) && $host !== '' ? strtolower($host) : NULL;
    }
    $line = strtolower($line);
    $slash = strpos($line, '/');
    if ($slash !== FALSE) {
      $line = substr($line, 0, $slash);
    }
    return $line !== '' ? $line : NULL;
  }

  private function logInvalidDestination(string $url, string $requestHost): void {
    $safe = $this->truncateForLog($url);
    $this->logger->warning('Destination rejected (untrusted or malformed): @dest', [
      '@dest' => $safe,
      'reason_code' => 'invalid_destination',
      'destination' => $safe,
      'request_host' => $requestHost,
    ]);
  }

  private function truncateForLog(string $value): string {
    if (strlen($value) <= self::LOG_DESTINATION_MAX) {
      return $value;
    }
    return substr($value, 0, self::LOG_DESTINATION_MAX) . '…';
  }

}
