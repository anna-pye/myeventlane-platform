<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Shapes event suggestion rows and quality score for the wizard panel and JSON API.
 */
final class EventSuggestionFormatter {

  use StringTranslationTrait;

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Clamps, labels, and summarises a quality score for the wizard payload.
   *
   * @return array{score: int, score_label: string, score_summary: string, score_band: string}
   *   Presentation fields for groups/suggestions.
   */
  public function formatScorePresentation(int $score): array {
    $score = max(0, min(100, $score));
    $band = $this->mapScoreBand($score);
    $label = $this->mapScoreLabel($score);
    return [
      'score' => $score,
      'score_label' => $label,
      'score_summary' => $this->buildScoreSummaryText($score),
      'score_band' => $band,
    ];
  }

  /**
   * Builds the full response array for the wizard panel and JSON consumers.
   *
   * @param array{score: int, score_label: string, score_summary: string, score_band: string} $scoreData
   *   Clamped, labelled score output from formatScorePresentation().
   * @param list<array<string, mixed>> $top
   *   Engine row arrays after dedupe, sort, optional AI, and cap.
   *
   * @return array<string, mixed>
   *   Same keys and nesting as the legacy EventSuggestionService::buildWizardInsights.
   */
  public function buildFullWizardPayload(array $scoreData, array $top): array {
    $groups = [
      'critical' => [],
      'revenue' => [],
      'tips' => [],
    ];
    foreach ($top as $row) {
      $g = (string) ($row['group'] ?? 'tips');
      if (!isset($groups[$g])) {
        $g = 'tips';
      }
      $groups[$g][] = $this->formatSuggestionForApi($row);
    }

    $flat = [];
    foreach ($top as $row) {
      $flat[] = $this->formatSuggestionForApi($row);
    }

    return [
      'score' => $scoreData['score'],
      'score_label' => $scoreData['score_label'],
      'score_summary' => $scoreData['score_summary'],
      'score_band' => $scoreData['score_band'],
      'groups' => $groups,
      'suggestions' => $flat,
    ];
  }

  /**
   * Maps a numeric quality score to a machine-readable band.
   */
  private function mapScoreBand(int $score): string {
    if ($score >= 90) {
      return 'ready_to_shine';
    }
    if ($score >= 75) {
      return 'looking_good';
    }
    if ($score >= 55) {
      return 'needs_improvements';
    }
    return 'needs_attention';
  }

  /**
   * Maps a quality score to the UI label (translated).
   */
  private function mapScoreLabel(int $score): string {
    if ($score >= 90) {
      return (string) $this->t('Ready to shine');
    }
    if ($score >= 75) {
      return (string) $this->t('Looking good');
    }
    if ($score >= 55) {
      return (string) $this->t('Needs a few improvements');
    }
    return (string) $this->t('Needs attention');
  }

  /**
   * Maps a quality score to the help paragraph under the label.
   */
  private function buildScoreSummaryText(int $score): string {
    if ($score >= 90) {
      return (string) $this->t('Your event is looking great. Everything’s in place for a strong launch.');
    }
    if ($score >= 75) {
      return (string) $this->t('Your event is shaping up well. A few small tweaks could help more people discover it.');
    }
    if ($score >= 55) {
      return (string) $this->t('You’re on the right track. A couple of key improvements will make your event clearer and more appealing.');
    }
    return (string) $this->t('There are a few important details missing. Fixing these will help your event perform much better.');
  }

