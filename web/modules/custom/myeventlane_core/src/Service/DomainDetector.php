<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
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

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private readonly RequestStack $requestStack;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private readonly ConfigFactoryInterface $configFactory;

  /**
   * Constructs a DomainDetector.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
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
   *
   * If vendor_domain is misconfigured to the same host as public_domain, that
   * shared host must be treated as public only; otherwise vendor-only
   * subscribers (e.g. console gate) run on the main site and fight with public
   * routing.
   */
  public function isVendorDomain(): bool {
    $public = $this->getConfiguredDomainHost('public_domain');
    $vendor = $this->getConfiguredDomainHost('vendor_domain');
    if ($public !== NULL && $vendor !== NULL && $public === $vendor) {
      return FALSE;
    }

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
   *   vendor|admin|public|current.
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
   * Builds a public-domain URL from an internal path.
   *
   * When the current request is on the vendor or admin domain and
   * public_domain config is available, returns an absolute URL on the
   * configured public host. Otherwise falls back to the relative path,
   * which keeps single-domain local environments (DDEV) working.
   *
   * @param string $internal_path
   *   A Drupal-generated relative path, e.g. /events/my-event.
   *
   * @return string
   *   Absolute public URL or the original relative path.
   */
  public function publicUrl(string $internal_path): string {
    $path = '/' . ltrim($internal_path, '/');

    if ($this->isPublicDomain()) {
      return $path;
    }

    try {
      return $this->buildDomainUrl($path, 'public');
    }
    catch (\Throwable) {
      return $path;
    }
  }

  /**
   * Builds an absolute public URL suitable for sharing, copy, and QR codes.
   *
   * Unlike publicUrl(), this never returns a site-relative path: scanners and
   * social/share paste destinations need a full http(s) URL.
   *
   * @param string $internal_path
   *   A Drupal-generated relative path, e.g. /events/my-event.
   *
   * @return string
   *   Absolute public URL (configured public host when available).
   */
  public function absolutePublicUrl(string $internal_path): string {
    $path = '/' . ltrim($internal_path, '/');
    $candidate = $this->publicUrl($path);

    if (preg_match('#^https?://#i', $candidate) === 1) {
      return $candidate;
    }

    $relative = '/' . ltrim($candidate, '/');
    try {
      return $this->buildDomainUrl($relative, 'public');
    }
    catch (\Throwable) {
      return Url::fromUserInput($relative)->setAbsolute()->toString();
    }
  }

  /**
   * Converts a Drupal Url object to one pointing at the public domain.
   *
   * Returns the original Url unchanged when already on the public domain
   * or when no domain configuration is available.
   *
   * @param \Drupal\Core\Url|null $url
   *   A Url object, or NULL.
   *
   * @return \Drupal\Core\Url|null
   *   A public-domain Url, or the original value.
   */
  public function publicUrlObject(?Url $url): ?Url {
    if (!$url instanceof Url) {
      return $url;
    }
    $path = $url->toString();
    $publicPath = $this->publicUrl($path);
    if ($publicPath !== $path) {
      return Url::fromUri($publicPath);
    }
    return $url;
  }

  /**
   * Hostnames to consider for domain matching.
   *
   * Behind reverse proxies, Request::getHost() follows X-Forwarded-Host. Some
   * stacks send the public apex for every vhost; the browser Host header at
   * the edge can still be vendor.* / admin.* and must be honoured so console
   * gates and redirects run on the correct host.
   *
   * @return list<string>
   */
  private function hostnameCandidatesForMatching(): array {
    $request = $this->getRequest();
    if ($request === NULL) {
      return [];
    }

    $out = [];
    $fromRequest = strtolower($request->getHost());
    if ($fromRequest !== '') {
      $out[] = $fromRequest;
    }

    $raw = $request->server->get('HTTP_HOST');
    if (is_string($raw) && $raw !== '') {
      $raw = strtolower((string) preg_replace('/:\d+$/', '', $raw));
      if ($raw !== '' && !in_array($raw, $out, TRUE)) {
        $out[] = $raw;
      }
    }

    return $out;
  }

  /**
   * Checks whether the current hostname matches a configured domain.
   */
  private function isMatchingConfiguredDomain(string $config_key, string $prefix): bool {
    $candidates = $this->hostnameCandidatesForMatching();
    if ($candidates === []) {
      return FALSE;
    }

    $configured = $this->getConfiguredDomainHost($config_key);
    if ($configured === NULL) {
      foreach ($candidates as $hostname) {
        if (str_starts_with($hostname, $prefix)) {
          return TRUE;
        }
      }
      return FALSE;
    }

    $configured = strtolower($configured);
    foreach ($candidates as $hostname) {
      if ($hostname === $configured) {
        return TRUE;
      }
    }

    return FALSE;
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
    return is_string($host) ? strtolower($host) : NULL;
  }

}
