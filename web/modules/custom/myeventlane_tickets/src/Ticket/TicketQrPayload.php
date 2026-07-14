<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Ticket;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Psr\Log\LoggerInterface;

/**
 * Builds and validates signed ticket QR payloads.
 */
final class TicketQrPayload {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds payload for a ticket based on configured mode.
   */
  public function buildForTicket(Ticket $ticket): string {
    $ticket_code = (string) $ticket->get('ticket_code')->value;
    $event_id = (int) $ticket->get('event_id')->target_id;
    $mode = (string) ($this->configFactory->get('myeventlane_tickets.settings')->get('qr_payload_mode') ?? 'signed');

    if ($mode === 'code_only') {
      return $ticket_code;
    }

    if ($this->shouldUseStructuredPayload($ticket)) {
      return $this->buildStructuredPayload($ticket);
    }

    $issued_ts = (int) $ticket->getCreatedTime();
    $message = $ticket_code . ':' . $event_id . ':' . $issued_ts;
    $sig = $this->base64UrlEncode(hash_hmac('sha256', $message, $this->getSecret(), TRUE));

    return sprintf('mel:v1:%s:%d:%d:%s', $ticket_code, $event_id, $issued_ts, $sig);
  }

  /**
   * Parses ticket input and validates signature when signed format is used.
   *
   * @return array{ticket_code: string, event_id: int|null, signed: bool, ticket_uuid?: string, entitlement_type?: string, owner_id?: int, expires_at?: int, redemption_metadata?: array<string, mixed>}|null
   *   Parsed data or NULL when invalid.
   */
  public function parseAndValidate(string $input): ?array {
    $input = trim($input);
    if ($input === '') {
      return NULL;
    }

    if (!str_starts_with($input, 'mel:v1:')) {
      return [
        'ticket_code' => $input,
        'event_id' => NULL,
        'signed' => FALSE,
      ];
    }

    if (str_starts_with($input, 'mel:v1:json:')) {
      return $this->parseStructuredPayload($input);
    }

    $parts = explode(':', $input, 6);
    if (count($parts) !== 6) {
      return NULL;
    }

    [, $version, $ticket_code, $event_id_raw, $issued_ts_raw, $sig] = $parts;
    if ($version !== 'v1') {
      return NULL;
    }
    if ($ticket_code === '' || !ctype_digit($event_id_raw) || !ctype_digit($issued_ts_raw)) {
      return NULL;
    }

    $event_id = (int) $event_id_raw;
    $issued_ts = (int) $issued_ts_raw;
    $message = $ticket_code . ':' . $event_id . ':' . $issued_ts;
    $expected_sig = $this->base64UrlEncode(hash_hmac('sha256', $message, $this->getSecret(), TRUE));

    if (!hash_equals($expected_sig, $sig)) {
      $this->logger->warning('Invalid ticket QR signature for code @code.', ['@code' => $ticket_code]);
      return NULL;
    }

    return [
      'ticket_code' => $ticket_code,
      'event_id' => $event_id,
      'signed' => TRUE,
    ];
  }

  /**
   * Builds the structured mel:v1 payload for non-admission entitlements.
   */
  private function buildStructuredPayload(Ticket $ticket): string {
    $payload = [
      'v' => 1,
      'type' => $ticket->getEntitlementType(),
      'tid' => $ticket->uuid(),
      'eid' => (int) $ticket->get('event_id')->target_id,
      'cap' => [
        'limit' => $ticket->getRedemptionLimit(),
        'multi' => $ticket->getRedemptionLimit() > 1,
      ],
    ];

    $expires_at = $this->getExpiresAtTimestamp($ticket);
    if ($expires_at !== NULL) {
      $payload['exp'] = $expires_at;
    }

    try {
      $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
    catch (\JsonException $e) {
      $this->logger->error('Failed to encode structured ticket QR payload for ticket @ticket: @message', [
        '@ticket' => (string) $ticket->id(),
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }

    $encoded = $this->base64UrlEncode($json);
    $sig = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->getSecret(), TRUE));

    return sprintf('mel:v1:json:%s:%s', $encoded, $sig);
  }

  /**
   * Parses and validates the structured mel:v1 JSON payload.
   *
   * @return array{ticket_code: string, event_id: int|null, signed: bool, ticket_uuid?: string, entitlement_type?: string, owner_id?: int, expires_at?: int, redemption_metadata?: array<string, mixed>}|null
   *   Parsed data or NULL when invalid.
   */
  private function parseStructuredPayload(string $input): ?array {
    $parts = explode(':', $input, 5);
    if (count($parts) !== 5) {
      return NULL;
    }

    [, $version, $format, $encoded, $sig] = $parts;
    if ($version !== 'v1' || $format !== 'json' || $encoded === '' || $sig === '') {
      return NULL;
    }

    $expected_sig = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->getSecret(), TRUE));
    if (!hash_equals($expected_sig, $sig)) {
      $this->logger->warning('Invalid structured ticket QR signature.');
      return NULL;
    }

