<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_ai\Service\AiManager;
use Drupal\myeventlane_ai\Value\PromptDefinition;
use Drupal\mel_ticket\Entity\TicketType;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * MEL-aware event wizard suggestions: ticketing, RSVP, commerce, boost, help CTAs.
 *
 * Also produces Event Quality Score and grouped insight payload for the wizard panel.
 */
final class EventSuggestionService {

  use StringTranslationTrait;

  private const MAX_SUGGESTIONS = 3;

  /** Priority tier: lower runs first when selecting top 3. */
  private const P_CRITICAL = 1;

  private const P_REVENUE = 2;

  private const P_TIPS = 3;

  public function __construct(
    private readonly HelpRetriever $helpRetriever,
    private readonly AiManager $aiManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly mixed $boostManager = NULL,
    private readonly mixed $attendanceManager = NULL,
  ) {}

  /**
   * Full wizard insight payload: score, copy, grouped suggestions (capped).
   *
   * @param array<string, mixed> $values
   *   Live wizard values (field_event_type, body, field_capacity, ticket_prices, …).
   * @param \Drupal\node\NodeInterface|null $event
   *   Event node when loaded with access check (authoritative for product, paragraphs).
   *
   * @return array<string, mixed>
   *   Keys: score (int), score_label, score_summary, score_band, groups, suggestions.
   */
  public function buildWizardInsights(array $values, ?NodeInterface $event): array {
    $candidates = $this->collectRuleCandidates($values, $event);
    $candidates = $this->dedupeById($candidates);
    usort($candidates, static function (array $a, array $b): int {
      return ($a['priority'] ?? 99) <=> ($b['priority'] ?? 99);
    });

    $assistantConfig = $this->configFactory->get('myeventlane_help_assistant.settings');
    $useAi = (bool) $assistantConfig->get('event_suggestions_ai');
    if ($useAi && $candidates === []) {
      $candidates = $this->maybeAiWhenEmpty([], $values);
    }

    $scoreData = $this->computeQualityScore($values, $event);
    $top = $this->capSuggestions($candidates);

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
   * Backward-compatible flat suggestion list (top 3, formatted for JSON).
   *
   * @param array<string, mixed> $values
   *
   * @return list<array<string, mixed>>
   */
  public function getSuggestions(array $values, ?NodeInterface $event): array {
    return $this->buildWizardInsights($values, $event)['suggestions'];
  }

  /**
   * @param array<string, mixed> $values
   *
   * @return list<array<string, mixed>>
   */
  private function collectRuleCandidates(array $values, ?NodeInterface $event): array {
    $out = [];

    $eventType = $this->effectiveEventType($values, $event);
    $venueCapacity = $this->effectiveVenueCapacity($values, $event);
    $rsvpCapacityState = $this->effectiveRsvpCapacityState($values, $event);

    $ticketIntel = $this->analyzeTicketParagraphs($event);
    $livePrices = isset($values['ticket_prices']) && is_array($values['ticket_prices'])
      ? $values['ticket_prices'] : [];
    $priceStats = $this->mergePriceStats($ticketIntel['prices'], $livePrices);

    $hasPaidLeg = in_array($eventType, ['paid', 'both'], TRUE);
    $hasRsvpLeg = in_array($eventType, ['rsvp', 'both'], TRUE);
    $hasProduct = $this->hasProductTarget($event);

    // 0. Product linked but no ticket type paragraphs (MEL stores types on the event).
    if ($hasPaidLeg && $event && $hasProduct && $ticketIntel['paragraph_count'] === 0) {
      $out[] = $this->row(
        'ticket_paragraphs_missing',
        'warning',
        'critical',
        self::P_CRITICAL,
        (string) $this->t('You’ve chosen paid tickets but haven’t set them up yet. Add at least one ticket type so people can purchase.'),
        $this->modalCreateTicketAction((string) $this->t('Create ticket')),
      );
    }

    // 1. Missing ticket setup (field_event_type paid; field_product_target empty on node).
    if ($hasPaidLeg && !$hasProduct && $event) {
      $out[] = $this->row(
        'missing_ticket_product',
        'warning',
        'critical',
        self::P_CRITICAL,
        (string) $this->t('You’ve chosen paid tickets but haven’t set them up yet. Add at least one ticket type so people can purchase.'),
        $this->modalCreateTicketAction((string) $this->t('Create ticket')),
      );
    }

    // 2. No ticket quantities (paid/both: no positive per-type capacity on paragraphs).
    if ($hasPaidLeg && $event && $hasProduct && !$ticketIntel['has_finite_quantities'] && $ticketIntel['paragraph_count'] > 0) {
      $out[] = $this->row(
        'ticket_no_limits',
        'warning',
        'critical',
        self::P_CRITICAL,
        (string) $this->t('Your tickets don’t have a quantity limit. This can lead to overselling if demand is high.'),
        $this->wizardTicketsAction($event, (string) $this->t('Set limits')),
      );
    }

    // 3. Capacity mismatch: sum of finite ticket caps > venue capacity.
    if (
      $hasPaidLeg && $event && $venueCapacity > 0
      && $ticketIntel['total_finite_qty'] > 0
      && !$ticketIntel['has_unlimited_paragraph']
      && $ticketIntel['total_finite_qty'] > $venueCapacity
    ) {
      $out[] = $this->row(
        'capacity_mismatch',
        'warning',
        'critical',
        self::P_CRITICAL,
        (string) $this->t('Your total ticket numbers are higher than your venue capacity. This could cause issues on the day.'),
        $this->wizardTicketsAction($event, (string) $this->t('Review tickets')),
      );
    }

    // 4. Single ticket type.
    if ($hasPaidLeg && $ticketIntel['paragraph_count'] === 1) {
      $out[] = $this->row(
        'single_ticket_type',
        'info',
        'revenue',
        self::P_REVENUE,
        (string) $this->t('Events often perform better with a few ticket types, like early bird or concession options.'),
        $event ? $this->wizardTicketsAction($event, (string) $this->t('Add ticket')) : $this->helpActionLink('ticket_types', (string) $this->t('Learn more')),
      );
    }

    // 5. RSVP without limit (MEL: event capacity field unset = unlimited RSVP headroom).
    if (in_array($eventType, ['rsvp', 'both'], TRUE) && $rsvpCapacityState === 'unset') {
      $out[] = $this->row(
        'rsvp_no_capacity',
        'warning',
        'critical',
        self::P_CRITICAL,
        (string) $this->t('Your event has no RSVP limit. Adding one helps you manage numbers and avoid overcrowding.'),
        $event ? $this->wizardWhenWhereAction($event, (string) $this->t('Set limit')) : $this->helpActionLink('rsvp_vs_paid', (string) $this->t('Learn more')),
      );
    }

    // 7. Paid but free (lowest variation/paragraph price is zero).
    if ($hasPaidLeg && $priceStats['min'] !== NULL && $priceStats['min'] <= 0.0) {
      $out[] = $this->row(
        'paid_zero_price',
        'info',
        'revenue',
        self::P_REVENUE,
        (string) $this->t('This looks like a free event. Using RSVP instead can make things simpler for your attendees.'),
        $this->helpActionLink('rsvp_vs_paid', (string) $this->t('Learn more')),
      );
    }

    // 8. Price imbalance.
    if (
      $hasPaidLeg && $priceStats['min'] !== NULL && $priceStats['max'] !== NULL
      && $priceStats['min'] > 0 && $priceStats['max'] > ($priceStats['min'] * 5)
    ) {
      $out[] = $this->row(
        'price_imbalance',
        'info',
        'revenue',
        self::P_REVENUE,
        (string) $this->t('There’s a big gap between your ticket prices. Keeping pricing clear and balanced can improve conversions.'),
        $event ? $this->wizardTicketsAction($event, (string) $this->t('Review pricing')) : $this->helpActionLink('ticket_types', (string) $this->t('Learn more')),
      );
    }

    $description = $this->effectiveDescription($values, $event);
    // 9. Short description / conversion.
    if ($description !== '' && mb_strlen($description) < 120) {
      $out[] = $this->row(
        'short_description',
        'warning',
        'tips',
        self::P_TIPS,
        (string) $this->t('Your description is quite short. A bit more detail helps people understand what to expect.'),
        $event ? $this->wizardDetailsAction($event, (string) $this->t('Improve this')) : $this->helpActionLink('event_create', (string) $this->t('Learn more')),
      );
    }

    // 10. Missing image.
    if ($event && $event->hasField('field_event_image') && $event->get('field_event_image')->isEmpty()) {
      $out[] = $this->row(
        'missing_image',
        'info',
        'revenue',
        self::P_REVENUE,
        (string) $this->t('Events with images tend to get more attention and bookings.'),
        $this->wizardBasicsAction($event, (string) $this->t('Add image')),
      );
    }

    // 11. Missing category.
    if ($event && $event->hasField('field_category') && $event->get('field_category')->isEmpty()) {
      $out[] = $this->row(
        'missing_category',
        'info',
        'tips',
        self::P_TIPS,
        (string) $this->t('Adding a category helps people find your event when browsing.'),
        $this->wizardBasicsAction($event, (string) $this->t('Choose category')),
      );
    }

    // 12. Accessibility.
    if ($event && $event->hasField('field_accessibility') && $event->get('field_accessibility')->isEmpty()) {
      $out[] = $this->row(
        'missing_accessibility',
        'info',
        'tips',
        self::P_TIPS,
        (string) $this->t('Sharing accessibility information helps more people feel comfortable attending your event.'),
        $this->wizardDetailsAction($event, (string) $this->t('Add details')),
      );
    }

    // 13. Boost (paid/both, not currently boosted).
    if ($hasPaidLeg && $event && !$this->isEventBoosted($event)) {
      $action = $this->boostWizardAction($event);
      $out[] = $this->row(
        'boost_visibility',
        'success',
        'revenue',
        self::P_REVENUE,
        (string) $this->t('Boosting your event can help it reach more people and stand out in listings.'),
        $action,
      );
    }

    // 14. Calendar / RSVP confirmations (MEL: ICS route + confirmation emails).
    $rsvpConfig = $this->configFactory->get('myeventlane_rsvp.settings');
    $confirmationsOn = (bool) ($rsvpConfig->get('send_confirmation') ?? TRUE);
    if ($hasRsvpLeg && !$confirmationsOn) {
      $confirmAction = $this->currentUser->hasPermission('administer myeventlane rsvp')
        ? [
          'type' => 'auto_fix',
          'label' => (string) $this->t('Turn on now'),
          'callback' => 'enable_confirmations',
        ]
        : $this->helpActionLink('rsvp_after_submit', (string) $this->t('Learn more'));
      $out[] = $this->row(
        'calendar_confirmations_off',
        'warning',
        'tips',
        self::P_TIPS,
        (string) $this->t('RSVP confirmations are switched off site-wide right now. When they’re on, attendees get details they can add to their calendar.'),
        $confirmAction,
      );
    }
    elseif ($hasRsvpLeg && $event && $confirmationsOn && $event->isPublished()) {
      try {
        $ics = Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => $event->id()], ['absolute' => FALSE])->toString();
        $out[] = $this->row(
          'calendar_ics_path',
          'info',
          'tips',
          self::P_TIPS,
          (string) $this->t('Let attendees add your event to their calendar so they don’t forget.'),
          [
            'type' => 'link',
            'label' => (string) $this->t('Get calendar link'),
            'url' => $ics,
          ],
        );
      }
      catch (\Throwable $e) {
        $this->logger->notice('ICS suggestion URL failed: @m', ['@m' => $e->getMessage()]);
      }
    }

