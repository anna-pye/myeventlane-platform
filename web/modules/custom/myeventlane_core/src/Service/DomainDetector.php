<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Domain detection and domain-aware URL helper.
 *
 * IMPORTANT:
 * - This service MUST NOT be used for authentication or access control.
 * - It is intended ONLY for UI logic and redirect helpers.
 * - Session handling is controlled by Drupal settings, not this service.
 */
final class DomainDetector {

  private readonly RequestStack $requestStack;
  private readonly ConfigFactoryInterface $configFactory;

  public function __construct(
    RequestStack $request_stack,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->requestStack = $request_stack;
    $this->configFactory = $config_factory;
  }

  /**
   * Returns the current Symfony request.
   */
  private function getRequest(): ?Request {
    return $this->requestStack->getCurrentRequest();
  }

  /**
   * Gets the current hostname.
   *
   * @return string|null
   *   Hostname only (no scheme, no port), or NULL if unavailable.
   */
  public function getCurrentHostname(): ?string {
    $request = $this->getRequest();
    return $request ? $request->getHost() : NULL;
  }

  /**
   * Determines whether the current request is on the vendor domain.
   */
  public function isVendorDomain(): bool {
    return $this->isMatchingConfiguredDomain('vendor_domain', 'vendor.');
  }

  /**
   * Determines whether the current request is on the admin domain.
   */
  public function isAdminDomain(): bool {
    return $this->isMatchingConfiguredDomain('admin_domain', 'admin.');
  }

  /**
   * Determines whether the current request is on the public domain.
   */
  public function isPublicDomain(): bool {
    return !$this->isVendorDomain() && !$this->isAdminDomain();
  }

  /**
   * Returns the current domain type.
   *
   * @return string
   *   One of: vendor, admin, public.
   */
  public function getCurrentDomainType(): string {
    if (PHP_SAPI === 'cli') {
      return 'public';
    }

    if ($this->isVendorDomain()) {
      return 'vendor';
    }

    if ($this->isAdminDomain()) {
      return 'admin';
    }

    return 'public';
  }

  /**
   * Builds a fully-qualified URL for a given domain type.
   *
   * @param string $path
   *   Internal path, with or without leading slash.
   * @param string $domain_type
   *   vendor|admin|public|current
   *
   * @throws \RuntimeException
   *   When required domain configuration is missing.
   */
  public function buildDomainUrl(string $path, string $domain_type = 'current'): string {
    if ($domain_type === 'current') {
      $domain_type = $this->getCurrentDomainType();
    }

    $base_url = match ($domain_type) {
      'vendor' => $this->getConfiguredDomainUrl('vendor_domain'),
      'admin' => $this->getConfiguredDomainUrl('admin_domain'),
      'public' => $this->getConfiguredDomainUrl('public_domain'),
      default => throw new \InvalidArgumentException(sprintf('Unknown domain type "%s".', $domain_type)),
    };

    $path = '/' . ltrim($path, '/');

    return $base_url . $path;
  }

  /**
   * Checks whether the current hostname matches a configured domain.
   */
  private function isMatchingConfiguredDomain(string $config_key, string $prefix): bool {
    $hostname = $this->getCurrentHostname();
    if ($hostname === NULL) {
      return FALSE;
    }

    $configured = $this->getConfiguredDomainHost($config_key);
    if ($configured === NULL) {
      return str_starts_with($hostname, $prefix);
    }

    return $hostname === $configured;
  }

  /**
   * Returns a configured domain URL (scheme + host).
   *
   * @throws \RuntimeException
   *   If configuration is missing or invalid.
   */
  private function getConfiguredDomainUrl(string $config_key): string {
    $config = $this->configFactory->get('myeventlane_core.domain_settings');
    $value = (string) $config->get($config_key);

    if ($value === '') {
      throw new \RuntimeException(sprintf(
        'Missing required domain configuration: myeventlane_core.domain_settings.%s',
        $config_key
      ));
    }

    $parsed = parse_url($value);
    if (!isset($parsed['scheme'], $parsed['host'])) {
      throw new \RuntimeException(sprintf(
        'Invalid domain configuration for %s: %s',
        $config_key,
        $value
      ));
    }

    return $parsed['scheme'] . '://' . $parsed['host'];
  }

  /**
   * Returns the configured domain host only.
   */
  private function getConfiguredDomainHost(string $config_key): ?string {
    $config = $this->configFactory->get('myeventlane_core.domain_settings');
    $value = (string) $config->get($config_key);

    if ($value === '') {
      return NULL;
    }

    $host = parse_url($value, PHP_URL_HOST);
    return is_string($host) ? $host : NULL;
  }

}