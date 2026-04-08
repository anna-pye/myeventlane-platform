<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the canonical browser entry URL for OAuth: /auth/login on the auth host.
 *
 * OAuth "state" is HMAC-signed (VendorSsoStateSigner) so the vendor callback does
 * not depend on a PHP session cookie surviving the round trip from the auth host.
 */
final class MelAuthLoginUrlBuilder {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AuthRedirectValidator $authRedirectValidator,
    private readonly LoggerChannelInterface $logger,
    private readonly DomainDetector $domainDetector,
    private readonly VendorSsoStateSigner $stateSigner,
  ) {}

  /**
   * Builds vendor SSO login URL with signed state (session-independent).
   *
   * @return string|null
   *   Absolute URL to auth host /auth/login, or NULL if misconfigured or redirect
   *   URI is not allowlisted.
   */
  public function buildVendorSsoLoginUrl(Request $request): ?string {
    $config = $this->configFactory->get('myeventlane_auth.settings');
    $configuredBase = rtrim((string) $config->get('auth_base_url'), '/');
    $authBase = $configuredBase;
    if ($authBase === '') {
      try {
        $authBase = rtrim($this->domainDetector->buildDomainUrl('/', 'public'), '/');
        $this->logger->notice('Vendor SSO: auth_base_url empty; using myeventlane_core public_domain as auth base (@base).', [
          '@base' => $authBase,
        ]);
      }
      catch (\Throwable $e) {
        $authBase = $request->getSchemeAndHttpHost();
        $this->logger->warning('myeventlane_auth.settings auth_base_url is empty and public_domain is missing or invalid; using current request host (@host). Set auth_base_url or myeventlane_core.domain_settings.public_domain.', [
          '@host' => $authBase,
        ]);
      }
    }

    $clientId = (string) $config->get('vendor_sso_client_id');
    if ($clientId === '') {
      $this->logger->warning('Vendor SSO login URL skipped: vendor_sso_client_id is empty.');
      return NULL;
    }

    $callbackPath = (string) $config->get('vendor_sso_callback_path');
    if ($callbackPath === '') {
      $callbackPath = '/vendor/sso/callback';
    }
    if (!str_starts_with($callbackPath, '/')) {
      $callbackPath = '/' . $callbackPath;
    }

    $callbackUrl = $request->getSchemeAndHttpHost() . $callbackPath;
    if (!$this->authRedirectValidator->isRedirectUriAllowed($callbackUrl)) {
      $this->logger->error('Vendor SSO callback URI is not allowlisted: @uri', ['@uri' => $callbackUrl]);
      return NULL;
    }

    $correlationId = bin2hex(random_bytes(6));
    try {
      $state = $this->stateSigner->create($callbackUrl, $clientId, $correlationId);
    }
    catch (\RuntimeException $e) {
      $this->logger->error('Vendor SSO login URL skipped: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }

    $this->logger->notice('Vendor SSO login URL built (correlation_id=@cid).', [
      '@cid' => $correlationId,
    ]);

    $query = [
      'response_type' => 'code',
      'client_id' => $clientId,
      'redirect_uri' => $callbackUrl,
      'state' => $state,
    ];

    return $authBase . '/auth/login?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  }

}
