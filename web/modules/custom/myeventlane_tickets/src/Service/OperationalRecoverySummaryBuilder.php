<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Read-only recovery visibility cards from merged inspector diagnostics.
 *
 * Does not read Commerce purchaser fields, message bodies, or wallet secrets.
 */
final class OperationalRecoverySummaryBuilder {

  /**
   * @param array<int|string, array<string, mixed>> $inspections
   * @param array<string, mixed> $merged
   *
   * @return array<string, mixed>
   */
  public function buildSection(array $inspections, array $merged): array {
    $recovery = $merged['recovery'] ?? [];
    $artifacts = $merged['artifacts'] ?? [];
    $compatibility = $merged['compatibility'] ?? [];
    $guest = $merged['guest_continuity'] ?? [];

    $pdfPath = (string) ($compatibility['ticket_pdf_operational_path'] ?? 'n/a');
    $walletSurface = (string) ($compatibility['wallet_resolution_surface'] ?? 'n/a');
    $orderItemSurface = (string) ($compatibility['order_item_pdf_surface'] ?? 'n/a');

    $canonical = (string) ($artifacts['canonical_pdf_readiness'] ?? 'n/a');
    $attachment = (string) ($artifacts['attachment_continuity'] ?? 'n/a');
    $walletScaffold = (string) ($artifacts['wallet_route_scaffold'] ?? 'n/a');
    $qr = (string) ($artifacts['qr_payload_operational'] ?? 'n/a');

    $legacyIndicator = $this->legacyFallbackIndicator($pdfPath, $canonical);
    $ticketReady = $this->ticketReadyToken($canonical, $qr, $attachment);

    $cards = [
      [
        'label' => 'Recovery completion states (observed)',
        'value' => implode(', ', $recovery['completion_states_observed'] ?? []) ?: 'n/a',
      ],
      [
        'label' => 'Recovery mismatch observed',
        'value' => !empty($recovery['recovery_mismatch_observed']) ? 'yes' : 'no',
      ],
      [
        'label' => 'Late tickets after confirmation (signal)',
        'value' => $this->worstRecoveryScalar($inspections, 'late_tickets_after_confirmation'),
      ],
      [
        'label' => 'Recovery requirement signal (worst)',
        'value' => $this->worstRecoveryScalar($inspections, 'recovery_requirement_signal'),
      ],
      [
        'label' => 'Attachment continuity (worst)',
        'value' => $attachment,
      ],
      [
        'label' => 'Canonical vs legacy PDF indicator',
        'value' => $legacyIndicator,
      ],
      [
        'label' => 'Wallet route scaffold (worst)',
        'value' => $walletScaffold,
      ],
      [
        'label' => 'Wallet resolution surface (worst)',
        'value' => $walletSurface,
      ],
      [
        'label' => 'Guest continuity status (observed)',
        'value' => implode(', ', $guest['continuity_statuses_observed'] ?? []) ?: 'n/a',
      ],
      [
        'label' => 'Order-item PDF surface (worst)',
        'value' => $orderItemSurface,
      ],
      [
        'label' => 'Ticket-ready readiness (composite)',
        'value' => $ticketReady,
      ],
    ];

    return [
      'id' => 'recovery_visibility',
      'title' => 'Recovery visibility',
      'cards' => $cards,
    ];
  }

  /**
   * @param array<int|string, array<string, mixed>> $inspections
   */
  private function worstRecoveryScalar(array $inspections, string $key): string {
    $rank = ['unknown' => 0, 'skipped' => 1, 'none' => 2, 'false' => 2, 'pending' => 3, 'recovered' => 2, 'true' => 4];
    $worst = 'unknown';
    $worstScore = -1;
    foreach ($inspections as $row) {
      $r = $row['recovery'] ?? [];
      $val = (string) ($r[$key] ?? 'unknown');
      $score = $rank[$val] ?? 1;
      if ($score > $worstScore) {
        $worstScore = $score;
        $worst = $val;
      }
    }
    return $worst;
  }

  private function legacyFallbackIndicator(string $pdfPath, string $canonical): string {
    if (str_contains($pdfPath, 'legacy') || $canonical === 'legacy') {
      return 'legacy_path_observed';
    }
    if ($canonical === 'mixed') {
      return 'mixed_canonical_legacy';
    }
    if ($canonical === 'canonical' || $canonical === 'valid') {
      return 'canonical_preferred';
    }
    return 'unknown';
  }

  private function ticketReadyToken(string $canonical, string $qr, string $attachment): string {
    $attachment_ok = in_array($attachment, ['valid', 'canonical', 'mixed'], TRUE);
    if ($canonical === 'valid' && $qr === 'valid' && $attachment_ok) {
      return 'ready';
    }
    if ($canonical === 'missing' && $qr === 'missing') {
      return 'blocked';
    }
    return 'degraded';
  }

}
