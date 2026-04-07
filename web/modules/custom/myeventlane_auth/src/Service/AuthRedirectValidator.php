<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;

/**
 * Validates redirect URIs and CORS origins against configured allowlists.
 */
final class AuthRedirectValidator {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Gets merged allowed redirect hosts (config + optional core domain hosts).
   *
   * @return list<string>
   */
  public function getAllowedRedirectHosts(): array {
    $config = $this->configFactory->get('myeventlane_auth.settings');
    $hosts = $config->get('allowed_redirect_hosts') ?: [];
    if (!is_array($hosts)) {
      $hosts = [];
    }
    $hosts = array_values(array_filter(array_map('strtolower', array_map('trim', $hosts))));

    if ($this->moduleHandler->moduleExists('myeventlane_core')) {
      $domain = $this->configFactory->get('myeventlane_core.domain_settings');
      foreach (['public_domain', 'vendor_domain', 'admin_domain'] as $key) {
        $url = (string) $domain->get($key);
        $host = $url !== '' ? parse_url($url, PHP_URL_HOST) : NULL;
        if (is_string($host) && $host !== '') {
          $hosts[] = strtolower($host);
        }
      }
    }

    $auth = (string) $config->get('auth_base_url');
    if ($auth !== '') {
      $authHost = parse_url($auth, PHP_URL_HOST);
      if (is_string($authHost) && $authHost !== '') {
        $hosts[] = strtolower($authHost);
      }
    }

    return array_values(array_unique($hosts));
  }

  /**
   * Allowed Origin header values for browser CORS to /auth/*.
   *
   * @return list<string>
   */
  public function getAllowedCorsOrigins(): array {
    $config = $this->configFactory->get('myeventlane_auth.settings');
    $origins = $config->get('allowed_cors_origins') ?: [];
    if (!is_array($origins)) {
      $origins = [];
    }
    $out = [];
    foreach ($origins as $o) {
      $o = trim((string) $o);
      if ($o !== '') {
        $out[] = $o;
      }
    }
    foreach ($this->buildOriginsFromDomainSettings() as $o) {
      $out[] = $o;
    }
    return array_values(array_unique($out));
  }

  /**
   * @return list<string>
   */
  private function buildOriginsFromDomainSettings(): array {
    if (!$this->moduleHandler->moduleExists('myeventlane_core')) {
      return [];
    }
    $domain = $this->configFactory->get('myeventlane_core.domain_settings');
    $origins = [];
    foreach (['public_domain', 'vendor_domain', 'admin_domain'] as $key) {
      $url = trim((string) $domain->get($key));
      if ($url === '') {
        continue;
      }
      $parsed = parse_url($url);
      if (!isset($parsed['scheme'], $parsed['host'])) {
        continue;
      }
      $origin = $parsed['scheme'] . '://' . $parsed['host'];
      if (!empty($parsed['port'])) {
        $origin .= ':' . $parsed['port'];
      }
      $origins[] = $origin;
    }
    return $origins;
  }

  /**
   * Validates a full redirect_uri for the authorize endpoint.
   */
  public function isRedirectUriAllowed(string $redirectUri): bool {
    $redirectUri = trim($redirectUri);
    if ($redirectUri === '' || strlen($redirectUri) > 2048) {
      return FALSE;
    }
    $parts = parse_url($redirectUri);
    if ($parts === FALSE || !isset($parts['scheme'], $parts['host'])) {
      return FALSE;
    }
    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    if ($scheme !== 'https') {
      $allow_http = (bool) Settings::get('myeventlane_auth.allow_http_redirect_uris', FALSE);
      if (!$allow_http && !in_array($host, ['127.0.0.1', 'localhost'], TRUE)) {
        $this->logger->warning('Rejected non-HTTPS redirect_uri host @host', ['@host' => $host]);
        return FALSE;
      }
    }
    $allowed = $this->getAllowedRedirectHosts();
    if ($allowed === []) {
      $this->logger->error('No allowed_redirect_hosts configured; rejecting redirect_uri.');
      return FALSE;
    }
    return in_array($host, $allowed, TRUE);
  }

  /**
   * Validates redirect matches client-specific prefixes (if configured).
   */
  public function matchesClientRedirectPrefixes(string $clientId, string $redirectUri): bool {
    $clients = $this->configFactory->get('myeventlane_auth.settings')->get('oauth_clients') ?: [];
    if (!is_array($clients) || !isset($clients[$clientId]) || !is_array($clients[$clientId])) {
      return TRUE;
    }
    $prefixes = $clients[$clientId]['redirect_uri_prefixes'] ?? [];
    if (!is_array($prefixes) || $prefixes === []) {
      return TRUE;
    }
    foreach ($prefixes as $prefix) {
      $prefix = (string) $prefix;
      if ($prefix !== '' && str_starts_with($redirectUri, $prefix)) {
        return TRUE;
      }
    }
    $this->logger->warning('redirect_uri did not match any prefix for client @id', ['@id' => $clientId]);
    return FALSE;
  }

  /**
   * Whether an Origin header is allowed for credentialed CORS.
   */
  public function isAllowedOrigin(?string $origin): bool {
    if ($origin === NULL || $origin === '') {
      return FALSE;
    }
    return in_array($origin, $this->getAllowedCorsOrigins(), TRUE);
  }

}
