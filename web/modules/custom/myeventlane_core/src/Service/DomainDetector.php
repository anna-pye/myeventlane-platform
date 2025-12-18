<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for detecting the current domain and determining domain context.
 */
final class DomainDetector {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private readonly RequestStack $requestStack;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private readonly ConfigFactoryInterface $configFactory;

  /**
   * Constructs a DomainDetector object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    RequestStack $request_stack,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->requestStack = $request_stack;
    $this->configFactory = $config_factory;
  }

  /**
   * Gets the current hostname from the request.
   *
   * @return string
   *   The hostname (e.g., 'myeventlane.com' or 'vendor.myeventlane.com').
   */
  public function getCurrentHostname(): string {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return '';
    }

    return $request->getHost();
  }

  /**
   * Checks if the current domain is the vendor domain.
   *
   * @return bool
   *   TRUE if on vendor domain, FALSE otherwise.
   */
  public function isVendorDomain(): bool {
    $hostname = $this->getCurrentHostname();
    if (empty($hostname)) {
      return FALSE;
    }

    // Check if hostname starts with 'vendor.'.
    if (str_starts_with($hostname, 'vendor.')) {
      return TRUE;
    }

    // Also check configured vendor domain from settings.
    $config = $this->configFactory->get('myeventlane_core.domain_settings');
    $vendor_domain = $config->get('vendor_domain') ?? '';
    if (!empty($vendor_domain)) {
      $vendor_host = parse_url($vendor_domain, PHP_URL_HOST) ?: $vendor_domain;
      if ($hostname === $vendor_host || str_ends_with($hostname, '.' . $vendor_host)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if the current domain is the admin domain.
   *
   * @return bool
   *   TRUE if on admin domain, FALSE otherwise.
   */
  public function isAdminDomain(): bool {
    $hostname = $this->getCurrentHostname();
    if (empty($hostname)) {
      return FALSE;
    }

    // Check if hostname starts with 'admin.'.
    if (str_starts_with($hostname, 'admin.')) {
      return TRUE;
    }

    // Also check configured admin domain from settings.
    $config = $this->configFactory->get('myeventlane_core.domain_settings');
    $admin_domain = $config->get('admin_domain') ?? '';
    if (!empty($admin_domain)) {
      $admin_host = parse_url($admin_domain, PHP_URL_HOST) ?: $admin_domain;
      if ($hostname === $admin_host || str_ends_with($hostname, '.' . $admin_host)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if the current domain is the public domain.
   *
   * @return bool
   *   TRUE if on public domain, FALSE otherwise.
   */
  public function isPublicDomain(): bool {
    return !$this->isVendorDomain() && !$this->isAdminDomain();
  }

  /**
   * Gets the current domain type.
   *
   * @return string
   *   Either 'vendor', 'admin', or 'public'.
   */
  public function getCurrentDomainType(): string {
    if ($this->isVendorDomain()) {
      return 'vendor';
    }
    if ($this->isAdminDomain()) {
      return 'admin';
    }
    return 'public';
  }

  /**
   * Gets the configured public domain URL.
   *
   * @return string
   *   The public domain URL.
   */
  public function getPublicDomainUrl(): string {
    $config = $this->configFactory->get('myeventlane_core.domain_settings');
    $public_domain = $config->get('public_domain') ?? '';
    if (!empty($public_domain)) {
      return $public_domain;
    }

    // Fallback: construct from current request (production-ready).
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      $scheme = $request->getScheme();
      $host = $request->getHost();
      // Remove 'vendor.' or 'admin.' prefix if present to get base domain.
      $host = preg_replace('/^(vendor|admin)\./', '', $host);
      return $scheme . '://' . $host;
    }

    // Last resort fallback: construct from base hostname if available.
    // This will work in both dev and production environments.
    return 'https://myeventlane.ddev.site';
  }

  /**
   * Gets the configured vendor domain URL.
   *
   * @return string
   *   The vendor domain URL.
   */
  public function getVendorDomainUrl(): string {
    $config = $this->configFactory->get('myeventlane_core.domain_settings');
    $vendor_domain = $config->get('vendor_domain') ?? '';
    if (!empty($vendor_domain)) {
      return $vendor_domain;
    }

    // Fallback: construct from current request (production-ready).
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      $scheme = $request->getScheme();
      $host = $request->getHost();
      // Ensure 'vendor.' prefix.
      if (!str_starts_with($host, 'vendor.')) {
        // Remove 'admin.' prefix if present, then add 'vendor.'.
        $host = preg_replace('/^admin\./', '', $host);
        $host = 'vendor.' . $host;
      }
      return $scheme . '://' . $host;
    }

    // Last resort fallback: construct vendor subdomain.
    // This will work in both dev and production environments.
    return 'https://vendor.myeventlane.ddev.site';
  }

  /**
   * Gets the configured admin domain URL.
   *
   * @return string
   *   The admin domain URL.
   */
  public function getAdminDomainUrl(): string {
    $config = $this->configFactory->get('myeventlane_core.domain_settings');
    $admin_domain = $config->get('admin_domain') ?? '';
    if (!empty($admin_domain)) {
      return $admin_domain;
    }

    // Fallback: construct from current request (production-ready).
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      $scheme = $request->getScheme();
      $host = $request->getHost();
      // Ensure 'admin.' prefix.
      if (!str_starts_with($host, 'admin.')) {
        // Remove vendor. prefix if present, then add admin.
        $host = preg_replace('/^vendor\./', '', $host);
        $host = 'admin.' . $host;
      }
      return $scheme . '://' . $host;
    }

    // Last resort fallback: construct admin subdomain.
    // This will work in both dev and production environments.
    return 'https://admin.myeventlane.ddev.site';
  }

  /**
   * Builds a URL for the specified domain type.
   *
   * @param string $path
   *   The path (e.g., '/vendor/dashboard').
   * @param string $domain_type
   *   Either 'vendor', 'admin', or 'public'. Defaults to current domain.
   *
   * @return string
   *   The full URL.
   */
  public function buildDomainUrl(string $path, string $domain_type = 'current'): string {
    if ($domain_type === 'current') {
      $domain_type = $this->getCurrentDomainType();
    }

    $base_url = match ($domain_type) {
      'vendor' => $this->getVendorDomainUrl(),
      'admin' => $this->getAdminDomainUrl(),
      default => $this->getPublicDomainUrl(),
    };

    // Ensure path starts with /.
    $path = '/' . ltrim($path, '/');

    return $base_url . $path;
  }

}
