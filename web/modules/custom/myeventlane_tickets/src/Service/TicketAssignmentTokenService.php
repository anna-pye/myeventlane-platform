<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Site\Settings;

/**
 * Service for generating and validating secure ticket assignment tokens.
 *
 * Uses HMAC signing with site private key. Same pattern as CheckInTokenService.
 */
final class TicketAssignmentTokenService {

  /**
   * Token expiry in seconds (7 days).
   */
  private const EXPIRY_SECONDS = 604800;

  /**
   * Generates a secure token for a ticket.
   *
   * Token format: base64(ticket_id:timestamp:hmac)
   * HMAC is computed over: ticket_id:timestamp using site private key.
   *
   * @param int $ticket_id
   *   The ticket entity ID.
   *
   * @return string
   *   A signed token string.
   */
  public function generateToken(int $ticket_id): string {
    $timestamp = time();
    $secret = $this->getSecretKey();

    $message = $ticket_id . ':' . $timestamp;
    $hmac = hash_hmac('sha256', $message, $secret, TRUE);
    $token_data = $ticket_id . ':' . $timestamp . ':' . base64_encode($hmac);

    return base64_encode($token_data);
  }

  /**
   * Validates and decodes a ticket assignment token.
   *
   * @param string $token
   *   The token to validate.
   *
   * @return array{ticket_id: int, valid: bool}|null
   *   Array with ticket_id and valid flag, or NULL if token is invalid.
   */
  public function validateToken(string $token): ?array {
    if (empty($token)) {
      return NULL;
    }

    try {
      $token_data = base64_decode($token, TRUE);
      if ($token_data === FALSE) {
        return NULL;
      }

      $parts = explode(':', $token_data);
      if (count($parts) !== 3) {
        return NULL;
      }

      [$ticket_id, $timestamp, $hmac_base64] = $parts;

      if (!is_numeric($ticket_id) || (int) $ticket_id <= 0) {
        return NULL;
      }

      $age = time() - (int) $timestamp;
      if ($age < 0 || $age > self::EXPIRY_SECONDS) {
        return NULL;
      }

      $secret = $this->getSecretKey();
      $message = $ticket_id . ':' . $timestamp;
      $expected_hmac = hash_hmac('sha256', $message, $secret, TRUE);
      $expected_hmac_base64 = base64_encode($expected_hmac);

      if (!hash_equals($expected_hmac_base64, $hmac_base64)) {
        return NULL;
      }

      return [
        'ticket_id' => (int) $ticket_id,
        'valid' => TRUE,
      ];
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Gets the secret key for HMAC signing.
   */
  private function getSecretKey(): string {
    $private_key = Settings::get('hash_salt', '');
    if (empty($private_key)) {
      $private_key = 'myeventlane-ticket-assign-secret-key';
    }
    return $private_key . '-ticket-assign-tokens';
  }

}
