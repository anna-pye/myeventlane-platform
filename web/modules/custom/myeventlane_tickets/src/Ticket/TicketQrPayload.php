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

    $issued_ts = (int) $ticket->getCreatedTime();
    $message = $ticket_code . ':' . $event_id . ':' . $issued_ts;
    $sig = $this->base64UrlEncode(hash_hmac('sha256', $message, $this->getSecret(), TRUE));

    return sprintf('mel:v1:%s:%d:%d:%s', $ticket_code, $event_id, $issued_ts, $sig);
  }

  /**
   * Parses ticket input and validates signature when signed format is used.
   *
   * @return array{ticket_code: string, event_id: int|null, signed: bool}|null
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
   * Gets QR signing secret from settings.php first, then config fallback.
   */
  private function getSecret(): string {
    $from_settings = (string) Settings::get('myeventlane_ticket_qr_secret', '');
    if ($from_settings !== '') {
      return $from_settings;
    }

    $from_config = (string) ($this->configFactory->get('myeventlane_tickets.settings')->get('qr_secret') ?? '');
    if ($from_config !== '') {
      return $from_config;
    }

    $hash_salt = (string) Settings::get('hash_salt', '');
    if ($hash_salt !== '') {
      return $hash_salt . ':myeventlane-ticket-qr';
    }

    $this->logger->error('QR secret is not configured; falling back to insecure static secret.');
    return 'myeventlane-ticket-qr-fallback';
  }

  /**
   * Converts binary data to URL-safe base64 without padding.
   */
  private function base64UrlEncode(string $binary): string {
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
  }

}
