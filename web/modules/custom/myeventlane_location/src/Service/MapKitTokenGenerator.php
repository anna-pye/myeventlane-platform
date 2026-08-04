<?php

declare(strict_types=1);

namespace Drupal\myeventlane_location\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Generates MapKit JS JWT tokens for Apple Maps integration.
 *
 * Tokens are signed with ES256 using the Apple-provided .p8 private key.
 *
 * @see https://developer.apple.com/documentation/mapkitjs/creating_and_using_tokens_with_mapkit_js
 */
final class MapKitTokenGenerator {

  /**
   * Token lifetime in seconds (1 hour).
   */
  private const TOKEN_TTL = 3600;

  /**
   * P-256 ECDSA component length in bytes (R and S).
   */
  private const ES256_PART_LENGTH = 32;

  /**
   * The config factory.
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * The request stack.
   */
  private RequestStack $requestStack;

  /**
   * Constructs a MapKitTokenGenerator.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack (used for the optional MapKit origin claim).
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    RequestStack $request_stack,
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('myeventlane_location');
    $this->requestStack = $request_stack;
  }

  /**
   * Generates a MapKit JS JWT token.
   *
   * @return string
   *   The JWT token, or empty string if generation fails.
   */
  public function generateToken(): string {
    $credentials = $this->resolveCredentials();
    if ($credentials === NULL) {
      $this->logger->warning('MapKit token generation failed: missing credentials. Configure Team ID, Key ID, and private key in location settings or settings.php.');
      return '';
    }

    [$team_id, $key_id, $private_key] = $credentials;

    $now = time();
    $header = [
      'alg' => 'ES256',
      'kid' => $key_id,
      'typ' => 'JWT',
    ];
    $payload = [
      'iss' => $team_id,
      'iat' => $now,
      'exp' => $now + self::TOKEN_TTL,
    ];

    $origin = $this->resolveOrigin();
    if ($origin !== '') {
      $payload['origin'] = $origin;
    }

    try {
      return $this->encodeEs256Jwt($header, $payload, $private_key);
    }
    catch (\Throwable $e) {
      $this->logger->error('MapKit token generation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Checks if token generation is properly configured.
   *
   * @return bool
   *   TRUE if all required credentials are available.
   */
  public function isConfigured(): bool {
    return $this->resolveCredentials() !== NULL;
  }

  /**
   * Resolves Team ID, Key ID, and private key from config or settings.php.
   *
   * @return array{0: string, 1: string, 2: string}|null
   *   [team_id, key_id, private_key] or NULL when incomplete.
   */
  private function resolveCredentials(): ?array {
    $config = $this->configFactory->get('myeventlane_location.settings');
    $team_id = (string) ($config->get('apple_maps_team_id') ?? '');
    $key_id = (string) ($config->get('apple_maps_key_id') ?? '');
    $private_key = (string) ($config->get('apple_maps_private_key') ?? '');

    if ($team_id === '' && defined('MYEVENTLANE_APPLE_MAPS_TEAM_ID')) {
      $team_id = (string) MYEVENTLANE_APPLE_MAPS_TEAM_ID;
    }
    if ($key_id === '' && defined('MYEVENTLANE_APPLE_MAPS_KEY_ID')) {
      $key_id = (string) MYEVENTLANE_APPLE_MAPS_KEY_ID;
    }
    if ($private_key === '' && defined('MYEVENTLANE_APPLE_MAPS_PRIVATE_KEY')) {
      $private_key = (string) MYEVENTLANE_APPLE_MAPS_PRIVATE_KEY;
    }

    $private_key = $this->normalizePrivateKey($private_key);

    if ($team_id === '' || $key_id === '' || $private_key === '') {
      return NULL;
    }

    return [$team_id, $key_id, $private_key];
  }

  /**
   * Builds the MapKit origin claim from the current request host.
   *
   * MapKit expects a scheme + host matching the browser Origin header,
   * e.g. https://staging.myeventlane.com.au
   */
  private function resolveOrigin(): string {
    if (defined('MYEVENTLANE_APPLE_MAPS_ORIGIN') && MYEVENTLANE_APPLE_MAPS_ORIGIN !== '') {
      return (string) MYEVENTLANE_APPLE_MAPS_ORIGIN;
    }

    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return '';
    }

    return $request->getSchemeAndHttpHost();
  }

  /**
   * Normalizes PEM private key text pasted into config.
   */
  private function normalizePrivateKey(string $private_key): string {
    $private_key = trim($private_key);
    if ($private_key === '') {
      return '';
    }

    // Config/textarea pastes often store literal \n sequences.
    if (str_contains($private_key, '\\n')) {
      $private_key = str_replace('\\n', "\n", $private_key);
    }

    $private_key = str_replace(["\r\n", "\r"], "\n", $private_key);

    return trim($private_key);
  }

  /**
   * Encodes and ES256-signs a JWT.
   *
   * @param array<string, mixed> $header
   *   JWT header claims.
   * @param array<string, mixed> $payload
   *   JWT payload claims.
   * @param string $private_key
   *   PEM-encoded EC private key.
   *
   * @return string
   *   Compact JWT serialization.
   *
   * @throws \RuntimeException
   *   When OpenSSL cannot load the key or sign the payload.
   */
  private function encodeEs256Jwt(array $header, array $payload, string $private_key): string {
    $segments = $this->base64UrlEncode((string) json_encode($header, JSON_THROW_ON_ERROR))
      . '.'
      . $this->base64UrlEncode((string) json_encode($payload, JSON_THROW_ON_ERROR));

    $key = openssl_pkey_get_private($private_key);
    if ($key === FALSE) {
      throw new \RuntimeException('Unable to load Apple Maps private key. Ensure it is a valid PEM EC key (.p8).');
    }

    $der_signature = '';
    $signed = openssl_sign($segments, $der_signature, $key, OPENSSL_ALGO_SHA256);
    if (!$signed || $der_signature === '') {
      throw new \RuntimeException('OpenSSL failed to sign the MapKit JWT (ES256).');
    }

    $jose_signature = $this->derToJose($der_signature, self::ES256_PART_LENGTH);
    return $segments . '.' . $this->base64UrlEncode($jose_signature);
  }

  /**
   * Converts an ASN.1 DER ECDSA signature to IEEE P1363 (R||S) for JWS.
   *
   * @param string $der
   *   DER-encoded ECDSA signature from openssl_sign().
   * @param int $part_length
   *   Expected byte length of R and S (32 for P-256 / ES256).
   *
   * @return string
   *   Raw R||S signature bytes.
   *
   * @throws \RuntimeException
   *   When the DER structure is invalid.
   */
  private function derToJose(string $der, int $part_length): string {
    $offset = 0;
    $length = strlen($der);

    if ($length < 8 || ord($der[$offset++]) !== 0x30) {
      throw new \RuntimeException('Invalid DER ECDSA signature (expected SEQUENCE).');
    }

    $seq_length = $this->readDerLength($der, $offset);
    if ($seq_length < 1 || $offset + $seq_length > $length) {
      throw new \RuntimeException('Invalid DER ECDSA signature length.');
    }

    $r = $this->readDerInteger($der, $offset, $part_length);
    $s = $this->readDerInteger($der, $offset, $part_length);

    return $r . $s;
  }

  /**
   * Reads a DER length value and advances $offset past the length bytes.
   */
  private function readDerLength(string $der, int &$offset): int {
    if (!isset($der[$offset])) {
      throw new \RuntimeException('Unexpected end of DER signature while reading length.');
    }

    $byte = ord($der[$offset++]);
    if (($byte & 0x80) === 0) {
      return $byte;
    }

    $num_bytes = $byte & 0x7F;
    if ($num_bytes === 0 || $num_bytes > 3 || !isset($der[$offset + $num_bytes - 1])) {
      throw new \RuntimeException('Invalid DER length encoding.');
    }

    $length = 0;
    for ($i = 0; $i < $num_bytes; $i++) {
      $length = ($length << 8) | ord($der[$offset++]);
    }

    return $length;
  }

  /**
   * Reads a DER INTEGER and returns it as a fixed-width unsigned big-endian value.
   */
  private function readDerInteger(string $der, int &$offset, int $part_length): string {
    if (!isset($der[$offset]) || ord($der[$offset++]) !== 0x02) {
      throw new \RuntimeException('Invalid DER ECDSA signature (expected INTEGER).');
    }

    $int_length = $this->readDerLength($der, $offset);
    if ($int_length < 1 || !isset($der[$offset + $int_length - 1])) {
      throw new \RuntimeException('Invalid DER INTEGER length.');
    }

    $value = substr($der, $offset, $int_length);
    $offset += $int_length;

    // Strip leading zero padding used for sign bit, then left-pad to part length.
    $value = ltrim($value, "\x00");
    if (strlen($value) > $part_length) {
      throw new \RuntimeException('ECDSA signature component exceeds ES256 size.');
    }

    return str_pad($value, $part_length, "\x00", STR_PAD_LEFT);
  }

  /**
   * Base64url-encodes binary data without padding.
   */
  private function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

}
