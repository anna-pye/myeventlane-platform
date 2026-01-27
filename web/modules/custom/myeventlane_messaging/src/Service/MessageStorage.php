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
   *   scheduled_for, status, attempts, created, sent.
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
        'scheduled_for' => (int) ($row['scheduled_for'] ?? 0),
        'status' => $row['status'] ?? 'queued',
        'attempts' => (int) ($row['attempts'] ?? 0),
        'created' => (int) ($row['created'] ?? 0),
        'sent' => (int) ($row['sent'] ?? 0),
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
   * Updates message status and related fields.
   *
   * @param string $id
   *   Message UUID.
   * @param array $updates
   *   Keys: status, attempts, sent, provider, provider_message_id.
   *
   * @return int
   *   Number of rows updated.
   */
  public function update(string $id, array $updates): int {
    $allowed = ['status', 'attempts', 'sent', 'provider', 'provider_message_id'];
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
   * Finds a message by provider message ID.
   *
   * @param string $providerMessageId
   *   The provider message ID.
   *
   * @return object|null
   *   Message row or NULL.
   */
  public function findByProviderMessageId(string $providerMessageId): ?object {
    $r = $this->connection->select('myeventlane_message', 'm')
      ->fields('m')
      ->condition('m.provider_message_id', $providerMessageId)
      ->execute()
      ->fetchObject();

    if (!$r) {
      return NULL;
    }
    $r->context = $r->context ? unserialize($r->context, ['allowed_classes' => FALSE]) : [];
    return $r;
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
