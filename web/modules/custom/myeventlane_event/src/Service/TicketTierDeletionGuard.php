<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;

/**
 * Evaluates whether a ticket type can be permanently deleted safely.
 */
final class TicketTierDeletionGuard {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * @return array{
   *   allowed: bool,
   *   blockers: list<string>,
   *   counts: array<string, int>
   * }
   */
  public function evaluate(TicketTypeInterface $ticket): array {
    $ticket_id = (int) $ticket->id();
    $counts = [
      'order_items' => 0,
      'issued_tickets' => 0,
      'waitlist_entries' => 0,
      'access_codes' => 0,
    ];
    $blockers = [];

    try {
      $variation_id = $ticket->hasField('commerce_variation') && !$ticket->get('commerce_variation')->isEmpty()
        ? (int) $ticket->get('commerce_variation')->target_id
        : 0;
      if ($variation_id > 0) {
        $counts['order_items'] = $this->countReferences(
          'commerce_order_item',
          'purchased_entity',
          $variation_id,
        );
      }

      $counts['issued_tickets'] = $this->countReferences(
        'myeventlane_ticket',
        'mel_ticket_type',
        $ticket_id,
      );
      $counts['waitlist_entries'] = $this->countReferences(
        'mel_ticket_waitlist_entry',
        'ticket_type',
        $ticket_id,
      );
      $counts['access_codes'] = $this->countReferences(
        'mel_access_code',
        'allowed_ticket_types',
        $ticket_id,
      );
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'Ticket deletion eligibility inspection failed for ticket @tid: @message',
        [
          '@tid' => (string) $ticket_id,
          '@message' => $e->getMessage(),
        ],
      );
      return [
        'allowed' => FALSE,
        'blockers' => ['inspection_failed'],
        'counts' => $counts,
      ];
    }

    foreach ($counts as $reference => $count) {
      if ($count > 0) {
        $blockers[] = $reference;
      }
    }

    return [
      'allowed' => $blockers === [],
      'blockers' => $blockers,
      'counts' => $counts,
    ];
  }

  private function countReferences(string $entity_type_id, string $field_name, int $target_id): int {
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      throw new \RuntimeException(sprintf(
        'Required deletion reference entity type "%s" is unavailable.',
        $entity_type_id,
      ));
    }

    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    return (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($field_name, $target_id)
      ->count()
      ->execute();
  }

}
