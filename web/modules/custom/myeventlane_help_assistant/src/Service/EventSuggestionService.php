<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Service;

use Drupal\node\NodeInterface;

/**
 * Orchestrates event wizard suggestions, quality score, and API payload shaping.
 */
final class EventSuggestionService {

  public function __construct(
    private readonly EventSuggestionEngine $eventSuggestionEngine,
    private readonly EventSuggestionFormatter $eventSuggestionFormatter,
  ) {}

  /**
   * Full wizard insight payload: score, copy, grouped suggestions (capped).
   *
   * @param array<string, mixed> $values
   *   Live wizard values (field_event_type, body, field_capacity, ticket_prices, ...).
   * @param \Drupal\node\NodeInterface|null $event
   *   Event node for persisted fields, or null (access-checked at load time).
   *
   * @return array<string, mixed>
   *   score (int), score_*, groups, and suggestions keys as consumed by the panel API.
   */
  public function buildWizardInsights(array $values, ?NodeInterface $event): array {
    $top = $this->eventSuggestionEngine->buildPreparedTopRows($values, $event);
    $score = $this->eventSuggestionEngine->computeScoreValue($values, $event);
    $scoreData = $this->eventSuggestionFormatter->formatScorePresentation($score);
    return $this->eventSuggestionFormatter->buildFullWizardPayload($scoreData, $top);
  }

  /**
   * Backward-compatible flat suggestion list (top 3, formatted for JSON).
   *
   * @param array<string, mixed> $values
   *
   * @return list<array<string, mixed>>
   */
  public function getSuggestions(array $values, ?NodeInterface $event): array {
    return $this->buildWizardInsights($values, $event)['suggestions'];
  }

}
