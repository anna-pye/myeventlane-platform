<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_help_centre\Event\HelpAnalyticsRecordedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Records Help Centre analytics events.
 */
final class HelpAnalyticsService {

  public function __construct(
    private readonly Connection $database,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * Logs a Help article view event.
   */
  public function logArticleView(int $nodeId): void {
    $this->write('view', $nodeId, NULL, NULL);
  }

  /**
   * Logs Help search usage and zero-result events.
   *
   * @param string|null $contextAudience
   *   Optional exposed-filter audience (public, vendor, staff) from Help search.
   */
  public function logSearch(string $query, int $resultCount, ?string $contextAudience = NULL): void {
    if ($query === '') {
      return;
    }
    $this->write('search', NULL, $query, $contextAudience);
    if ($resultCount === 0) {
      $this->write('zero_result', NULL, $query, $contextAudience);
    }
  }

  /**
   * Logs a Help feedback event.
   */
  public function logFeedback(int $nodeId, bool $helpful): void {
    $this->write('feedback', $nodeId, $helpful ? 'helpful=1' : 'helpful=0', NULL);
  }

  /**
   * Logs AI assistant usage events.
   *
   * @param int|null $responseTimeMs
   *   Round-trip AI time when applicable; omitted for retrieval-only paths.
   * @param string|null $contextAudience
   *   Optional audience hint when reliably known server-side.
   */
  public function logAiEvent(string $eventType, string $query, int $resultCount, string $confidence, ?int $responseTimeMs = NULL, ?string $contextAudience = NULL): void {
    if (!in_array($eventType, ['ai_query', 'ai_success', 'ai_low_confidence'], TRUE)) {
      $this->loggerFactory->get('myeventlane_help')
        ->error('Invalid AI event type provided: @event', ['@event' => $eventType]);
      return;
    }

    $cleanQuery = trim(preg_replace('/\s+/', ' ', strip_tags($query)) ?? '');
    if ($cleanQuery === '') {
      return;
    }

    $cleanConfidence = mb_strtolower(trim($confidence));
    if (!in_array($cleanConfidence, ['high', 'medium', 'low'], TRUE)) {
      $cleanConfidence = 'low';
    }

    $rtPart = $responseTimeMs !== NULL ? sprintf('|rt_ms=%d', max(0, min($responseTimeMs, 999999))) : '';
    $payload = sprintf(
      'query=%s|rc=%d|cf=%s%s',
      mb_substr($cleanQuery, 0, 200),
      max(0, $resultCount),
      $cleanConfidence,
      $rtPart,
    );
    if (mb_strlen($payload) > 500) {
      $payload = sprintf(
        'query=%s|rc=%d|cf=%s%s',
        mb_substr($cleanQuery, 0, 120),
        max(0, $resultCount),
        $cleanConfidence,
        $rtPart,
      );
    }
    $this->write($eventType, NULL, $payload, $contextAudience);
  }

  /**
   * Writes an analytics event to table and logger.
   */
  private function write(string $eventType, ?int $nodeId, ?string $query, ?string $contextAudience): void {
    $uid = $this->currentUser->isAuthenticated() ? (int) $this->currentUser->id() : NULL;
    $audience = $this->normaliseContextAudience($contextAudience);
    try {
      $this->database->insert('myeventlane_help_analytics')
        ->fields([
          'event_type' => $eventType,
          'node_id' => $nodeId,
          'query' => $query !== NULL ? mb_substr($query, 0, 512) : NULL,
          'created' => $this->time->getRequestTime(),
          'uid' => $uid,
          'context_audience' => $audience,
        ])
        ->execute();
    }
    catch (\Throwable $exception) {
      $this->loggerFactory->get('myeventlane_help')
        ->error('Failed analytics insert for @event: @message', [
          '@event' => $eventType,
          '@message' => $exception->getMessage(),
        ]);
      return;
    }

    $this->loggerFactory->get('myeventlane_help')
      ->notice('Help analytics event: @event nid=@nid query=@query', [
        '@event' => $eventType,
        '@nid' => $nodeId !== NULL ? (string) $nodeId : '-',
        '@query' => $query ?? '-',
      ]);

    $event = new HelpAnalyticsRecordedEvent(
      $eventType,
      $nodeId,
      $query,
      $audience,
      $uid,
      $this->time->getRequestTime(),
    );
    $this->eventDispatcher->dispatch($event, HelpAnalyticsRecordedEvent::NAME);
  }

  private function normaliseContextAudience(?string $contextAudience): ?string {
    if ($contextAudience === NULL || $contextAudience === '') {
      return NULL;
    }
    $v = mb_strtolower(trim($contextAudience));
    return in_array($v, ['public', 'vendor', 'staff'], TRUE) ? $v : NULL;
  }

}
