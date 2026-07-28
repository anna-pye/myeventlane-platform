<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\Core\Database\Connection;

/**
 * Storage for myeventlane_message records (source of truth for idempotency).
 */
final class MessageStorage {

  /**
   * Constructs MessageStorage.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * Creates a message record and returns its ID.
   *
   * @param array $row
   *   Keys: template, channel, recipient, langcode, context (array), context_hash,
   *   scheduled_for, status, attempts, created, sent, claimed_at, provider,
   *   provider_message_id.
   *
   * @return string
   *   The message UUID.
   *
   * @throws \Exception
   *   If insert fails.
   */
  public function create(array $row): string {
    $id = $row['id'] ?? $this->uuid();
    $context = $row['context'] ?? [];
    $serialized = is_string($context) ? $context : serialize($context);

    $this->connection->insert('myeventlane_message')
      ->fields([
        'id' => $id,
        'template' => $row['template'] ?? '',
        'channel' => $row['channel'] ?? 'email',
        'recipient' => $row['recipient'] ?? '',
        'langcode' => $row['langcode'] ?? 'en',
        'context' => $serialized,
        'context_hash' => $row['context_hash'] ?? '',
        'idempotency_key' => $row['idempotency_key'] ?? NULL,
        'scheduled_for' => (int) ($row['scheduled_for'] ?? 0),
        'status' => $row['status'] ?? 'queued',
        'attempts' => (int) ($row['attempts'] ?? 0),
        'created' => (int) ($row['created'] ?? 0),
        'sent' => (int) ($row['sent'] ?? 0),
        'claimed_at' => (int) ($row['claimed_at'] ?? 0),
        'provider' => $row['provider'] ?? '',
        'provider_message_id' => $row['provider_message_id'] ?? '',
      ])
      ->execute();

    return $id;
  }

  /**
   * Loads a message by ID.
   *
   * @param string $id
   *   Message UUID.
   *
   * @return object|null
   *   StdClass with all columns; context as unserialized array. NULL if not found.
   */
  public function load(string $id): ?object {
    $r = $this->connection->select('myeventlane_message', 'm')
      ->fields('m')
      ->condition('m.id', $id)
      ->execute()
      ->fetchObject();

    if (!$r) {
      return NULL;
    }
    $r->context = $r->context ? unserialize($r->context, ['allowed_classes' => FALSE]) : [];
    return $r;
  }

  /**
   * Finds an existing message by idempotency key (context_hash + recipient + template).
   *
   * @param string $contextHash
   *   Deterministic context hash.
   * @param string $recipient
   *   Recipient email/address.
   * @param string $template
   *   Template key.
   * @param string[] $statuses
   *   Statuses to consider as "already exists" (e.g. queued, sent).
   *
   * @return object|null
   *   Existing message row or NULL.
   */
  public function findByContextHash(
    string $contextHash,
    string $recipient,
    string $template,
    array $statuses = ['queued', 'sent'],
  ): ?object {
    $q = $this->connection->select('myeventlane_message', 'm')
      ->fields('m')
      ->condition('m.context_hash', $contextHash)
      ->condition('m.recipient', $recipient)
      ->condition('m.template', $template)
      ->condition('m.status', $statuses, 'IN');
    $r = $q->execute()->fetchObject();
    if (!$r) {
      return NULL;
    }
    $r->context = $r->context ? unserialize($r->context, ['allowed_classes' => FALSE]) : [];
    return $r;
  }

  /**
   * Finds a message by its business idempotency key.
   */
  public function findByIdempotencyKey(string $idempotencyKey): ?object {
    if ($idempotencyKey === '') {
      return NULL;
    }
    $row = $this->connection->select('myeventlane_message', 'm')
      ->fields('m')
      ->condition('m.idempotency_key', $idempotencyKey)
      ->execute()
      ->fetchObject();
    if (!$row) {
      return NULL;
    }
    $row->context = $row->context
      ? unserialize($row->context, ['allowed_classes' => FALSE])
      : [];
    return $row;
  }

  /**
   * Atomically claims a queued or retryable message for one worker.
   */
  public function claimForDelivery(string $id, int $claimedAt): bool {
    return $this->connection->update('myeventlane_message')
      ->fields([
        'status' => 'processing',
        'claimed_at' => $claimedAt,
      ])
      ->condition('id', $id)
      ->condition('status', ['queued', 'failed'], 'IN')
      ->execute() === 1;
  }

  /**
   * Atomically renews an abandoned pre-dispatch processing claim.
   */
  public function reclaimStaleProcessing(
    string $id,
    int $staleBefore,
    int $claimedAt,
  ): bool {
    return $this->connection->update('myeventlane_message')
      ->fields(['claimed_at' => $claimedAt])
      ->condition('id', $id)
      ->condition('status', 'processing')
      ->condition('claimed_at', $staleBefore, '<=')
      ->execute() === 1;
  }

