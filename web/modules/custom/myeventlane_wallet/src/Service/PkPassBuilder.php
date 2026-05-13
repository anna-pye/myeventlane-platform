<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\UniversalTicketViewModelBuilder;
use JsonException;

/**
 * Builds Apple Wallet .pkpass files.
 *
 * Issued tickets drive operational scaffold content through the universal ticket
 * view model (QR payloads originate from TicketQrPayload inside that builder).
 */
final class PkPassBuilder {

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly UniversalTicketViewModelBuilder $ticketViewModelBuilder,
  ) {}

  /**
   * Generates a .pkpass file path for the given order item route.
   *
   * @param \Drupal\myeventlane_tickets\Entity\Ticket|null $ticket
   *   Issued entitlement row when present; NULL preserves legacy placeholder
   *   behaviour without operational ticket assumptions.
   *
   * @return string
   *   Path to the generated .pkpass file.
   */
  public function generate(OrderItemInterface $orderItem, ?Ticket $ticket = NULL): string {
    $tempDir = $this->fileSystem->getTempDirectory();
    $passPath = $tempDir . '/ticket_' . $orderItem->id() . '.pkpass';

    if (!$ticket instanceof Ticket) {
      // Legacy compatibility: empty artifact until issuance exists for the line.
      file_put_contents($passPath, '');
      return $passPath;
    }

    $model = $this->ticketViewModelBuilder->build($ticket);
    try {
      $scaffold = json_encode([
        'mel_wallet_scaffold' => 1,
        'ticket_code' => (string) ($model['ticket']['code'] ?? ''),
        'qr_payload' => (string) ($model['qr']['payload'] ?? ''),
        'entitlement_type' => (string) ($model['ticket']['entitlement_type'] ?? ''),
        'capabilities' => $model['capabilities'] ?? [],
        'event_label' => (string) ($model['event']['label'] ?? ''),
      ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
    catch (JsonException) {
      file_put_contents($passPath, '');
      return $passPath;
    }

    file_put_contents($passPath, $scaffold);
    return $passPath;
  }

}
