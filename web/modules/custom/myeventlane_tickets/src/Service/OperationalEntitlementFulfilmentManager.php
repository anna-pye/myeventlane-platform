<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_tickets\Entity\RedemptionLog;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Executes locked, forward-only organiser fulfilment transitions.
 */
final class OperationalEntitlementFulfilmentManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LockBackendInterface $lock,
    private readonly Connection $database,
    private readonly AccountProxyInterface $currentUser,
    private readonly RequestStack $requestStack,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @return list<array<string, mixed>>
   */
  public function buildRowsForOrderItem(int $order_item_id, int $event_id): array {
    $storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_item_id', $order_item_id)
      ->condition('event_id', $event_id)
      ->condition('entitlement_type', Ticket::ENTITLEMENT_TICKET, '<>')
      ->sort('id', 'ASC')
      ->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $ticket) {
      if (!$ticket instanceof Ticket) {
        continue;
      }
      $state = $ticket->getFulfilmentStatus();
      $code = (string) $ticket->get('ticket_code')->value;
      $rows[] = [
        'ticket_id' => (int) $ticket->id(),
        'ticket_uuid' => (string) $ticket->uuid(),
        'code_label' => $this->maskCode($code),
        'status' => $state,
        'status_label' => $this->statusLabel($state),
        'next_state' => $this->nextState($state),
        'next_label' => $this->nextActionLabel($state),
      ];
    }
    return $rows;
  }

  public function loadByCodeForEvent(string $code, int $event_id): ?Ticket {
    $ids = $this->entityTypeManager->getStorage('myeventlane_ticket')->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event_id)
      ->condition('ticket_code', trim($code))
      ->condition('entitlement_type', Ticket::ENTITLEMENT_TICKET, '<>')
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $ticket = $this->entityTypeManager->getStorage('myeventlane_ticket')->load((int) reset($ids));
    return $ticket instanceof Ticket ? $ticket : NULL;
  }

  public function loadByIdForEvent(int $ticket_id, int $event_id): ?Ticket {
    $ticket = $ticket_id > 0
      ? $this->entityTypeManager->getStorage('myeventlane_ticket')->load($ticket_id)
      : NULL;
    return $ticket instanceof Ticket
      && (int) $ticket->get('event_id')->target_id === $event_id
      && $ticket->getEntitlementType() !== Ticket::ENTITLEMENT_TICKET
        ? $ticket
        : NULL;
  }

  public function transition(Ticket $ticket, NodeInterface $event, string $target, string $reason = '', bool $manual_recovery = FALSE): Ticket {
    if ((int) $ticket->get('event_id')->target_id !== (int) $event->id() || $ticket->getEntitlementType() === Ticket::ENTITLEMENT_TICKET) {
      throw new \InvalidArgumentException('The entitlement does not belong to this event.');
    }
    $reason = trim($reason);
    if ($manual_recovery && mb_strlen($reason) < 10) {
      throw new \InvalidArgumentException('Manual recovery requires a clear reason of at least 10 characters.');
    }

    $lock_name = 'myeventlane_tickets:fulfilment:' . (int) $ticket->id();
    if (!$this->lock->acquire($lock_name, 10.0)) {
      throw new \RuntimeException('This pass is being updated by another staff member. Try again.');
    }

    try {
      $fresh = $this->entityTypeManager->getStorage('myeventlane_ticket')->loadUnchanged((int) $ticket->id());
      if (!$fresh instanceof Ticket) {
        throw new \RuntimeException('The entitlement could not be reloaded.');
      }
      $from = $fresh->getFulfilmentStatus();
      $this->assertTransitionAllowed($fresh, $from, $target, $manual_recovery);

      $transaction = $this->database->startTransaction();
      try {
        $fresh->set('fulfilment_status', $target);
        if ($target === Ticket::FULFILMENT_COLLECTED) {
          $fresh->set('redemption_count', min($fresh->getRedemptionLimit(), max(1, $fresh->getRedemptionCount())));
        }
        $fresh->save();
        $this->writeAudit($fresh, $from, $target, $reason, $manual_recovery);
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      return $fresh;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  public function statusLabel(string $state): string {
    return match ($state) {
      Ticket::FULFILMENT_PREPARING => 'Preparing',
      Ticket::FULFILMENT_READY => 'Ready to collect',
      Ticket::FULFILMENT_COLLECTED => 'Collected',
      Ticket::FULFILMENT_REDEEMED => 'Redeemed',
      Ticket::FULFILMENT_CANCELLED => 'Cancelled',
      Ticket::FULFILMENT_EXPIRED => 'Expired',
      default => 'Order received',
    };
  }

  public function nextState(string $state): ?string {
    return match ($state) {
      Ticket::FULFILMENT_PENDING => Ticket::FULFILMENT_PREPARING,
      Ticket::FULFILMENT_PREPARING => Ticket::FULFILMENT_READY,
      Ticket::FULFILMENT_READY => Ticket::FULFILMENT_COLLECTED,
      default => NULL,
    };
  }

  public function nextActionLabel(string $state): string {
    return match ($state) {
      Ticket::FULFILMENT_PENDING => 'Start preparing',
      Ticket::FULFILMENT_PREPARING => 'Mark ready',
      Ticket::FULFILMENT_READY => 'Mark collected',
      default => '',
    };
  }

  private function assertTransitionAllowed(Ticket $ticket, string $from, string $target, bool $manual): void {
    if (in_array((string) $ticket->get('status')->value, [Ticket::STATUS_VOID, Ticket::STATUS_REFUNDED], TRUE)) {
      throw new \InvalidArgumentException('Cancelled or refunded entitlements cannot be fulfilled.');
    }
    if ($manual) {
      if (!in_array($target, [Ticket::FULFILMENT_READY, Ticket::FULFILMENT_COLLECTED], TRUE)
        || in_array($from, [Ticket::FULFILMENT_COLLECTED, Ticket::FULFILMENT_REDEEMED, Ticket::FULFILMENT_CANCELLED, Ticket::FULFILMENT_EXPIRED], TRUE)) {
        throw new \InvalidArgumentException('That manual recovery change is not allowed.');
      }
      return;
    }
    if ($this->nextState($from) !== $target) {
      throw new \InvalidArgumentException('Fulfilment steps must move forward in order.');
    }
  }

  private function writeAudit(Ticket $ticket, string $from, string $target, string $reason, bool $manual): void {
    $action = $manual ? RedemptionLog::ACTION_RECOVER : match ($target) {
      Ticket::FULFILMENT_PREPARING => RedemptionLog::ACTION_PREPARE,
      Ticket::FULFILMENT_READY => RedemptionLog::ACTION_READY,
      default => RedemptionLog::ACTION_COLLECT,
    };
    try {
      $this->entityTypeManager->getStorage('mel_redemption_log')->create([
        'ticket_id' => (int) $ticket->id(),
        'entitlement_type' => $ticket->getEntitlementType(),
        'staff_uid' => (int) $this->currentUser->id() ?: NULL,
        'vendor_id' => $this->resolveVendorId($ticket),
        'event_id' => (int) $ticket->get('event_id')->target_id,
        'action_type' => $action,
        'device_identifier' => $manual ? 'manual-recovery' : 'organiser-console',
        'ip_address' => $this->requestStack->getCurrentRequest()?->getClientIp(),
        'notes' => $manual ? $reason : $this->statusLabel($target),
        'metadata_json' => [
          'source_state' => $from,
          'target_state' => $target,
          'manual_recovery' => $manual,
          'changed_at' => $this->time->getCurrentTime(),
        ],
      ])->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Fulfilment audit failed for entitlement @id: @message', [
        '@id' => (string) $ticket->id(),
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('The update was not saved because its audit record could not be written.', 0, $e);
    }
  }

  private function resolveVendorId(Ticket $ticket): ?int {
    if (!$ticket->get('vendor_reference')->isEmpty()) {
      return (int) $ticket->get('vendor_reference')->target_id;
    }
    $event = $ticket->get('event_id')->entity;
    return $event instanceof NodeInterface && $event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()
      ? (int) $event->get('field_event_vendor')->target_id
      : NULL;
  }

  private function maskCode(string $code): string {
    $suffix = mb_substr($code, -6);
    return '•••• ' . $suffix;
  }

}
