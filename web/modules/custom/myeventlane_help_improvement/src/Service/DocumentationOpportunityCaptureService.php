<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_improvement\Service;

use Drupal\myeventlane_help_centre\Event\HelpAnalyticsRecordedEvent;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Maps analytics events into documentation opportunities.
 */
final class DocumentationOpportunityCaptureService {

  public function __construct(
    private readonly DocumentationOpportunityStorage $storage,
    private readonly DocumentationOpportunityNormalizer $normalizer,
    private readonly LoggerChannelInterface $logger,
  ) {}

  public function recordFromAnalyticsEvent(HelpAnalyticsRecordedEvent $event): void {
    try {
      if ($event->eventType === 'zero_result') {
        $this->captureZeroResultSearch($event);
        return;
      }
      if ($event->eventType === 'ai_low_confidence') {
        $this->captureAssistantLowSignal($event);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Opportunity capture failed for @type: @message', [
        '@type' => $event->eventType,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  private function captureZeroResultSearch(HelpAnalyticsRecordedEvent $event): void {
    $query = trim((string) ($event->query ?? ''));
    if ($query === '') {
      return;
    }
    $audience = $this->normalizer->mapAnalyticsAudience($event->contextAudience);
    $normalized = $this->normalizer->normalizeTopic($query);
    if ($normalized === '') {
      return;
    }
    $dedupe = hash('sha256', DocumentationOpportunityStorage::SOURCE_ZERO_RESULTS . '|' . $audience . '|' . $normalized);
    $this->storage->upsert([
      'dedupe_key' => $dedupe,
      'source_type' => DocumentationOpportunityStorage::SOURCE_ZERO_RESULTS,
      'audience' => $audience,
      'query_text' => $query,
      'normalized_topic' => $normalized,
      'suggested_title' => $this->normalizer->suggestTitleFromQuery($query),
      'suggested_article_type' => 'help_article',
      'frequency_count' => 1,
      'first_seen' => $event->created,
      'last_seen' => $event->created,
      'status' => DocumentationOpportunityStorage::STATUS_NEW,
      'linked_help_article_nid' => NULL,
      'notes' => NULL,
      'metadata' => NULL,
    ]);
  }

  private function captureAssistantLowSignal(HelpAnalyticsRecordedEvent $event): void {
    $parsed = $this->normalizer->parseAiAnalyticsPayload($event->query);
    $query = trim($parsed['query']);
    if ($query === '') {
      return;
    }
    $audience = $this->normalizer->mapAnalyticsAudience($event->contextAudience);
    $normalized = $this->normalizer->normalizeTopic($query);
    if ($normalized === '') {
      return;
    }
    $rc = (int) $parsed['result_count'];
    $source = $rc === 0
      ? DocumentationOpportunityStorage::SOURCE_ASSISTANT_NO_ANSWER
      : DocumentationOpportunityStorage::SOURCE_ASSISTANT_LOW_CONFIDENCE;
    $dedupe = hash('sha256', $source . '|' . $audience . '|' . $normalized);
    $this->storage->upsert([
      'dedupe_key' => $dedupe,
      'source_type' => $source,
      'audience' => $audience,
      'query_text' => $query,
      'normalized_topic' => $normalized,
      'suggested_title' => $this->normalizer->suggestTitleFromQuery($query),
      'suggested_article_type' => 'help_article',
      'frequency_count' => 1,
      'first_seen' => $event->created,
      'last_seen' => $event->created,
      'status' => DocumentationOpportunityStorage::STATUS_NEW,
      'linked_help_article_nid' => NULL,
      'notes' => NULL,
      'metadata' => json_encode([
        'assistant_result_count' => $rc,
        'captured_from' => 'ai_low_confidence',
      ], JSON_THROW_ON_ERROR),
    ]);
  }

  /**
   * Called from cron aggregation for repeated searches.
   */
  public function captureRepeatedSearch(string $query, int $frequency, int $now): void {
    $query = trim($query);
    if ($query === '') {
      return;
    }
    $audience = 'unknown';
    $normalized = $this->normalizer->normalizeTopic($query);
    if ($normalized === '') {
      return;
    }
    $dedupe = hash('sha256', DocumentationOpportunityStorage::SOURCE_REPEATED_SEARCH . '|' . $audience . '|' . $normalized);
    $this->storage->upsert([
      'dedupe_key' => $dedupe,
      'source_type' => DocumentationOpportunityStorage::SOURCE_REPEATED_SEARCH,
      'audience' => $audience,
      'query_text' => $query,
      'normalized_topic' => $normalized,
      'suggested_title' => $this->normalizer->suggestTitleFromQuery($query),
      'suggested_article_type' => 'help_article',
      'target_frequency' => max(1, $frequency),
      'frequency_count' => max(1, $frequency),
      'first_seen' => $now,
      'last_seen' => $now,
      'status' => DocumentationOpportunityStorage::STATUS_NEW,
      'linked_help_article_nid' => NULL,
      'notes' => NULL,
      'metadata' => json_encode(['aggregation' => 'cron_repeated_search'], JSON_THROW_ON_ERROR),
    ]);
  }

  /**
   * Suggests revision when an article collects disproportionate unhelpful votes.
   *
   * @param array{unhelpful: int, helpful: int} $counts
   */
  public function captureLowHelpfulness(int $nid, string $title, array $counts, int $now, string $audience): void {
    $normalized = 'article:' . $nid;
    $dedupe = hash('sha256', DocumentationOpportunityStorage::SOURCE_LOW_HELPFULNESS . '|' . $audience . '|' . $normalized);
    $summary = sprintf('Article nid %d received %d unhelpful vs %d helpful feedback in the review window.', $nid, $counts['unhelpful'], $counts['helpful']);
    $this->storage->upsert([
      'dedupe_key' => $dedupe,
      'source_type' => DocumentationOpportunityStorage::SOURCE_LOW_HELPFULNESS,
      'audience' => $audience,
      'query_text' => mb_substr($title, 0, 512),
      'normalized_topic' => $normalized,
      'suggested_title' => 'Revise: ' . mb_substr($title, 0, 180),
      'suggested_article_type' => 'revision',
      'target_frequency' => max(1, (int) $counts['unhelpful']),
      'frequency_count' => max(1, (int) $counts['unhelpful']),
      'first_seen' => $now,
      'last_seen' => $now,
      'status' => DocumentationOpportunityStorage::STATUS_NEW,
      'linked_help_article_nid' => $nid,
      'notes' => $summary,
      'metadata' => json_encode([
        'unhelpful' => $counts['unhelpful'],
        'helpful' => $counts['helpful'],
      ], JSON_THROW_ON_ERROR),
    ]);
  }

}
