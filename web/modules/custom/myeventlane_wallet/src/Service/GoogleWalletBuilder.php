<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\UniversalTicketViewModelBuilder;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Builds Google Wallet Generic Pass "Save to Wallet" JWT links.
 *
 * Official contract (Google Wallet Generic Pass JWT):
 * https://developers.google.com/wallet/generic/use-cases/jwt
 *
 * Payload uses genericClasses + genericObjects (not eventTicket*).
 * Ownership checks remain in WalletGoogleController / WalletDownloadAccessChecker.
 * JWT signing uses a Google service account JSON path from site settings only.
 */
final class GoogleWalletBuilder {

  private const SAVE_URL_PREFIX = 'https://pay.google.com/gp/v/save/';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UniversalTicketViewModelBuilder $ticketViewModelBuilder,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether issuer + service account material can produce a signed save JWT.
   */
  public function isReady(): bool {
    try {
      $this->assertIssuerConfigured();
      $service_account = $this->loadServiceAccount();
      $this->assertJwtSigningWorks($service_account['private_key']);
      return TRUE;
    }
    catch (RuntimeException) {
      return FALSE;
    }
  }

  /**
   * Generates a Google Wallet save link for an order item route.
   *
   * @param \Drupal\myeventlane_tickets\Entity\Ticket|null $ticket
   *   Issued ticket when inward resolution succeeded; NULL for legacy mode.
   *
   * @return string
   *   The Google Wallet save URL.
   *
   * @throws \InvalidArgumentException
   *   When ticket/order item mismatch.
   * @throws \RuntimeException
   *   When JWT material is missing or signing fails.
   */
  public function generateSaveLink(OrderItemInterface $orderItem, ?Ticket $ticket = NULL): string {
    if (!$ticket instanceof Ticket) {
      // Legacy compatibility: frozen placeholder when no issued ticket exists.
      return self::SAVE_URL_PREFIX . 'placeholder';
    }

    if (!$ticket->get('order_item_id')->isEmpty()
      && (int) $ticket->get('order_item_id')->target_id !== (int) $orderItem->id()) {
      throw new InvalidArgumentException('Ticket order item mismatch for wallet link generation.');
    }

    $jwt = $this->buildSignedJwt($orderItem, $ticket);
    return self::SAVE_URL_PREFIX . $jwt;
  }

  /**
   * Builds and signs a Generic Pass save JWT for the issued ticket.
   *
   * @see https://developers.google.com/wallet/generic/use-cases/jwt
   */
  private function buildSignedJwt(OrderItemInterface $orderItem, Ticket $ticket): string {
    $issuer_id = $this->assertIssuerConfigured();
    $service_account = $this->loadServiceAccount();
    $model = $this->ticketViewModelBuilder->build($ticket);

    $ticket_code = (string) ($model['ticket']['code'] ?? '');
    $qr_payload = (string) ($model['qr']['payload'] ?? '');
    if ($ticket_code === '' || $qr_payload === '') {
      throw new RuntimeException('Google Wallet pass requires ticket code and QR payload.');
    }

    $event_label = (string) ($model['event']['label'] ?? 'Event');
    $holder = (string) ($model['holder']['name'] ?? '');
    $entitlement = (string) ($model['ticket']['entitlement_label'] ?? $model['ticket']['entitlement_type'] ?? 'Ticket');
    $ticket_uuid = (string) ($model['ticket']['uuid'] ?? $ticket->uuid());
    $event_id = (int) ($model['event']['id'] ?? 0);
    $class_suffix = $event_id > 0 ? ('mel.ticket.event.' . $event_id) : 'mel.ticket.generic';
    $class_id = $issuer_id . '.' . $class_suffix;
    $object_suffix = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $ticket_uuid !== '' ? $ticket_uuid : $ticket_code) ?? $ticket_code;
    $object_id = $issuer_id . '.' . $object_suffix;
    $issuer_name = trim((string) ($this->configFactory->get('myeventlane_wallet.settings')->get('apple_organisation_name') ?: '')) ?: 'MyEventLane';

