<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Guards customer-facing fulfillment execution text from internal diagnostics.
 *
 * Staff lifecycle read-models use key=value tokens; customer surfaces must not
 * echo them if strip patterns miss a fragment.
 */
final class OperationalFulfillmentExecutionCustomerDiagnostics {

  /**
   * Diagnostic token names stripped by stripInternalExecutionDiagnostics().
   *
   * @var list<string>
   */
  public const INTERNAL_DIAGNOSTIC_TOKEN_NAMES = [
    'pickup_lane',
    'redemption_lane',
    'scanner_action',
    'timed',
    'session',
    'redemptions',
    'lifecycle',
    'requires_fulfilment',
    'pdf',
    'state',
    'fulfilment_row',
    'qr_payload',
    'replay_token',
    'fingerprint',
    'device_fingerprint',
    'inventory_quantity',
    'stock_count',
    'warehouse_ids',
    'shipment_provider',
  ];

  /**
   * Whether text still contains internal key=value diagnostic material.
   */
  public static function containsInternalDiagnosticTokens(string $text): bool {
    if ($text === '') {
      return FALSE;
    }
    $tokens = implode('|', self::INTERNAL_DIAGNOSTIC_TOKEN_NAMES);
    return (bool) preg_match('/\b(' . $tokens . ')=/', $text);
  }

  /**
   * Strips internal lifecycle diagnostics from customer execution projections.
   */
  public static function stripInternalExecutionDiagnostics(string $text): string {
    if ($text === '') {
      return '';
    }
    $patterns = [
      '/\bscanner_action=[^;]*\s*(;\s*|\s*|$)/',
      '/\bpickup_lane=[^;]*\s*(;\s*|\s*|$)/',
      '/\bredemption_lane=[^;]*\s*(;\s*|\s*|$)/',
      '/\btimed=[^;]*\s*(;\s*|\s*|$)/',
      '/\bsession=[^;]*\s*(;\s*|\s*|$)/',
      '/\bredemptions=\d+\/\d+\s*(;\s*|\s*|$)/',
      '/\blifecycle=[^;]*\s*(;\s*|\s*|$)/',
      '/\brequires_fulfilment=[^;]*\s*(;\s*|\s*|$)/',
      '/\bpdf=[^;]*\s*(;\s*|\s*|$)/',
      '/\bstate=[^;]*\s*(;\s*|\s*|$)/',
      '/\bfulfilment_row=[^;]*\s*(;\s*|\s*|$)/',
      '/\bqr_payload=[^;]*\s*(;\s*|\s*|$)/',
      '/\breplay_token=[^;]*\s*(;\s*|\s*|$)/',
      '/\bfingerprint=[^;]*\s*(;\s*|\s*|$)/',
      '/\bdevice_fingerprint=[^;]*\s*(;\s*|\s*|$)/',
      '/\binventory_quantity=[^;]*\s*(;\s*|\s*|$)/',
      '/\bstock_count=[^;]*\s*(;\s*|\s*|$)/',
      '/\bwarehouse_ids=[^;]*\s*(;\s*|\s*|$)/',
      '/\bshipment_provider=[^;]*\s*(;\s*|\s*|$)/',
    ];
    $out = $text;
    foreach ($patterns as $pattern) {
      $out = preg_replace($pattern, '', $out) ?? $out;
    }
    $out = trim((string) $out);
    $out = preg_replace('/;\s*;/', '; ', $out) ?? $out;
    $out = preg_replace('/\s+—\s+—/', ' — ', $out) ?? $out;
    return trim((string) $out, " \t\n\r\0\x0B;—");
  }

}
