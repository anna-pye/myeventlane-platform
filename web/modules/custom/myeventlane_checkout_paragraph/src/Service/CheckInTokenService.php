<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_paragraph\Service;

use Drupal\Core\Site\Settings;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Service for generating and validating secure check-in tokens.
 *
 * Uses HMAC signing with site private key to prevent token forgery.
 */
final class CheckInTokenService {

  /**
   * Generates a secure token for an attendee paragraph.
   *
   * Token format: base64(paragraph_id:timestamp:hmac)
   * HMAC is computed over: paragraph_id:timestamp using site private key.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The attendee paragraph.
   *
   * @return string
   *   A signed token string.
   */
  public function generateToken(ParagraphInterface $paragraph): string {
    $paragraph_id = (int) $paragraph->id();
    $timestamp = time();
    $secret = $this->getSecretKey();

    // Create message: paragraph_id:timestamp
    $message = $paragraph_id . ':' . $timestamp;

    // Generate HMAC signature.
    $hmac = hash_hmac('sha256', $message, $secret, TRUE);

    // Encode: paragraph_id:timestamp:hmac_base64
    $token_data = $paragraph_id . ':' . $timestamp . ':' . base64_encode($hmac);

    // Base64 encode the entire token for URL safety.
    return base64_encode($token_data);
  }

  /**
   * Validates and decodes a check-in token.
   *
   * @param string $token
   *   The token to validate.
   *
   * @return array{paragraph_id: int, valid: bool}|null
   *   Array with paragraph_id and valid flag, or NULL if token is invalid.
   */
  public function validateToken(string $token): ?array {
    if (empty($token)) {
      return NULL;
    }

    try {
      // Decode base64 token.
      $token_data = base64_decode($token, TRUE);
      if ($token_data === FALSE) {
        return NULL;
      }

      // Split: paragraph_id:timestamp:hmac_base64
      $parts = explode(':', $token_data);
      if (count($parts) !== 3) {
        return NULL;
      }

      [$paragraph_id, $timestamp, $hmac_base64] = $parts;

      // Validate paragraph ID is numeric.
      if (!is_numeric($paragraph_id) || (int) $paragraph_id <= 0) {
        return NULL;
      }

      // Check token age (expire after 24 hours).
      $age = time() - (int) $timestamp;
      if ($age < 0 || $age > 86400) {
        return NULL;
      }

      // Verify HMAC.
      $secret = $this->getSecretKey();
      $message = $paragraph_id . ':' . $timestamp;
      $expected_hmac = hash_hmac('sha256', $message, $secret, TRUE);
      $expected_hmac_base64 = base64_encode($expected_hmac);

      if (!hash_equals($expected_hmac_base64, $hmac_base64)) {
        // HMAC mismatch - token is invalid or forged.
        return NULL;
      }

      return [
        'paragraph_id' => (int) $paragraph_id,
        'valid' => TRUE,
      ];
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Gets the secret key for HMAC signing.
   *
   * Uses site private key from Drupal settings.
   *
   * @return string
   *   The secret key.
   */
  private function getSecretKey(): string {
    // Use Drupal's private key from settings.
    // This is already used for CSRF tokens and other secure operations.
    $private_key = Settings::get('hash_salt', '');
    if (empty($private_key)) {
      // Fallback: use a constant (less secure, but better than nothing).
      // In production, hash_salt should always be set.
      $private_key = 'myeventlane-checkin-secret-key';
    }

    // Add a prefix to make this key specific to check-in tokens.
    return $private_key . '-checkin-tokens';
  }

}

