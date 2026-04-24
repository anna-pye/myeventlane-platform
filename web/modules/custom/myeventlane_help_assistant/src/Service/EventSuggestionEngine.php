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
 * Rule evaluation, optional AI backfill, and numeric event quality score.
 */
final class EventSuggestionEngine {

  use StringTranslationTrait;

  private const MAX_SUGGESTIONS = 3;

  // Lower number sorts earlier when capping the top 3.
  private const P_CRITICAL = 1;

  private const P_REVENUE = 2;

  private const P_TIPS = 3;

  public function __construct(
    private readonly EventSuggestionFormatter $formatter,
    private readonly HelpRetriever $helpRetriever,
    private readonly AiManager $aiManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly mixed $boostManager = NULL,
    private readonly mixed $attendanceManager = NULL,
  ) {}

  /**
   * Caps and sorts rule + AI rows (pre-API, internal row shape).
   *
   * @param array<string, mixed> $values
   *   Live wizard values (field_event_type, body, field_capacity, ticket_prices, ...).
   * @param \Drupal\node\NodeInterface|null $event
   *   Event node from access-checked load, or null.
   *
   * @return list<array<string, mixed>>
   *   Suggestion row arrays, at most self::MAX_SUGGESTIONS items.
   */
  public function buildPreparedTopRows(array $values, ?NodeInterface $event): array {
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

    return $this->capSuggestions($candidates);
  }