    $now = time();
    $claims = [
      'iss' => $service_account['client_email'],
      'aud' => 'google',
      'typ' => 'savetowallet',
      'iat' => $now,
      'origins' => $this->allowedOrigins(),
      'payload' => [
        'genericClasses' => [
          [
            'id' => $class_id,
          ],
        ],
        'genericObjects' => [
          [
            'id' => $object_id,
            'classId' => $class_id,
            'state' => 'ACTIVE',
            'cardTitle' => [
              'defaultValue' => [
                'language' => 'en-AU',
                'value' => $issuer_name,
              ],
            ],
            'header' => [
              'defaultValue' => [
                'language' => 'en-AU',
                'value' => $event_label,
              ],
            ],
            'subheader' => [
              'defaultValue' => [
                'language' => 'en-AU',
                'value' => $entitlement,
              ],
            ],
            'hexBackgroundColor' => '#FFF0F5',
            'barcode' => [
              'type' => 'QR_CODE',
              'value' => $qr_payload,
              'alternateText' => $ticket_code,
            ],
            'textModulesData' => array_values(array_filter([
              $holder !== '' ? [
                'id' => 'holder',
                'header' => 'Name',
                'body' => $holder,
              ] : NULL,
              [
                'id' => 'ticket_code',
                'header' => 'Ticket code',
                'body' => $ticket_code,
              ],
            ])),
          ],
        ],
      ],
    ];

    try {
      $header = ['alg' => 'RS256', 'typ' => 'JWT'];
      $segments = [
        $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
        $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
      ];
    }
    catch (JsonException $e) {
      throw new RuntimeException('Unable to encode Google Wallet JWT claims.', 0, $e);
    }

    $signing_input = implode('.', $segments);
    try {
      $signature_b64 = $this->signRs256($signing_input, $service_account['private_key']);
    }
    catch (RuntimeException $e) {
      $this->logger->error('Google Wallet JWT signing failed for order item @id.', [
        '@id' => (string) $orderItem->id(),
      ]);
      throw $e;
    }

    return $signing_input . '.' . $signature_b64;
  }

  /**
   * Proves the service account private key can produce an RS256 JWT signature.
   */
  private function assertJwtSigningWorks(string $private_key_pem): void {
    $this->signRs256('mel.wallet.probe', $private_key_pem);
  }

  private function signRs256(string $signing_input, string $private_key_pem): string {
    $private_key = openssl_pkey_get_private($private_key_pem);
    if ($private_key === FALSE) {
      throw new RuntimeException('Google Wallet service account private key is invalid.');
    }

    $signature = '';
    $ok = openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256);
    if ($ok !== TRUE || $signature === '') {
      throw new RuntimeException('Google Wallet JWT signing failed.');
    }

    return $this->base64UrlEncode($signature);
  }

  private function assertIssuerConfigured(): string {
    $issuer_id = trim((string) $this->configFactory->get('myeventlane_wallet.settings')->get('google_issuer_id'));
    if ($issuer_id === '') {
      throw new RuntimeException('Google Wallet issuer ID is not configured.');
    }
    // Reject common misconfiguration: OAuth client secrets are not issuer IDs.
    if (str_starts_with($issuer_id, 'GOCSPX-') || str_contains($issuer_id, ' ')) {
      throw new RuntimeException('Google Wallet issuer ID appears invalid.');
    }
    return $issuer_id;
  }

  /**
   * @return array{client_email: string, private_key: string}
   *   Minimal service account fields required for JWT signing.
   */
  private function loadServiceAccount(): array {
    $wallet = Settings::get('myeventlane_wallet', []);
    if (!is_array($wallet)) {
      $wallet = [];
    }
    $path = trim((string) ($wallet['google_service_account_json_path'] ?? ''));
    if ($path === '' || !is_file($path) || !is_readable($path)) {
      throw new RuntimeException('Google Wallet service account JSON path is not readable.');
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
      throw new RuntimeException('Google Wallet service account JSON is empty.');
    }

    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (JsonException $e) {
      throw new RuntimeException('Google Wallet service account JSON is invalid.', 0, $e);
    }

    $email = trim((string) ($decoded['client_email'] ?? ''));
    $key = (string) ($decoded['private_key'] ?? '');
    if ($email === '' || $key === '' || ($decoded['type'] ?? '') !== 'service_account') {
      throw new RuntimeException('Google Wallet service account JSON is incomplete.');
    }

    return [
      'client_email' => $email,
      'private_key' => $key,
    ];
  }

  /**
   * @return list<string>
   *   Allowed HTTPS origins for the save JWT.
   */
  private function allowedOrigins(): array {
    $wallet = Settings::get('myeventlane_wallet', []);
    $configured = [];
    if (is_array($wallet) && isset($wallet['google_origins']) && is_array($wallet['google_origins'])) {
      foreach ($wallet['google_origins'] as $origin) {
        $origin = trim((string) $origin);
        if ($origin !== '') {
          $configured[] = rtrim($origin, '/');
        }
      }
    }

    if ($configured === []) {
      $base = Settings::get('myeventlane_wallet_public_origin');
      if (is_string($base) && $base !== '') {
        $configured[] = rtrim($base, '/');
      }
    }

    if ($configured === []) {
      global $base_url;
      if (is_string($base_url) && $base_url !== '') {
        $configured[] = rtrim($base_url, '/');
      }
    }

    return array_values(array_unique($configured));
  }

  private function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

}
