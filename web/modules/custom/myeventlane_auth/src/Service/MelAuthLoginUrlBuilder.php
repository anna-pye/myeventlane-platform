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
 * OAuth "state" MUST be generated only here so CSRF/session correlation is not
 * duplicated in subscribers or controllers. Callers must not pass prebuilt state.
 */
final class MelAuthLoginUrlBuilder {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AuthRedirectValidator $authRedirectValidator,
    private readonly LoggerChannelInterface $logger,
    private readonly DomainDetector $domainDetector,
  ) {}

  /**
   * Builds vendor SSO login URL, generates state, stores session keys for callback.
   *
   * Correlation ID is reused when a prior OAuth attempt on this session is still
   * within TTL, the redirect_uri matches this build, and state is in flight
   * (avoids breaking trace continuity on duplicate redirects; new redirect target
   * forces a new correlation).
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
      // Never use the vendor host as the auth base: /auth/* routes must hit the
      // same Drupal app as the public site. Prefer configured public_domain.
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

    $session = $request->getSession();
    $now = MelAuthOAuthSession::requestTime($request);

    // State is generated only in this service; never accept external state.
    $state = bin2hex(random_bytes(24));

    // Reuse correlation ID within TTL if in-flight state targets the same redirect_uri.
    $existingCor = (string) $session->get(MelAuthOAuthSession::KEY_CORRELATION_ID, '');
    $storedRedirectUri = (string) $session->get(MelAuthOAuthSession::KEY_REDIRECT_URI, '');
    $createdRaw = $session->get(MelAuthOAuthSession::KEY_STATE_CREATED);
    $createdTs = is_numeric($createdRaw) ? (int) $createdRaw : 0;
    $sameRedirectUri = $storedRedirectUri !== ''
      && hash_equals(
        $this->normalizeRedirectUriForComparison($storedRedirectUri),
        $this->normalizeRedirectUriForComparison($callbackUrl)
      );
    $reuseCorrelation = $existingCor !== ''
      && $sameRedirectUri
      && $createdTs > 0
      && ($now - $createdTs) <= MelAuthOAuthSession::STATE_TTL_SECONDS
      && $session->has(MelAuthOAuthSession::KEY_STATE);

    if ($reuseCorrelation) {
      $correlationId = $existingCor;
      $this->logger->debug('Vendor SSO login URL rebuilt (correlation_id=@cid).', [
        '@cid' => $correlationId,
      ]);
    }
    else {
      $correlationId = bin2hex(random_bytes(6));
      $this->logger->notice('Vendor SSO login URL built (correlation_id=@cid).', [
        '@cid' => $correlationId,
      ]);
    }

    $session->set(MelAuthOAuthSession::KEY_STATE, $state);
    $session->set(MelAuthOAuthSession::KEY_STATE_CREATED, $now);
    $session->set(MelAuthOAuthSession::KEY_REDIRECT_URI, $callbackUrl);
    $session->set(MelAuthOAuthSession::KEY_CORRELATION_ID, $correlationId);

    $query = [
      'response_type' => 'code',
      'client_id' => $clientId,
      'redirect_uri' => $callbackUrl,
      'state' => $state,
    ];

    return $authBase . '/auth/login?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  }

  /**
   * Normalizes redirect_uri values so harmless differences do not break reuse.
   *
   * Lowercases scheme and host, drops default ports, trims path trailing slashes
   * (except root), preserves query, strips fragments.
   */
  private function normalizeRedirectUriForComparison(string $url): string {
    $url = trim($url);
    if ($url === '') {
      return '';
    }
    $parts = parse_url($url);
    if ($parts === FALSE || empty($parts['scheme']) || empty($parts['host'])) {
      return $url;
    }
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    $portPart = '';
    if (!empty($parts['port'])) {
      $p = (int) $parts['port'];
      $default = ($scheme === 'https') ? 443 : (($scheme === 'http') ? 80 : 0);
      if ($p !== $default) {
        $portPart = ':' . $p;
      }
    }
    $path = isset($parts['path']) ? (string) $parts['path'] : '';
    $path = '/' . ltrim($path, '/');
    $path = rtrim($path, '/');
    if ($path === '') {
      $path = '/';
    }
    $query = isset($parts['query']) && (string) $parts['query'] !== '' ? '?' . $parts['query'] : '';
    return $scheme . '://' . $host . $portPart . $path . $query;
  }

}