  /**
   * Gathers MEL rules for the current wizard + node snapshot.
   *
   * @param array<string, mixed> $values
   *   Live wizard form values.
   * @param \Drupal\node\NodeInterface|null $event
   *   Event being edited (access-checked) or null.
   *
   * @return list<array<string, mixed>>
   *   Suggestion row arrays, unsorted, possible duplicate ids.
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
      $out[] = $this->formatter->row(
        'ticket_paragraphs_missing',
        'warning',
        'critical',
        self::P_CRITICAL,
        (string) $this->t('You’ve chosen paid tickets but haven’t set them up yet. Add at least one ticket type so people can purchase.'),
        $this->formatter->modalCreateTicketAction((string) $this->t('Create ticket')),
      );
    }

    // 1. Missing ticket setup (field_event_type paid; field_product_target empty on node).
    if ($hasPaidLeg && !$hasProduct && $event) {
      $out[] = $this->formatter->row(
        'missing_ticket_product',
        'warning',
        'critical',
        self::P_CRITICAL,
        (string) $this->t('You’ve chosen paid tickets but haven’t set them up yet. Add at least one ticket type so people can purchase.'),
        $this->formatter->modalCreateTicketAction((string) $this->t('Create ticket')),
      );
    }

    // 2. No ticket quantities (paid/both: no positive per-type capacity on paragraphs).
    if ($hasPaidLeg && $event && $hasProduct && !$ticketIntel['has_finite_quantities'] && $ticketIntel['paragraph_count'] > 0) {
      $out[] = $this->formatter->row(
        'ticket_no_limits',
        'warning',
        'critical',
        self::P_CRITICAL,
        (string) $this->t('Your tickets don’t have a quantity limit. This can lead to overselling if demand is high.'),
        $this->formatter->wizardTicketsAction($event, (string) $this->t('Set limits')),
      );
    }

    // 3. Capacity mismatch: sum of finite ticket caps > venue capacity.
    if (
      $hasPaidLeg && $event && $venueCapacity > 0
      && $ticketIntel['total_finite_qty'] > 0
      && !$ticketIntel['has_unlimited_paragraph']
      && $ticketIntel['total_finite_qty'] > $venueCapacity
    ) {
      $out[] = $this->formatter->row(
        'capacity_mismatch',
        'warning',
        'critical',
        self::P_CRITICAL,
        (string) $this->t('Your total ticket numbers are higher than your venue capacity. This could cause issues on the day.'),
        $this->formatter->wizardTicketsAction($event, (string) $this->t('Review tickets')),
      );
    }

    // 4. Single ticket type.
    if ($hasPaidLeg && $ticketIntel['paragraph_count'] === 1) {
      $out[] = $this->formatter->row(
        'single_ticket_type',
        'info',
        'revenue',
        self::P_REVENUE,
        (string) $this->t('Events often perform better with a few ticket types, like early bird or concession options.'),
        $event ? $this->formatter->wizardTicketsAction($event, (string) $this->t('Add ticket')) : $this->formatter->helpActionLink('ticket_types', (string) $this->t('Learn more')),
      );
    }

    // 5. RSVP without limit (MEL: event capacity field unset = unlimited RSVP headroom).
    if (in_array($eventType, ['rsvp', 'both'], TRUE) && $rsvpCapacityState === 'unset') {
      $out[] = $this->formatter->row(
        'rsvp_no_capacity',
        'warning',
        'critical',
        self::P_CRITICAL,
        (string) $this->t('Your event has no RSVP limit. Adding one helps you manage numbers and avoid overcrowding.'),
        $event ? $this->formatter->wizardWhenWhereAction($event, (string) $this->t('Set limit')) : $this->formatter->helpActionLink('rsvp_vs_paid', (string) $this->t('Learn more')),
      );
    }

    // 7. Paid but free (lowest variation/paragraph price is zero).
    if ($hasPaidLeg && $priceStats['min'] !== NULL && $priceStats['min'] <= 0.0) {
      $out[] = $this->formatter->row(
        'paid_zero_price',
        'info',
        'revenue',
        self::P_REVENUE,
        (string) $this->t('This looks like a free event. Using RSVP instead can make things simpler for your attendees.'),
        $this->formatter->helpActionLink('rsvp_vs_paid', (string) $this->t('Learn more')),
      );
    }

    // 8. Price imbalance.
    if (
      $hasPaidLeg && $priceStats['min'] !== NULL && $priceStats['max'] !== NULL
      && $priceStats['min'] > 0 && $priceStats['max'] > ($priceStats['min'] * 5)
    ) {
      $out[] = $this->formatter->row(
        'price_imbalance',
        'info',
        'revenue',
        self::P_REVENUE,
        (string) $this->t('There’s a big gap between your ticket prices. Keeping pricing clear and balanced can improve conversions.'),
        $event ? $this->formatter->wizardTicketsAction($event, (string) $this->t('Review pricing')) : $this->formatter->helpActionLink('ticket_types', (string) $this->t('Learn more')),
      );
    }

    $description = $this->effectiveDescription($values, $event);
    // 9. Short description / conversion.
    if ($description !== '' && mb_strlen($description) < 120) {
      $out[] = $this->formatter->row(
        'short_description',
        'warning',
        'tips',
        self::P_TIPS,
        (string) $this->t('Your description is quite short. A bit more detail helps people understand what to expect.'),
        $event ? $this->formatter->wizardDetailsAction($event, (string) $this->t('Improve this')) : $this->formatter->helpActionLink('event_create', (string) $this->t('Learn more')),
      );
    }

    // 10. Missing image.
    if ($event && $event->hasField('field_event_image') && $event->get('field_event_image')->isEmpty()) {
      $out[] = $this->formatter->row(
        'missing_image',
        'info',
        'revenue',
        self::P_REVENUE,
        (string) $this->t('Events with images tend to get more attention and bookings.'),
        $this->formatter->wizardBasicsAction($event, (string) $this->t('Add image')),
      );
    }

    // 11. Missing category.
    if ($event && $event->hasField('field_category') && $event->get('field_category')->isEmpty()) {
      $out[] = $this->formatter->row(
        'missing_category',
        'info',
        'tips',
        self::P_TIPS,
        (string) $this->t('Adding a category helps people find your event when browsing.'),
        $this->formatter->wizardBasicsAction($event, (string) $this->t('Choose category')),
      );
    }

    // 12. Accessibility.
    if ($event && $event->hasField('field_accessibility') && $event->get('field_accessibility')->isEmpty()) {
      $out[] = $this->formatter->row(
        'missing_accessibility',
        'info',
        'tips',
        self::P_TIPS,
        (string) $this->t('Sharing accessibility information helps more people feel comfortable attending your event.'),
        $this->formatter->wizardDetailsAction($event, (string) $this->t('Add details')),
      );
    }

    // 13. Boost (paid/both, not currently boosted).
    if ($hasPaidLeg && $event && !$this->isEventBoosted($event)) {
      $action = $this->formatter->boostWizardAction($event);
      $out[] = $this->formatter->row(
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
        : $this->formatter->helpActionLink('rsvp_after_submit', (string) $this->t('Learn more'));
      $out[] = $this->formatter->row(
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
        $out[] = $this->formatter->row(
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
          $out[] = $this->formatter->row(
            'near_capacity',
            'warning',
            'critical',
            self::P_CRITICAL,
            (string) $this->t('You’re nearly at capacity (@remaining spots left). You might like to pause sales until you’ve double-checked your limits.', [
              '@remaining' => (string) $remaining,
            ]),
            $this->formatter->helpActionLink('managing_dashboard', (string) $this->t('Learn more')),
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
   * Numeric 0-100 event quality score from the same heuristics as before extraction.
   *
   * @param array<string, mixed> $values
   *   Live wizard values.
   * @param \Drupal\node\NodeInterface|null $event
   *   Event node for persisted fields, or null.
   *
   * @return int
   *   Clamped score 0-100.
   */
  public function computeScoreValue(array $values, ?NodeInterface $event): int {
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

    return $score;
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
   * @param list<mixed> $livePrices
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

  /**
   *
   */
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

  /**
   *
   */
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

  /**
   *
   */
  private function normalizeEventType(string $raw): string {
    $raw = mb_strtolower(trim($raw));
    return in_array($raw, ['rsvp', 'paid', 'both', 'external'], TRUE) ? $raw : '';
  }

  /**
   *
   */
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

  /**
   *
   */
  private function combinedDescription(string $body, string $intro): string {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($body . ' ' . $intro)) ?? '');
    return $text;
  }

  /**
   * @param list<array<string, mixed>> $current
   * @param array<string, mixed> $values
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

    return [$this->formatter->row('ai_suggestion', $type, 'tips', self::P_TIPS, $message, $action)];
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
