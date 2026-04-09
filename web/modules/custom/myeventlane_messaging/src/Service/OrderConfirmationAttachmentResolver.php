<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Adds PDF ticket attachments at send time for order confirmation emails.
 *
 * Queued payloads may contain non-PDF attachments (e.g. calendar). PDFs are
 * generated when the message is sent so issued ticket entities exist.
 */
final class OrderConfirmationAttachmentResolver {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    private readonly ?object $ticketPdfGenerator = NULL,
  ) {}

  /**
   * Merges queued attachments with per-ticket PDFs for order_confirmation.
   *
   * @param array<string, mixed> $context
   *   Message context (expects order_id for commerce orders).
   * @param array<int, array<string, string>> $queuedAttachments
   *   Attachments stored at queue time.
   *
   * @return array<int, array<string, string>>
   *   Combined list; PDF failures are logged and omitted (email still sends).
   */
  public function mergeOrderConfirmationAttachments(string $template, array $context, array $queuedAttachments): array {
    if ($template !== 'order_confirmation') {
      return $queuedAttachments;
    }
    $generator = $this->ticketPdfGenerator;
    if ($generator === NULL || !is_object($generator) || !method_exists($generator, 'getPdfContentForTicket')) {
      return $queuedAttachments;
    }
    if (!$this->entityTypeManager->hasDefinition('myeventlane_ticket')) {
      return $queuedAttachments;
    }

    $orderId = isset($context['order_id']) && is_numeric($context['order_id']) ? (int) $context['order_id'] : 0;
    if ($orderId < 1) {
      return $queuedAttachments;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $orderId)
      ->sort('id')
      ->execute();
    if (!$ids || !is_array($ids)) {
      return $queuedAttachments;
    }

    $tickets = $storage->loadMultiple($ids);
    $out = $queuedAttachments;

    foreach ($tickets as $ticket) {
      try {
        $pdf = $generator->getPdfContentForTicket($ticket);
        if (!is_array($pdf) || ($pdf['content'] ?? '') === '' || ($pdf['filename'] ?? '') === '') {
          continue;
        }
        $out[] = [
          'filename' => (string) $pdf['filename'],
          'content' => (string) $pdf['content'],
          'mime' => (string) ($pdf['mime'] ?? 'application/pdf'),
        ];
      }
      catch (\Throwable $e) {
        $this->logger->warning('Order confirmation: ticket PDF not attached for order @order_id: @message', [
          '@order_id' => (string) $orderId,
          '@message' => $e->getMessage(),
          'order_id' => $orderId,
        ]);
      }
    }

    return $out;
  }

}