  /**
   * Normalises an internal row for JSON (ids, type, group, action shape).
   *
   * @param array<string, mixed> $row
   *   Internal engine row.
   *
   * @return array<string, mixed>
   *   API row as previously returned by the monolith.
   */
  public function formatSuggestionForApi(array $row): array {
    $id = (string) ($row['id'] ?? '');
    $out = [
      'id' => $id,
      'type' => (string) ($row['type'] ?? 'info'),
      'group' => (string) ($row['group'] ?? 'tips'),
      'title' => (string) ($row['title'] ?? $this->titleForSuggestionId($id)),
      'message' => (string) ($row['message'] ?? ''),
    ];
    if (!empty($row['action']) && is_array($row['action'])) {
      $normalized = $this->normalizeActionForApi($row['action']);
      if ($normalized !== NULL) {
        $out['action'] = $normalized;
      }
    }
    elseif (!empty($row['cta']) && is_array($row['cta'])) {
      $label = trim((string) ($row['cta']['label'] ?? ''));
      $url = trim((string) ($row['cta']['url'] ?? ''));
      if ($label !== '' && $url !== '') {
        $out['action'] = [
          'type' => 'link',
          'label' => $label,
          'url' => $url,
        ];
      }
    }
    return $out;
  }

  /**
   * Picks a display title for a known suggestion id.
   */
  private function titleForSuggestionId(string $id): string {
    return match ($id) {
      'ticket_paragraphs_missing' => (string) $this->t('Add your ticket types'),
      'missing_ticket_product' => (string) $this->t('Add your ticket types'),
      'ticket_no_limits' => (string) $this->t('Set ticket limits'),
      'capacity_mismatch' => (string) $this->t('Check your capacity'),
      'single_ticket_type' => (string) $this->t('Add more ticket options'),
      'rsvp_no_capacity' => (string) $this->t('Set an RSVP limit'),
      'paid_zero_price' => (string) $this->t('Switch to RSVP'),
      'price_imbalance' => (string) $this->t('Review your pricing'),
      'short_description' => (string) $this->t('Add more detail'),
      'missing_image' => (string) $this->t('Add an event image'),
      'missing_category' => (string) $this->t('Choose a category'),
      'missing_accessibility' => (string) $this->t('Add accessibility details'),
      'boost_visibility' => (string) $this->t('Promote your event'),
      'calendar_confirmations_off' => (string) $this->t('Turn on confirmations'),
      'calendar_ics_path' => (string) $this->t('Enable calendar downloads'),
      'near_capacity' => (string) $this->t('Nearly at capacity'),
      'ai_suggestion' => (string) $this->t('Helpful tip'),
      default => (string) $this->t('Suggestion'),
    };
  }

  /**
   * Assembles a single engine row (title, optional normalised action).
   *
   * @return array{id: string, type: string, group: string, priority: int, message: string, title?: string, action?: array<string, string>}
   *   Internal row prior to formatSuggestionForApi.
   */
  public function row(string $id, string $type, string $group, int $priority, string $message, ?array $action): array {
    $row = [
      'id' => $id,
      'type' => $type,
      'group' => $group,
      'priority' => $priority,
      'message' => $message,
      'title' => $this->titleForSuggestionId($id),
    ];
    if ($action !== NULL) {
      $normalized = $this->normalizeActionForApi($action);
      if ($normalized !== NULL) {
        $row['action'] = $normalized;
      }
    }
    return $row;
  }

  /**
   * Restricts and copies action data for the client API.
   *
   * @param array<string, mixed> $action
   *   Raw action array from a rule.
   *
   * @return array<string, string>|null
   *   Normalised type/label/urls or null to omit.
   */
  public function normalizeActionForApi(array $action): ?array {
    $type = (string) ($action['type'] ?? '');
    if (!in_array($type, ['link', 'modal', 'auto_fix'], TRUE)) {
      return NULL;
    }
    $label = trim((string) ($action['label'] ?? ''));
    if ($label === '') {
      return NULL;
    }
    if ($type === 'link') {
      $url = trim((string) ($action['url'] ?? ''));
      if ($url === '') {
        return NULL;
      }
      return ['type' => 'link', 'label' => $label, 'url' => $url];
    }
    if ($type === 'modal') {
      $modal = trim((string) ($action['modal'] ?? ''));
      if ($modal === '' || !preg_match('/^[a-z0-9_]+$/', $modal)) {
        return NULL;
      }
      return ['type' => 'modal', 'label' => $label, 'modal' => $modal];
    }
    $callback = trim((string) ($action['callback'] ?? ''));
    $allowed = ['enable_confirmations', 'add_default_ticket'];
    if (!in_array($callback, $allowed, TRUE)) {
      return NULL;
    }
    return ['type' => 'auto_fix', 'label' => $label, 'callback' => $callback];
  }

