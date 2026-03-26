<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_improvement\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;

/**
 * Cron-driven aggregation from analytics and feedback tables.
 */
final class DocumentationOpportunityAggregationService {

  public function __construct(
    private readonly Connection $connection,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DocumentationOpportunityNormalizer $normalizer,
    private readonly DocumentationOpportunityCaptureService $capture,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
  ) {}

  public function runScheduledAggregation(): void {
    $now = $this->time->getCurrentTime();
    $this->aggregateRepeatedSearches($now);
    $this->aggregateLowHelpfulness($now);
  }

  private function aggregateRepeatedSearches(int $now): void {
    $cutoff = $now - (14 * 86400);
    $minCount = 4;
    try {
      $sql = "SELECT query, COUNT(*) AS c FROM {myeventlane_help_analytics}
        WHERE event_type = :search
        AND created >= :cutoff
        AND query IS NOT NULL AND query <> ''
        GROUP BY query
        HAVING c >= :min";
      $rows = $this->connection->query($sql, [
        ':search' => 'search',
        ':cutoff' => $cutoff,
        ':min' => $minCount,
      ])->fetchAll(\PDO::FETCH_ASSOC);
    }
    catch (\Throwable $e) {
      $this->logger->error('Repeated search aggregation query failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    foreach ($rows as $row) {
      $q = trim((string) ($row['query'] ?? ''));
      $c = (int) ($row['c'] ?? 0);
      if ($q === '' || $c < $minCount) {
        continue;
      }
      try {
        $this->capture->captureRepeatedSearch($q, $c, $now);
      }
      catch (\Throwable $e) {
        $this->logger->error('Repeated search opportunity failed for @q: @message', [
          '@q' => mb_substr($q, 0, 80),
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  private function aggregateLowHelpfulness(int $now): void {
    $cutoff = $now - (30 * 86400);
    $minUnhelpful = 3;
    try {
      $sql = "SELECT node_id,
        SUM(CASE WHEN helpful = 0 THEN 1 ELSE 0 END) AS unhelpful,
        SUM(CASE WHEN helpful = 1 THEN 1 ELSE 0 END) AS helpful
        FROM {myeventlane_help_feedback}
        WHERE created >= :cutoff
        GROUP BY node_id
        HAVING unhelpful >= :min_u AND unhelpful >= helpful";
      $rows = $this->connection->query($sql, [
        ':cutoff' => $cutoff,
        ':min_u' => $minUnhelpful,
      ])->fetchAll(\PDO::FETCH_ASSOC);
    }
    catch (\Throwable $e) {
      $this->logger->error('Low helpfulness aggregation query failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    foreach ($rows as $row) {
      $nid = (int) ($row['node_id'] ?? 0);
      $unhelpful = (int) ($row['unhelpful'] ?? 0);
      $helpful = (int) ($row['helpful'] ?? 0);
      if ($nid < 1 || $unhelpful < $minUnhelpful) {
        continue;
      }
      $node = $nodeStorage->load($nid);
      if (!$node instanceof NodeInterface || $node->bundle() !== 'help_article') {
        continue;
      }
      $title = $node->label();
      $audience = $this->resolveArticleAudience($node);
      try {
        $this->capture->captureLowHelpfulness($nid, $title, [
          'unhelpful' => $unhelpful,
          'helpful' => $helpful,
        ], $now, $audience);
      }
      catch (\Throwable $e) {
        $this->logger->error('Low helpfulness opportunity failed for nid @nid: @message', [
          '@nid' => (string) $nid,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  private function resolveArticleAudience(NodeInterface $node): string {
    if ($node->hasField('field_audience') && !$node->get('field_audience')->isEmpty()) {
      $vals = $node->get('field_audience')->getValue();
      $first = (string) ($vals[0]['value'] ?? '');
      return $this->normalizer->sanitizeAudience($first !== '' ? $first : 'unknown');
    }
    return 'unknown';
  }

}
