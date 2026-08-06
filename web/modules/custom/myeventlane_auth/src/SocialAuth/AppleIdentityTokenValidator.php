<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\SocialAuth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\ClientInterface;

/**
 * Verifies Apple identity-token signatures and required OpenID claims.
 */
final class AppleIdentityTokenValidator {

  private const APPLE_ISSUER = 'https://appleid.apple.com';
  private const APPLE_KEYS_URL = 'https://appleid.apple.com/auth/keys';

  public function __construct(
    private readonly ClientInterface $httpClient,
  ) {}

  /**
   * Verifies an Apple identity token for the initiating browser session.
   *
   * @return array<string, mixed>
   *   The verified claims.
   *
   * @throws \UnexpectedValueException
   *   When the signature or any required claim is invalid.
   */
  public function validate(string $identityToken, string $clientId, string $nonce): array {
    if ($identityToken === '' || $clientId === '' || $nonce === '') {
      throw new \UnexpectedValueException('Apple identity-token validation context is incomplete.');
    }

    $response = $this->httpClient->request('GET', self::APPLE_KEYS_URL);
    if ($response->getStatusCode() !== 200) {
      throw new \UnexpectedValueException('Apple identity keys could not be loaded.');
    }

    $keySet = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($keySet) || !isset($keySet['keys'])) {
      throw new \UnexpectedValueException('Apple identity keys are invalid.');
    }

    $decoded = JWT::decode($identityToken, JWK::parseKeySet($keySet));
    $claims = get_object_vars($decoded);
    $this->validateClaims($claims, $clientId, $nonce);

    return $claims;
  }

  /**
   * Enforces Apple's issuer, audience, expiry, subject and nonce contract.
   *
   * @param array<string, mixed> $claims
   *   Verified JWT claims.
   * @param string $clientId
   *   The Apple Services ID expected in the audience claim.
   * @param string $nonce
   *   The nonce stored when the browser authentication started.
   */
  public function validateClaims(array $claims, string $clientId, string $nonce): void {
    if (!isset($claims['iss']) || !is_string($claims['iss']) || !hash_equals(self::APPLE_ISSUER, $claims['iss'])) {
      throw new \UnexpectedValueException('Apple identity-token issuer is invalid.');
    }

    $audiences = is_array($claims['aud'] ?? NULL)
      ? $claims['aud']
      : [$claims['aud'] ?? NULL];
    if (!in_array($clientId, $audiences, TRUE)) {
      throw new \UnexpectedValueException('Apple identity-token audience is invalid.');
    }

    if (!isset($claims['exp']) || !is_int($claims['exp']) || $claims['exp'] <= time()) {
      throw new \UnexpectedValueException('Apple identity token has expired.');
    }

    if (!isset($claims['sub']) || !is_string($claims['sub']) || trim($claims['sub']) === '') {
      throw new \UnexpectedValueException('Apple identity-token subject is invalid.');
    }

    if (!isset($claims['nonce']) || !is_string($claims['nonce']) || !hash_equals($nonce, $claims['nonce'])) {
      throw new \UnexpectedValueException('Apple identity-token nonce is invalid.');
    }
  }

}