    // Analytics-driven (attendance vs capacity) when service present.
    if ($event && $this->attendanceManager && method_exists($this->attendanceManager, 'getAvailability')) {
      /** @var callable $getAvail */
      $getAvail = [$this->attendanceManager, 'getAvailability'];
      try {
        $avail = $getAvail($event);
        $remaining = $avail['remaining'] ?? NULL;
        $capacity = (int) ($avail['capacity'] ?? 0);
        if ($capacity > 0 && is_int($remaining) && $remaining >= 0 && $remaining <= max(2, (int) round($capacity * 0.05))) {
          $out[] = $this->row(
            'near_capacity',
            'warning',
            'critical',
            self::P_CRITICAL,
            (string) $this->t('You’re nearly at capacity (@remaining spots left). You might like to pause sales until you’ve double-checked your limits.', [
              '@remaining' => (string) $remaining,
            ]),
            $this->helpActionLink('managing_dashboard', (string) $this->t('Learn more')),
          );
        }
      }
      catch (\Throwable $e) {
        $this->logger->notice('Event suggestions availability check failed: @m', ['@m' => $e->getMessage()]);
      }
    }

    return $out;
  }

  /**
   * @return array{score: int, score_label: string, score_summary: string, score_band: string}
   */
  private function computeQualityScore(array $values, ?NodeInterface $event): array {
    $score = 100;
    $eventType = $this->effectiveEventType($values, $event);
    $venueCapacity = $this->effectiveVenueCapacity($values, $event);
    $rsvpCapacityState = $this->effectiveRsvpCapacityState($values, $event);
    $ticketIntel = $this->analyzeTicketParagraphs($event);
    $livePrices = isset($values['ticket_prices']) && is_array($values['ticket_prices'])
      ? $values['ticket_prices'] : [];
    $priceStats = $this->mergePriceStats($ticketIntel['prices'], $livePrices);
    $hasPaidLeg = in_array($eventType, ['paid', 'both'], TRUE);
    $hasRsvpLeg = in_array($eventType, ['rsvp', 'both'], TRUE);
    $hasProduct = $this->hasProductTarget($event);

    $title = $this->effectiveTitle($values, $event);
    if ($title === '') {
      $score -= 25;
    }
    elseif (mb_strlen($title) < 12) {
      $score -= 8;
    }

    $description = $this->effectiveDescription($values, $event);
    if ($description === '') {
      $score -= 20;
    }
    elseif (mb_strlen($description) < 120) {
      $score -= 10;
    }

    if ($event && $event->hasField('field_event_image') && $event->get('field_event_image')->isEmpty()) {
      $score -= 12;
    }

    if ($event && $event->hasField('field_category') && $event->get('field_category')->isEmpty()) {
      $score -= 10;
    }

    if ($event && $event->hasField('field_accessibility') && $event->get('field_accessibility')->isEmpty()) {
      $score -= 6;
    }

    $hasWhenWhere = $this->hasEventStart($values, $event) && $this->hasMeaningfulLocation($values, $event);
    if ($eventType !== 'external' && !$hasWhenWhere) {
      $score -= 15;
    }

    if ($hasPaidLeg && $event && !$hasProduct) {
      $score -= 20;
    }

    if ($hasPaidLeg && $event && $hasProduct && !$ticketIntel['has_finite_quantities'] && $ticketIntel['paragraph_count'] > 0) {
      $score -= 12;
    }

    if ($hasRsvpLeg && $rsvpCapacityState === 'unset') {
      $score -= 8;
    }

    if (
      $hasPaidLeg && $event && $venueCapacity > 0
      && $ticketIntel['total_finite_qty'] > 0
      && !$ticketIntel['has_unlimited_paragraph']
      && $ticketIntel['total_finite_qty'] > $venueCapacity
    ) {
      $score -= 20;
    }

    if ($hasPaidLeg && $ticketIntel['paragraph_count'] === 1) {
      $score -= 4;
    }

    if ($hasPaidLeg && $priceStats['min'] !== NULL && $priceStats['min'] <= 0.0) {
      $score -= 10;
    }

    if ($hasPaidLeg && $event && !$this->isEventBoosted($event)) {
      $score -= 2;
    }

    if ($hasRsvpLeg && $event && !$event->isPublished()) {
      $score -= 2;
    }

    if ($hasPaidLeg && $event && $hasProduct && $ticketIntel['paragraph_count'] === 0) {
      $score -= 18;
    }

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
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function formatSuggestionForApi(array $row): array {
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
      'boost_visibility' => (string) $this->t('Boost your event'),
      'calendar_confirmations_off' => (string) $this->t('Turn on confirmations'),
      'calendar_ics_path' => (string) $this->t('Enable calendar downloads'),
      'near_capacity' => (string) $this->t('Nearly at capacity'),
      'ai_suggestion' => (string) $this->t('Helpful tip'),
      default => (string) $this->t('Suggestion'),
    };
  }

  /**
   * @param array<string, mixed> $values
   */
  private function effectiveTitle(array $values, ?NodeInterface $event): string {
    $t = trim((string) ($values['title'] ?? ''));
    if ($t !== '') {
      return $t;
    }
    if ($event) {
      $label = trim((string) $event->label());
      if ($label !== '' && !str_contains($label, 'Untitled event (draft)')) {
        return $label;
      }
    }
    return '';
  }

  /**
   * @param array<string, mixed> $values
   */
  private function hasEventStart(array $values, ?NodeInterface $event): bool {
    $raw = trim((string) ($values['field_event_start'] ?? ''));
    if ($raw !== '') {
      return TRUE;
    }
    if (!empty($values['field_event_start_has_value'])) {
      return TRUE;
    }
    if ($event && $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $values
   */
  private function hasMeaningfulLocation(array $values, ?NodeInterface $event): bool {
    if (!empty($values['field_location_has_value'])) {
      return TRUE;
    }
    if ($event && $event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      return TRUE;
    }
    if ($event && $event->hasField('field_venue') && !$event->get('field_venue')->isEmpty()) {
      return TRUE;
    }
    if ($event && $event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      $vn = trim((string) $event->get('field_venue_name')->value);
      if ($vn !== '') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param list<array<string, mixed>> $items
   *
   * @return list<array<string, mixed>>
   */
  private function dedupeById(array $items): array {
    $seen = [];
    $out = [];
    foreach ($items as $item) {
      $id = (string) ($item['id'] ?? '');
      if ($id === '' || isset($seen[$id])) {
        continue;
      }
      $seen[$id] = TRUE;
      $out[] = $item;
    }
    return $out;
  }

  /**
   * @param list<array<string, mixed>> $items
   *
   * @return list<array<string, mixed>>
   */
  private function capSuggestions(array $items): array {
    if (count($items) <= self::MAX_SUGGESTIONS) {
      return array_values($items);
    }
    return array_values(array_slice($items, 0, self::MAX_SUGGESTIONS));
  }

  /**
   * @return array{id: string, type: string, group: string, priority: int, message: string, title?: string, action?: array<string, string>}
   */
  private function row(string $id, string $type, string $group, int $priority, string $message, ?array $action): array {
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
   * @param array<string, mixed> $action
   *
   * @return array<string, string>|null
   */
  private function normalizeActionForApi(array $action): ?array {
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

  private function helpActionLink(string $key, string $label): ?array {
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

  private function modalCreateTicketAction(string $label): array {
    return [
      'type' => 'modal',
      'label' => $label,
      'modal' => 'create_ticket',
    ];
  }

  private function wizardTicketsAction(NodeInterface $event, string $label): ?array {
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

  private function wizardWhenWhereAction(NodeInterface $event, string $label): ?array {
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

  private function wizardDetailsAction(NodeInterface $event, string $label): ?array {
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

  private function wizardBasicsAction(NodeInterface $event, string $label): ?array {
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
   * @return array{paragraph_count: int, has_finite_quantities: bool, has_unlimited_paragraph: bool, total_finite_qty: int, prices: list<float>}
   */
  private function analyzeTicketParagraphs(?NodeInterface $event): array {
    $defaults = [
      'paragraph_count' => 0,
      'has_finite_quantities' => FALSE,
      'has_unlimited_paragraph' => FALSE,
      'total_finite_qty' => 0,
      'prices' => [],
    ];
    if (!$event || !$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return $defaults;
    }
    $count = 0;
    $hasFinite = FALSE;
    $hasUnlimited = FALSE;
    $totalFinite = 0;
    $prices = [];
    foreach ($event->get('field_ticket_types')->referencedEntities() as $ticket) {
      if (!$ticket instanceof TicketType) {
        continue;
      }
      $count++;
      if ($ticket->get('capacity')->isEmpty()) {
        $hasUnlimited = TRUE;
      }
      else {
        $n = (int) ($ticket->get('capacity')->value ?? 0);
        if ($n > 0) {
          $hasFinite = TRUE;
          $totalFinite += $n;
        }
        else {
          $hasUnlimited = TRUE;
        }
      }
      $price = $ticket->toPriceValue();
      if ($price) {
        $prices[] = (float) $price->getNumber();
      }
    }
    return [
      'paragraph_count' => $count,
      'has_finite_quantities' => $hasFinite,
      'has_unlimited_paragraph' => $hasUnlimited,
      'total_finite_qty' => $totalFinite,
      'prices' => $prices,
    ];
  }

  /**
   * @param list<float> $savedPrices
   * @param list<mixed>  $livePrices
   *
   * @return array{min: ?float, max: ?float}
   */
  private function mergePriceStats(array $savedPrices, array $livePrices): array {
    $nums = $savedPrices;
    foreach ($livePrices as $p) {
      if (is_numeric($p)) {
        $nums[] = (float) $p;
      }
    }
    $min = NULL;
    $max = NULL;
    foreach ($nums as $n) {
      if ($min === NULL || $n < $min) {
        $min = $n;
      }
      if ($max === NULL || $n > $max) {
        $max = $n;
      }
    }
    return ['min' => $min, 'max' => $max];
  }

  private function hasProductTarget(?NodeInterface $event): bool {
    if (!$event || !$event->hasField('field_product_target')) {
      return FALSE;
    }
    return !$event->get('field_product_target')->isEmpty();
  }

  /**
   * @param array<string, mixed> $values
   */
  private function effectiveEventType(array $values, ?NodeInterface $event): string {
    $raw = (string) ($values['field_event_type'] ?? '');
    if ($raw !== '') {
      return $this->normalizeEventType($raw);
    }
    if ($event && $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
      return $this->normalizeEventType((string) $event->get('field_event_type')->value);
    }
    return '';
  }

  /**
   * @param array<string, mixed> $values
   */
  private function effectiveVenueCapacity(array $values, ?NodeInterface $event): int {
    $raw = $values['field_capacity'] ?? NULL;
    if ($raw !== NULL && $raw !== '') {
      if (is_numeric($raw)) {
        return max(0, (int) $raw);
      }
    }
    if ($event && $event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()) {
      return max(0, (int) $event->get('field_capacity')->value);
    }
    return 0;
  }

  /**
   * RSVP / event capacity field: unset vs unlimited (0) vs limited (>0).
   *
   * @param array<string, mixed> $values
   */
  private function effectiveRsvpCapacityState(array $values, ?NodeInterface $event): string {
    $raw = $values['field_capacity'] ?? NULL;
    if ($raw !== NULL && $raw !== '') {
      return $this->normalizeCapacityState($raw);
    }
    if ($event && $event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()) {
      return $this->normalizeCapacityState($event->get('field_capacity')->value);
    }
    return 'unset';
  }

  /**
   * @param array<string, mixed> $values
   */
  private function effectiveDescription(array $values, ?NodeInterface $event): string {
    $body = (string) ($values['body'] ?? '');
    $intro = (string) ($values['field_event_intro'] ?? '');
    if ($body === '' && $event && $event->hasField('body')) {
      $body = $event->get('body')->value ?? '';
    }
    if ($intro === '' && $event && $event->hasField('field_event_intro')) {
      $intro = (string) ($event->get('field_event_intro')->value ?? '');
    }
    return $this->combinedDescription($body, $intro);
  }

  private function isEventBoosted(NodeInterface $event): bool {
    if ($this->boostManager && method_exists($this->boostManager, 'isBoosted')) {
      try {
        return (bool) $this->boostManager->isBoosted($event);
      }
      catch (\Throwable $e) {
        $this->logger->notice('Boost status check failed: @m', ['@m' => $e->getMessage()]);
      }
    }
    if ($event->hasField('field_promoted') && !$event->get('field_promoted')->isEmpty()) {
      return (bool) $event->get('field_promoted')->value;
    }
    return FALSE;
  }

  private function boostWizardAction(NodeInterface $event): ?array {
    try {
      $url = Url::fromRoute('myeventlane_boost.vendor_boost_wizard', ['event' => $event->id()])->toString();
      return [
        'type' => 'link',
        'label' => (string) $this->t('Boost event'),
        'url' => $url,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->notice('boostWizardAction: @m', ['@m' => $e->getMessage()]);
      return NULL;
    }
  }

  private function normalizeEventType(string $raw): string {
    $raw = mb_strtolower(trim($raw));
    return in_array($raw, ['rsvp', 'paid', 'both', 'external'], TRUE) ? $raw : '';
  }

  private function normalizeCapacityState(mixed $venueCapacityOrRaw): string {
    if ($venueCapacityOrRaw === NULL || $venueCapacityOrRaw === '') {
      return 'unset';
    }
    if (is_int($venueCapacityOrRaw) || is_float($venueCapacityOrRaw)) {
      $n = (int) $venueCapacityOrRaw;
      return $n > 0 ? 'limited' : 'unlimited';
    }
    if (is_string($venueCapacityOrRaw)) {
      $trim = trim($venueCapacityOrRaw);
      if ($trim === '') {
        return 'unset';
      }
      if (is_numeric($trim)) {
        $n = (int) $trim;
        return $n > 0 ? 'limited' : 'unlimited';
      }
    }
    return 'unset';
  }

  private function combinedDescription(string $body, string $intro): string {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($body . ' ' . $intro)) ?? '');
    return $text;
  }

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

  /**
   * @param list<array<string, mixed>> $current
   * @param array<string, mixed>       $values
   *
   * @return list<array<string, mixed>>
   */
  private function maybeAiWhenEmpty(array $current, array $values): array {
    $aiConfig = $this->configFactory->get('myeventlane_ai.settings');
    if (!(bool) $aiConfig->get('enabled')) {
      return $current;
    }

    $retrievalQuery = $this->buildRetrievalQuery($values);
    $articles = $retrievalQuery !== ''
      ? $this->helpRetriever->retrieve($retrievalQuery, 3)
      : [];

    if ($articles === []) {
      return $current;
    }

    $structured = [
      'title' => (string) ($values['title'] ?? ''),
      'field_event_type' => (string) ($values['field_event_type'] ?? ''),
      'body_excerpt' => mb_substr(strip_tags((string) ($values['body'] ?? '')), 0, 400),
    ];

    $articleContext = [];
    foreach ($articles as $row) {
      $articleContext[] = [
        'title' => (string) ($row['title'] ?? ''),
        'summary' => (string) ($row['summary'] ?? ''),
        'url' => (string) ($row['url'] ?? ''),
      ];
    }

    $userMessage = implode("\n\n", [
      'Suggest 1 improvement for this event setup.',
      'Event data:',
      Json::encode($structured),
      'Help articles:',
      Json::encode(['articles' => $articleContext]),
      'Rules:',
      '- Do not invent MyEventLane features.',
      '- Keep it short.',
      '- Return JSON only: {"suggestions":[{"type":"info|warning|success","message":"..."}]}',
    ]);

    $system = (string) $this->t('You only output valid JSON. Max 1 suggestion. Types: info, warning, success.');
    $hash = hash('sha256', 'mel.event_suggestions.ai.empty|' . $system . '|' . $userMessage);
    $definition = new PromptDefinition('mel.event_suggestions.empty', 'v2', $system, $userMessage, $hash);

    $uid = (int) $this->currentUser->id();
    $result = $this->aiManager->analyze(
      $definition,
      [
        'model' => (string) ($aiConfig->get('openai.model') ?: 'gpt-4o-mini'),
        'temperature' => 0.2,
        'max_tokens' => 200,
        'timeout_seconds' => (int) ($aiConfig->get('openai.timeout_seconds') ?? 15),
      ],
      $uid > 0 ? $uid : NULL,
      'empty:' . substr($hash, 0, 12),
      NULL,
    );

    if (!$result->ok) {
      return $current;
    }

    $decoded = is_array($result->json) ? $result->json : json_decode(trim($result->raw), TRUE);
    if (!is_array($decoded) || empty($decoded['suggestions'][0])) {
      return $current;
    }
    $row = $decoded['suggestions'][0];
    if (!is_array($row)) {
      return $current;
    }
    $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
    $message = trim((string) ($row['message'] ?? ''));
    if (!in_array($type, ['info', 'warning', 'success'], TRUE) || $message === '') {
      return $current;
    }
    $action = NULL;
    if (!empty($articles[0]['url'])) {
      $action = [
        'type' => 'link',
        'label' => (string) $this->t('Learn more'),
        'url' => (string) $articles[0]['url'],
      ];
    }

    return [$this->row('ai_suggestion', $type, 'tips', self::P_TIPS, $message, $action)];
  }

  /**
   * @param array<string, mixed> $values
   */
  private function buildRetrievalQuery(array $values): string {
    $parts = array_filter([
      trim((string) ($values['title'] ?? '')),
      trim((string) ($values['field_event_type'] ?? '')),
      mb_substr(strip_tags((string) ($values['body'] ?? '')), 0, 200),
    ]);
    return trim(implode(' ', $parts));
  }

}
