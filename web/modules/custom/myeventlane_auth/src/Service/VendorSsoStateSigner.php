<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * HMAC-signed OAuth "state" for vendor SSO (no session storage required).
 *
 * Fixes cross-subdomain flows where the vendor PHP session cookie is not sent
 * on redirect back from the auth host, which previously made server-side state
 * validation fail and left users anonymous after token exchange.
 */
final class VendorSsoStateSigner {

  private const PAYLOAD_VERSION = 1;

  public function __construct(
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Builds a signed state parameter for /auth/login.
   *
   * @return string
   *   Opaque state string (contains a '.'; legacy hex state did not).
   */
  public function create(string $redirectUri, string $clientId, string $correlationId): string {
    $key = $this->derivedKey();
    if ($key === '') {
      throw new \RuntimeException('Vendor SSO state signing requires $settings["myeventlane_auth"]["signing_key"].');
    }
    $now = $this->time->getRequestTime();
    $exp = $now + MelAuthOAuthSession::STATE_TTL_SECONDS;
    $payload = [
      'v' => self::PAYLOAD_VERSION,
      'exp' => $exp,
      'ru' => $redirectUri,
      'cid' => $clientId,
      'cor' => $correlationId,
    ];
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $sig = hash_hmac('sha256', $json, $key, TRUE);
    return $this->base64UrlEncode($json) . '.' . $this->base64UrlEncode($sig);
  }

  /**
   * Verifies signature, expiry, and payload shape.
   *
   * @return array{redirect_uri: string, client_id: string, correlation_id: string}|null
   */
  public function verify(string $state): ?array {
    $key = $this->derivedKey();
    if ($key === '') {
      $this->logger->error('Vendor SSO signed state: signing key missing.');
      return NULL;
    }
    if ($state === '' || !str_contains($state, '.')) {
      return NULL;
    }
    $parts = explode('.', $state, 2);
    if (count($parts) !== 2) {
      return NULL;
    }
    $json = $this->base64UrlDecode($parts[0]);
    $sigIn = $this->base64UrlDecode($parts[1]);
    if ($json === '' || $sigIn === '') {
      return NULL;
    }
    $expectedSig = hash_hmac('sha256', $json, $key, TRUE);
    if (!hash_equals($expectedSig, $sigIn)) {
      $this->logger->warning('Audit: vendor SSO signed state rejected (bad signature).');
      return NULL;
    }
    try {
      $data = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    if (!is_array($data) || (int) ($data['v'] ?? 0) !== self::PAYLOAD_VERSION) {
      return NULL;
    }
    $requestTime = $this->time->getRequestTime();
    $exp = (int) ($data['exp'] ?? 0);
    if ($requestTime >= $exp) {
      $this->logger->warning('Audit: vendor SSO signed state rejected (expired).');
      return NULL;
    }
    // Reject states that claim an unreasonably long lifetime (tampered exp).
    if ($exp > $requestTime + MelAuthOAuthSession::STATE_TTL_SECONDS + 120) {
      $this->logger->warning('Audit: vendor SSO signed state rejected (invalid exp).');
      return NULL;
    }
    $ru = trim((string) ($data['ru'] ?? ''));
    $cid = trim((string) ($data['cid'] ?? ''));
    $cor = trim((string) ($data['cor'] ?? ''));
    if ($ru === '' || $cid === '') {
      return NULL;
    }
    if (strlen($ru) > 2048) {
      return NULL;
    }
    return [
      'redirect_uri' => $ru,
      'client_id' => $cid,
      'correlation_id' => $cor,
    ];
  }

  /**
   * @return string
   *   32-byte derived key, or empty string if signing key is not configured.
   */
  private function derivedKey(): string {
    $base = myeventlane_auth_signing_key();
    if ($base === '') {
      return '';
    }
    return hash_hmac('sha256', 'mel_vendor_oauth_state_v1', $base, TRUE);
  }

  private function base64UrlEncode(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
  }

  private function base64UrlDecode(string $encoded): string {
    $remainder = strlen($encoded) % 4;
    if ($remainder > 0) {
      $encoded .= str_repeat('=', 4 - $remainder);
    }
    $decoded = base64_decode(strtr($encoded, '-_', '+/'), TRUE);
    return $decoded === FALSE ? '' : $decoded;
  }

}
