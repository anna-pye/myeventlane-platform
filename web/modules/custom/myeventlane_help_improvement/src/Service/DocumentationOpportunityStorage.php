<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_improvement\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Persistence for mel_doc_opportunity rows.
 */
final class DocumentationOpportunityStorage {

  public const SOURCE_ZERO_RESULTS = 'zero_results_search';

  public const SOURCE_REPEATED_SEARCH = 'repeated_search';

  public const SOURCE_LOW_HELPFULNESS = 'low_helpfulness';

  public const SOURCE_ASSISTANT_NO_ANSWER = 'assistant_no_answer';

  public const SOURCE_ASSISTANT_LOW_CONFIDENCE = 'assistant_low_confidence';

  public const STATUS_NEW = 'new';

  public const STATUS_REVIEWING = 'reviewing';

  public const STATUS_DRAFTED = 'drafted';

  public const STATUS_RESOLVED = 'resolved';

  public const STATUS_IGNORED = 'ignored';

  public function __construct(
    private readonly Connection $connection,
    private readonly LoggerChannelInterface $logger,
    private readonly DocumentationOpportunityNormalizer $normalizer,
  ) {}

  /**
   * @return array<string, mixed>|null
   */
  public function load(int $id): ?array {
    $row = $this->connection->select('mel_doc_opportunity', 'o')
      ->fields('o')
      ->condition('id', $id)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * @param array<string, mixed> $fields
   */
  public function update(int $id, array $fields): void {
    unset($fields['id'], $fields['dedupe_key']);
    if ($fields === []) {
      return;
    }
    try {
      $this->connection->update('mel_doc_opportunity')
        ->fields($fields)
        ->condition('id', $id)
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to update documentation opportunity @id: @message', [
        '@id' => (string) $id,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Upserts by dedupe key: increments frequency when the row already exists.
   *
   * @param array<string, mixed> $record
   *   Must include dedupe_key and required insert fields.
   */
  public function upsert(array $record): void {
    $dedupe = (string) ($record['dedupe_key'] ?? '');
    if ($dedupe === '') {
      $this->logger->error('Documentation opportunity upsert missing dedupe_key.');
      return;
    }

    $existingId = $this->connection->select('mel_doc_opportunity', 'o')
      ->fields('o', ['id'])
      ->condition('dedupe_key', $dedupe)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $now = (int) ($record['last_seen'] ?? time());

    if ($existingId) {
      $existingRow = $this->load((int) $existingId);
      if ($existingRow === NULL) {
        return;
      }
      $prevQuery = (string) ($existingRow['query_text'] ?? '');
      $incomingQuery = (string) ($record['query_text'] ?? '');
      $chosenQuery = mb_strlen($incomingQuery) > mb_strlen($prevQuery) ? $incomingQuery : $prevQuery;
      $targetFrequency = $record['target_frequency'] ?? NULL;
      if ($targetFrequency !== NULL) {
        $newFreq = max((int) $existingRow['frequency_count'], (int) $targetFrequency);
      }
      else {
        $newFreq = (int) $existingRow['frequency_count'] + 1;
      }
      try {
        $this->connection->update('mel_doc_opportunity')
          ->fields([
            'frequency_count' => $newFreq,
            'last_seen' => $now,
            'query_text' => mb_substr($chosenQuery, 0, 512),
          ])
          ->condition('id', (int) $existingId)
          ->execute();
      }
      catch (\Throwable $e) {
        $this->logger->error('Documentation opportunity frequency update failed: @message', [
          '@message' => $e->getMessage(),
        ]);
        throw $e;
      }
      return;
    }

    try {
      $insertTargetFrequency = $record['target_frequency'] ?? NULL;
      $initialFreq = $insertTargetFrequency !== NULL
        ? max(1, (int) $insertTargetFrequency)
        : max(1, (int) ($record['frequency_count'] ?? 1));
      $this->connection->insert('mel_doc_opportunity')
        ->fields([
          'source_type' => (string) $record['source_type'],
          'audience' => $this->normalizer->sanitizeAudience((string) ($record['audience'] ?? 'unknown')),
          'query_text' => mb_substr((string) ($record['query_text'] ?? ''), 0, 512),
          'normalized_topic' => mb_substr((string) ($record['normalized_topic'] ?? ''), 0, 255),
          'suggested_title' => mb_substr((string) ($record['suggested_title'] ?? ''), 0, 512),
          'suggested_article_type' => mb_substr((string) ($record['suggested_article_type'] ?? 'help_article'), 0, 128),
          'frequency_count' => $initialFreq,
          'first_seen' => (int) ($record['first_seen'] ?? $now),
          'last_seen' => $now,
          'status' => (string) ($record['status'] ?? self::STATUS_NEW),
          'linked_help_article_nid' => $record['linked_help_article_nid'] !== NULL ? (int) $record['linked_help_article_nid'] : NULL,
          'notes' => $record['notes'] !== NULL ? (string) $record['notes'] : NULL,
          'dedupe_key' => $dedupe,
          'metadata' => $record['metadata'] !== NULL ? (string) $record['metadata'] : NULL,
        ])
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->error('Documentation opportunity insert failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function listing(?string $status, ?string $source, ?string $audience, int $limit, int $offset): array {
    $q = $this->connection->select('mel_doc_opportunity', 'o')->fields('o');
    if ($status !== NULL && $status !== '' && $status !== 'all') {
      $q->condition('status', $status);
    }
    if ($source !== NULL && $source !== '' && $source !== 'all') {
      $q->condition('source_type', $source);
    }
    if ($audience !== NULL && $audience !== '' && $audience !== 'all') {
      $q->condition('audience', $audience);
    }
    $q->orderBy('last_seen', 'DESC');
    $q->range($offset, $limit);
    return $q->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function listingCount(?string $status, ?string $source, ?string $audience): int {
    $q = $this->connection->select('mel_doc_opportunity', 'o');
    $q->addExpression('COUNT(*)', 'c');
    if ($status !== NULL && $status !== '' && $status !== 'all') {
      $q->condition('status', $status);
    }
    if ($source !== NULL && $source !== '' && $source !== 'all') {
      $q->condition('source_type', $source);
    }
    if ($audience !== NULL && $audience !== '' && $audience !== 'all') {
      $q->condition('audience', $audience);
    }
    return (int) $q->execute()->fetchField();
  }

  /**
   * Open items: new, reviewing, or drafted (not yet resolved).
   */
  public function countOpen(): int {
    $q = $this->connection->select('mel_doc_opportunity', 'o');
    $q->addExpression('COUNT(*)', 'c');
    $q->condition('status', [
      self::STATUS_NEW,
      self::STATUS_REVIEWING,
      self::STATUS_DRAFTED,
    ], 'IN');
    return (int) $q->execute()->fetchField();
  }

  /**
   * @return array<string, mixed>|null
   */
  public function decodeMetadata(?string $json): ?array {
    if ($json === NULL || $json === '') {
      return NULL;
    }
    try {
      $data = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
      return is_array($data) ? $data : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * @param array<string, mixed> $data
   */
  public function encodeMetadata(array $data): string {
    return json_encode($data, JSON_THROW_ON_ERROR);
  }

}