  /**
   * Atomically records that provider dispatch is about to begin.
   */
  public function markDispatching(string $id, int $claimedAt): bool {
    return $this->connection->update('myeventlane_message')
      ->fields([
        'status' => 'dispatching',
        'claimed_at' => $claimedAt,
      ])
      ->condition('id', $id)
      ->condition('status', 'processing')
      ->execute() === 1;
  }

  /**
   * Quarantines an abandoned provider dispatch for reconciliation.
   */
  public function markStaleDispatchUnknown(string $id, int $staleBefore): bool {
    return $this->connection->update('myeventlane_message')
      ->fields([
        'status' => 'delivery_unknown',
        'claimed_at' => 0,
      ])
      ->condition('id', $id)
      ->condition('status', 'dispatching')
      ->condition('claimed_at', $staleBefore, '<=')
      ->execute() === 1;
  }

  /**
   * Releases a processing claim after an unhandled worker failure.
   */
  public function releaseFailedClaim(string $id): bool {
    return $this->connection->update('myeventlane_message')
      ->fields([
        'status' => 'failed',
        'claimed_at' => 0,
      ])
      ->condition('id', $id)
      ->condition('status', 'processing')
      ->execute() === 1;
  }

  /**
   * Finds a message by provider-assigned message ID.
   *
   * @param string $messageId
   *   Provider message identifier (e.g. Postmark MessageID).
   *
   * @return object|null
   *   StdClass with all columns; context as unserialized array. NULL if not found.
   */
  public function findByProviderMessageId(string $messageId): ?object {
    if ($messageId === '') {
      return NULL;
    }
    $r = $this->connection->select('myeventlane_message', 'm')
      ->fields('m')
      ->condition('m.provider_message_id', $messageId)
      ->execute()
      ->fetchObject();

    if (!$r) {
      return NULL;
    }
    $r->context = $r->context ? unserialize($r->context, ['allowed_classes' => FALSE]) : [];
    return $r;
  }

  /**
   * Sent order_confirmation rows for a commerce order (recipient + serialized order_id).
   *
   * @return object[]
   *   Rows with context as array; ordered by sent ascending (0 first).
   */
  public function findSentOrderConfirmationsForOrder(int $orderId, string $recipient): array {
    $recipient = strtolower(trim($recipient));
    if ($orderId < 1 || $recipient === '') {
      return [];
    }
    $serializedOrderIdMarker = 's:8:"order_id";i:' . $orderId . ';';
    $q = $this->connection->select('myeventlane_message', 'm')
      ->fields('m')
      ->condition('m.template', 'order_confirmation')
      ->condition('m.recipient', $recipient)
      ->condition('m.status', 'sent')
      ->condition('m.context', '%' . $this->connection->escapeLike($serializedOrderIdMarker) . '%', 'LIKE')
      ->orderBy('m.sent', 'ASC')
      ->orderBy('m.created', 'ASC');

    $rows = $q->execute()->fetchAll();
    $out = [];
    foreach ($rows as $r) {
      $r->context = $r->context ? unserialize($r->context, ['allowed_classes' => FALSE]) : [];
      $out[] = $r;
    }
    return $out;
  }

  /**
   * Updates message status and related fields.
   *
   * @param string $id
   *   Message UUID.
   * @param array $updates
   *   Keys: status, attempts, sent, claimed_at, provider, provider_message_id.
   *
   * @return int
   *   Number of rows updated.
   */
  public function update(string $id, array $updates): int {
    $allowed = [
      'status',
      'attempts',
      'sent',
      'claimed_at',
      'provider',
      'provider_message_id',
    ];
    $fields = array_intersect_key($updates, array_flip($allowed));
    if (empty($fields)) {
      return 0;
    }
    return $this->connection->update('myeventlane_message')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Increments attempts for a message (safe atomic update).
   *
   * @param string $id
   *   Message UUID.
   *
   * @return int
   *   New attempts count, or 0 if row not found.
   */
  public function incrementAttempts(string $id): int {
    $this->connection->query(
      'UPDATE {myeventlane_message} SET attempts = attempts + 1 WHERE id = :id',
      [':id' => $id]
    );
    $r = $this->connection->select('myeventlane_message', 'm')
      ->fields('m', ['attempts'])
      ->condition('m.id', $id)
      ->execute()
      ->fetchObject();
    return $r ? (int) $r->attempts : 0;
  }

  /**
   * Generates a UUID v4.
   */
  private function uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
  }

}
