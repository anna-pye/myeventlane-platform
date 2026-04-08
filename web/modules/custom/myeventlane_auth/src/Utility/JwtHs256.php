<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Utility;

/**
 * Minimal HS256 JWT encode/decode (no external dependencies).
 */
final class JwtHs256 {

  /**
   * Encodes a JWT.
   *
   * @param array<string, mixed> $claims
   *   Claims (sub, iat, exp, jti, etc.).
   */
  public function encode(array $claims, string $secret): string {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $segments = [
      $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
      $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR)),
    ];
    $signing_input = implode('.', $segments);
    $signature = hash_hmac('sha256', $signing_input, $secret, TRUE);
    $segments[] = $this->base64UrlEncode($signature);
    return implode('.', $segments);
  }

  /**
   * Decodes and verifies a JWT.
   *
   * @return array<string, mixed>|null
   *   Payload claims or NULL if invalid/expired.
   */
  public function decode(string $jwt, string $secret, ?int $now = NULL): ?array {
    $now = $now ?? time();
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
      return NULL;
    }
    [$h64, $p64, $s64] = $parts;
    $signing_input = $h64 . '.' . $p64;
    $expected = $this->base64UrlEncode(hash_hmac('sha256', $signing_input, $secret, TRUE));
    if (!hash_equals($expected, $s64)) {
      return NULL;
    }
    $header = json_decode($this->base64UrlDecode($h64), TRUE);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
      return NULL;
    }
    $payload = json_decode($this->base64UrlDecode($p64), TRUE);
    if (!is_array($payload)) {
      return NULL;
    }
    if (isset($payload['nbf']) && $now < (int) $payload['nbf']) {
      return NULL;
    }
    if (isset($payload['exp']) && $now >= (int) $payload['exp']) {
      return NULL;
    }
    return $payload;
  }

  private function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

  private function base64UrlDecode(string $data): string {
    $remainder = strlen($data) % 4;
    if ($remainder) {
      $data .= str_repeat('=', 4 - $remainder);
    }
    $decoded = base64_decode(strtr($data, '-_', '+/'), TRUE);
    return $decoded === FALSE ? '' : $decoded;
  }

}