    $json = $this->base64UrlDecode($encoded);
    if ($json === NULL) {
      return NULL;
    }

    try {
      $payload = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->logger->warning('Invalid structured ticket QR JSON: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    if (!is_array($payload) || (int) ($payload['v'] ?? 0) !== 1) {
      return NULL;
    }

    $ticket_uuid = trim((string) ($payload['tid'] ?? ''));
    if ($ticket_uuid === '') {
      return NULL;
    }

    $event_id = isset($payload['eid']) && is_numeric($payload['eid']) ? (int) $payload['eid'] : NULL;
    $result = [
      'ticket_code' => isset($payload['code']) ? (string) $payload['code'] : '',
      'event_id' => $event_id,
      'signed' => TRUE,
      'ticket_uuid' => $ticket_uuid,
    ];

    if (isset($payload['type']) && is_string($payload['type'])) {
      $result['entitlement_type'] = $payload['type'];
    }
    if (isset($payload['owner']) && is_numeric($payload['owner'])) {
      $result['owner_id'] = (int) $payload['owner'];
    }
    if (isset($payload['exp']) && is_numeric($payload['exp'])) {
      $result['expires_at'] = (int) $payload['exp'];
    }
    if (isset($payload['cap']) && is_array($payload['cap'])) {
      $result['redemption_metadata'] = $payload['cap'];
    }

    return $result;
  }

  /**
   * Determines if this ticket needs the structured payload variant.
   */
  private function shouldUseStructuredPayload(Ticket $ticket): bool {
    if ($ticket->getEntitlementType() !== Ticket::ENTITLEMENT_TICKET) {
      return TRUE;
    }
    return $this->getExpiresAtTimestamp($ticket) !== NULL;
  }

  /**
   * Reads optional expiry as a Unix timestamp.
   */
  private function getExpiresAtTimestamp(Ticket $ticket): ?int {
    if (!$ticket->hasField('expires_at') || $ticket->get('expires_at')->isEmpty()) {
      return NULL;
    }
    $timestamp = strtotime((string) $ticket->get('expires_at')->value . ' UTC');
    return $timestamp === FALSE ? NULL : $timestamp;
  }

  /**
   * Whether a QR signing secret is available from settings or environment.
   */
  public function isSecretConfigured(): bool {
    return $this->resolveSecretSource() !== NULL;
  }

  /**
   * Resolves which non-empty source would supply the QR signing secret.
   *
   * Order matches getSecret(): settings key, legacy settings key, then MEL_QR_SECRET.
   * Does not reveal the secret value.
   *
   * @return string|null
   *   Machine-safe source label, or NULL when missing.
   */
  public function resolveSecretSource(): ?string {
    $secret = Settings::get('myeventlane_qr_secret');
    if (!empty($secret)) {
      return 'settings:myeventlane_qr_secret';
    }

    $legacy = Settings::get('myeventlane_ticket_qr_secret');
    if (!empty($legacy)) {
      return 'settings:myeventlane_ticket_qr_secret';
    }

    $env = getenv('MEL_QR_SECRET') ?: '';
    if ($env !== '') {
      return 'env:MEL_QR_SECRET';
    }

    return NULL;
  }

  /**
   * Whether the active qr_payload_mode requires a signing secret.
   */
  public function requiresSigningSecret(): bool {
    $mode = (string) ($this->configFactory->get('myeventlane_tickets.settings')->get('qr_payload_mode') ?? 'signed');
    return $mode !== 'code_only';
  }

  /**
   * Gets QR signing secret from settings.php first, then environment.
   */
  private function getSecret(): string {
    if (!$this->isSecretConfigured()) {
      $this->logger->error('MEL QR signing secret is not configured.');
      throw new \RuntimeException('MEL QR signing secret is not configured.');
    }

    $secret = Settings::get('myeventlane_qr_secret');
    if (empty($secret)) {
      $secret = Settings::get('myeventlane_ticket_qr_secret');
    }
    if (empty($secret)) {
      $secret = getenv('MEL_QR_SECRET') ?: '';
    }

    return (string) $secret;
  }

  /**
   * Converts binary data to URL-safe base64 without padding.
   */
  private function base64UrlEncode(string $binary): string {
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
  }

  /**
   * Decodes URL-safe base64 without padding.
   */
  private function base64UrlDecode(string $encoded): ?string {
    $padded = str_pad(strtr($encoded, '-_', '+/'), strlen($encoded) % 4 === 0 ? strlen($encoded) : strlen($encoded) + 4 - strlen($encoded) % 4, '=', STR_PAD_RIGHT);
    $decoded = base64_decode($padded, TRUE);
    return $decoded === FALSE ? NULL : $decoded;
  }

}