  /**
   * Builds a link action to a Help Centre article, when configured.
   */
  public function helpActionLink(string $key, string $label): ?array {
    $url = $this->helpPath($key);
    if ($url === NULL) {
      return NULL;
    }
    return [
      'type' => 'link',
      'label' => $label,
      'url' => $url,
    ];
  }

  /**
   * Shorthand for the create-ticket modal action in the event studio.
   */
  public function modalCreateTicketAction(string $label): array {
    return [
      'type' => 'modal',
      'label' => $label,
      'modal' => 'create_ticket',
    ];
  }

  /**
   * Event Studio link for the ticketing section, with help fallback.
   */
  public function wizardTicketsAction(NodeInterface $event, string $label): ?array {
    try {
      $url = Url::fromRoute('myeventlane_event_studio.edit', ['node' => $event->id()])->toString();
      return [
        'type' => 'link',
        'label' => $label,
        'url' => $url,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->notice('wizardTicketsAction: @m', ['@m' => $e->getMessage()]);
      return $this->helpActionLink('ticket_types', (string) $this->t('Learn more'));
    }
  }

  /**
   * Event Studio link for when/where fields, with help fallback.
   */
  public function wizardWhenWhereAction(NodeInterface $event, string $label): ?array {
    try {
      $url = Url::fromRoute('myeventlane_event_studio.edit', ['node' => $event->id()])->toString();
      return [
        'type' => 'link',
        'label' => $label,
        'url' => $url,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->notice('wizardWhenWhereAction: @m', ['@m' => $e->getMessage()]);
      return $this->helpActionLink('rsvp_vs_paid', (string) $this->t('Learn more'));
    }
  }

  /**
   * Event Studio link for description and detail fields, with help fallback.
   */
  public function wizardDetailsAction(NodeInterface $event, string $label): ?array {
    try {
      $url = Url::fromRoute('myeventlane_event_studio.edit', ['node' => $event->id()])->toString();
      return [
        'type' => 'link',
        'label' => $label,
        'url' => $url,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->notice('wizardDetailsAction: @m', ['@m' => $e->getMessage()]);
      return $this->helpActionLink('event_create', (string) $this->t('Learn more'));
    }
  }

  /**
   * Node edit form link for image/category basics, with help fallback.
   */
  public function wizardBasicsAction(NodeInterface $event, string $label): ?array {
    try {
      $url = Url::fromRoute('entity.node.edit_form', ['node' => $event->id()])->toString();
      return [
        'type' => 'link',
        'label' => $label,
        'url' => $url,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->notice('wizardBasicsAction: @m', ['@m' => $e->getMessage()]);
      return $this->helpActionLink('event_create', (string) $this->t('Learn more'));
    }
  }

  /**
   * Link to the vendor boost wizard, or null if the route is missing.
   */
  public function boostWizardAction(NodeInterface $event): ?array {
    try {
      $url = Url::fromRoute('myeventlane_boost.vendor_boost_wizard', ['event' => $event->id()])->toString();
      return [
        'type' => 'link',
        'label' => (string) $this->t('Promote event'),
        'url' => $url,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->notice('boostWizardAction: @m', ['@m' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Resolves a Help Centre URL for a help_centre key, or null.
   */
  private function helpPath(string $key): ?string {
    if (!function_exists('myeventlane_help_centre_get_link')) {
      return NULL;
    }
    $link = myeventlane_help_centre_get_link($key);
    if ($link === NULL || empty($link['url'])) {
      return NULL;
    }
    return (string) $link['url'];
  }

}
