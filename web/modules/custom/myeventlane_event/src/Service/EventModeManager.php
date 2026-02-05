<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Manages event booking modes and provides unified CTA logic.
 *
 * This service is the canonical source of truth for determining how an event
 * should be booked (RSVP, paid tickets, both, external, or none).
 *
 * INVARIANT:
 * Ticket capacity MUST be reflected here (getRsvpAvailability, getTicketAvailability
 * using EventCapacityService). Do not rely on node edit form validation or UI state.
 * This protects Ticket UX (Phase 3A).
 */
final class EventModeManager {

  use StringTranslationTrait;

  /**
   * Event mode constants.
   */
  public const MODE_RSVP = 'rsvp';
  public const MODE_PAID = 'paid';
  public const MODE_BOTH = 'both';
  public const MODE_EXTERNAL = 'external';
  public const MODE_NONE = 'none';

  /**
   * Constructs EventModeManager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly UrlGeneratorInterface $urlGenerator,
    private readonly TimeInterface $time,
    private readonly ?EventCapacityServiceInterface $capacityService = NULL,
  ) {}

  /**
   * Gets the effective booking mode for an event.
   *
   * The mode is inferred from field values:
   * - 'external': field_event_type = 'external' with a URL configured.
   * - 'both': field_event_type = 'rsvp' AND field_product_target is populated.
   * - 'rsvp': field_event_type = 'rsvp' with no product linked.
   * - 'paid': field_event_type = 'paid' AND field_product_target is populated.
   * - 'none': Any other state (misconfigured or coming soon).
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return string
   *   One of the MODE_* constants.
   */
  public function getEffectiveMode(NodeInterface $event): string {
    if ($event->bundle() !== 'event') {
      return self::MODE_NONE;
    }

    $eventType = $this->getFieldValue($event, 'field_event_type');
    $hasProduct = $this->hasLinkedProduct($event);
    $hasExternalUrl = $this->hasExternalUrl($event);

    // External mode.
    if ($eventType === 'external' && $hasExternalUrl) {
      return self::MODE_EXTERNAL;
    }

    // Explicit "both" mode (free + paid).
    if ($eventType === 'both' && $hasProduct) {
      return self::MODE_BOTH;
    }

    // RSVP mode with product linked also = both (for backward compatibility).
    if ($eventType === 'rsvp' && $hasProduct) {
      $product = $this->getLinkedProduct($event);
      // If product has multiple variations or non-zero price, it's "both".
      if ($product && $this->isHybridProduct($product)) {
        return self::MODE_BOTH;
      }
      // If product is auto-generated $0 RSVP product, treat as RSVP only.
      return self::MODE_RSVP;
    }

    // RSVP only (no product or auto-created $0 product).
    if ($eventType === 'rsvp') {
      return self::MODE_RSVP;
    }

    // Paid tickets.
    if ($eventType === 'paid' && $hasProduct) {
      return self::MODE_PAID;
    }

    // Misconfigured or not yet configured.
    return self::MODE_NONE;
  }

  /**
   * Checks if RSVP is enabled for this event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if RSVP flow is available.
   */
  public function isRsvpEnabled(NodeInterface $event): bool {
    $mode = $this->getEffectiveMode($event);
    return in_array($mode, [self::MODE_RSVP, self::MODE_BOTH], TRUE);
  }

  /**
   * Checks if paid tickets are enabled for this event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if ticket purchase flow is available.
   */
  public function isTicketsEnabled(NodeInterface $event): bool {
    $mode = $this->getEffectiveMode($event);
    return in_array($mode, [self::MODE_PAID, self::MODE_BOTH], TRUE);
  }

  /**
   * Checks if this event uses an external link.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if external link mode.
   */
  public function isExternalLink(NodeInterface $event): bool {
    return $this->getEffectiveMode($event) === self::MODE_EXTERNAL;
  }

  /**
   * Checks if the event is currently bookable (any mode).
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if customers can take action (RSVP, buy, or visit external link).
   */
  public function isBookable(NodeInterface $event): bool {
    $mode = $this->getEffectiveMode($event);

    if ($mode === self::MODE_NONE) {
      return FALSE;
    }

    // Check if event date hasn't passed.
    if ($this->isEventPast($event)) {
      return FALSE;
    }

    // For RSVP modes, check capacity.
    if (in_array($mode, [self::MODE_RSVP, self::MODE_BOTH], TRUE)) {
      $rsvpAvail = $this->getRsvpAvailability($event);
      if ($rsvpAvail['available']) {
        return TRUE;
      }
    }

    // For paid modes, check product availability.
    if (in_array($mode, [self::MODE_PAID, self::MODE_BOTH], TRUE)) {
      $ticketAvail = $this->getTicketAvailability($event);
      if ($ticketAvail['available']) {
        return TRUE;
      }
    }

    // External links are always "bookable" if configured.
    if ($mode === self::MODE_EXTERNAL) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Gets RSVP availability status for the event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Associative array with:
   *   - 'available': bool
   *   - 'reason': string (human-readable explanation)
   *   - 'spots_remaining': int|null
   */
  public function getRsvpAvailability(NodeInterface $event): array {
    if (!$this->isRsvpEnabled($event)) {
      return [
        'available' => FALSE,
        'reason' => (string) $this->t('RSVP is not enabled for this event.'),
        'spots_remaining' => NULL,
      ];
    }

    if ($this->isEventPast($event)) {
      return [
        'available' => FALSE,
        'reason' => (string) $this->t('This event has already ended.'),
        'spots_remaining' => 0,
      ];
    }

    // Use EventCapacityService when available so display matches enforcement.
    if ($this->capacityService) {
      if ($this->capacityService->isSoldOut($event)) {
        return [
          'available' => FALSE,
          'reason' => (string) $this->t('This event is at capacity.'),
          'spots_remaining' => 0,
        ];
      }
      $remaining = $this->capacityService->getRemaining($event);
      return [
        'available' => $remaining === NULL || $remaining > 0,
        'reason' => $remaining !== NULL
          ? (string) $this->t('@count spot(s) remaining.', ['@count' => $remaining])
          : (string) $this->t('Spots available.'),
        'spots_remaining' => $remaining,
      ];
    }

    $availability = $this->attendanceManager->getAvailability($event);
    return [
      'available' => $availability['available'],
      'reason' => $availability['reason'],
      'spots_remaining' => $availability['remaining'],
    ];
  }

  /**
   * Gets ticket availability status for the event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Associative array with:
   *   - 'available': bool
   *   - 'reason': string
   *   - 'product': \Drupal\commerce_product\Entity\ProductInterface|null
   */
  public function getTicketAvailability(NodeInterface $event): array {
    if (!$this->isTicketsEnabled($event)) {
      return [
        'available' => FALSE,
        'reason' => (string) $this->t('Tickets are not enabled for this event.'),
        'product' => NULL,
      ];
    }

    if ($this->isEventPast($event)) {
      return [
        'available' => FALSE,
        'reason' => (string) $this->t('This event has already ended.'),
        'product' => NULL,
      ];
    }

    $product = $this->getLinkedProduct($event);

    if (!$product) {
      return [
        'available' => FALSE,
        'reason' => (string) $this->t('No ticket product configured.'),
        'product' => NULL,
      ];
    }

    // Check if product is published.
    if (!$product->isPublished()) {
      return [
        'available' => FALSE,
        'reason' => (string) $this->t('Tickets are not currently on sale.'),
        'product' => $product,
      ];
    }

    // Check event-level capacity if service is available.
    if ($this->capacityService) {
      if ($this->capacityService->isSoldOut($event)) {
        return [
          'available' => FALSE,
          'reason' => (string) $this->t('This event is sold out.'),
          'product' => $product,
          'remaining' => 0,
        ];
      }
      $remaining = $this->capacityService->getRemaining($event);
      $reason = $remaining !== NULL
        ? (string) $this->t('@count ticket(s) remaining.', ['@count' => $remaining])
        : (string) $this->t('Tickets available.');
      return [
        'available' => TRUE,
        'reason' => $reason,
        'product' => $product,
        'remaining' => $remaining,
      ];
    }

    return [
      'available' => TRUE,
      'reason' => (string) $this->t('Tickets available.'),
      'product' => $product,
      'remaining' => NULL,
    ];
  }

  /**
   * Gets the primary CTA render array for the event.
   *
   * Returns the most prominent action based on mode priority:
   * - Paid tickets take priority in 'both' mode.
   * - Falls back to RSVP, then external link.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Render array for the primary CTA, or empty array if none.
   */
  public function getPrimaryCta(NodeInterface $event): array {
    $mode = $this->getEffectiveMode($event);

    if ($mode === self::MODE_NONE) {
      return $this->buildComingSoonCta();
    }

    if ($this->isEventPast($event)) {
      return $this->buildPastEventCta();
    }

    // Paid tickets are primary in 'both' and 'paid' modes.
    if (in_array($mode, [self::MODE_PAID, self::MODE_BOTH], TRUE)) {
      $ticketAvail = $this->getTicketAvailability($event);
      // Show CTA if tickets are available OR if a product is linked (even if not published).
      // The booking page will handle showing appropriate messages for unpublished products.
      if ($ticketAvail['available'] || !empty($ticketAvail['product'])) {
        return $this->buildTicketCta($event);
      }
    }

    // RSVP is primary in 'rsvp' mode, secondary in 'both'.
    if (in_array($mode, [self::MODE_RSVP, self::MODE_BOTH], TRUE)) {
      $rsvpAvail = $this->getRsvpAvailability($event);
      if ($rsvpAvail['available']) {
        return $this->buildRsvpCta($event);
      }
      elseif ($rsvpAvail['spots_remaining'] === 0) {
        return $this->buildWaitlistCta($event);
      }
    }

    // External link.
    if ($mode === self::MODE_EXTERNAL) {
      return $this->buildExternalCta($event);
    }

    return $this->buildComingSoonCta();
  }

  /**
   * Gets all available CTAs for the event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of CTA render arrays keyed by type ('tickets', 'rsvp', 'external').
   */
  public function getAllCtas(NodeInterface $event): array {
    $ctas = [];
    $mode = $this->getEffectiveMode($event);

    if ($mode === self::MODE_NONE || $this->isEventPast($event)) {
      return $ctas;
    }

    // Tickets CTA.
    if (in_array($mode, [self::MODE_PAID, self::MODE_BOTH], TRUE)) {
      $ticketAvail = $this->getTicketAvailability($event);
      // Show CTA if tickets are available OR if a product is linked (even if not published).
      // The booking page will handle showing appropriate messages for unpublished products.
      if ($ticketAvail['available'] || !empty($ticketAvail['product'])) {
        $ctas['tickets'] = $this->buildTicketCta($event);
      }
    }

    // RSVP CTA.
    if (in_array($mode, [self::MODE_RSVP, self::MODE_BOTH], TRUE)) {
      $rsvpAvail = $this->getRsvpAvailability($event);
      if ($rsvpAvail['available']) {
        $ctas['rsvp'] = $this->buildRsvpCta($event, $mode === self::MODE_BOTH);
      }
      elseif ($rsvpAvail['spots_remaining'] === 0) {
        $ctas['waitlist'] = $this->buildWaitlistCta($event);
      }
    }

    // External CTA.
    if ($mode === self::MODE_EXTERNAL) {
      $ctas['external'] = $this->buildExternalCta($event);
    }

    return $ctas;
  }

  /**
   * Gets configuration status for vendor UX.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Status information for each booking mode.
   */
  public function getConfigurationStatus(NodeInterface $event): array {
    $eventType = $this->getFieldValue($event, 'field_event_type');
    $hasProduct = $this->hasLinkedProduct($event);
    $hasExternalUrl = $this->hasExternalUrl($event);
    $availability = $this->attendanceManager->getAvailability($event);

    return [
      'event_type' => $eventType,
      'effective_mode' => $this->getEffectiveMode($event),
      'rsvp' => [
        'enabled' => in_array($eventType, ['rsvp'], TRUE),
        'configured' => TRUE,
        'capacity' => $availability['capacity'],
        'message' => $eventType === 'rsvp'
          ? ($availability['capacity'] > 0
            ? (string) $this->t('RSVP enabled with @count person capacity.', ['@count' => $availability['capacity']])
            : (string) $this->t('RSVP enabled with unlimited capacity.'))
          : (string) $this->t('RSVP not enabled.'),
      ],
      'tickets' => [
        'enabled' => in_array($eventType, ['paid'], TRUE) || ($eventType === 'rsvp' && $hasProduct),
        'configured' => $hasProduct,
        'product' => $this->getLinkedProduct($event),
        'message' => $hasProduct
          ? (string) $this->t('Ticket product linked.')
          : (string) $this->t('No ticket product linked yet.'),
      ],
      'external' => [
        'enabled' => $eventType === 'external',
        'configured' => $hasExternalUrl,
        'url' => $this->getExternalUrl($event),
        'message' => $eventType === 'external'
          ? ($hasExternalUrl
            ? (string) $this->t('External link configured.')
            : (string) $this->t('External URL not set.'))
          : (string) $this->t('Not using external link mode.'),
      ],
    ];
  }

  /**
   * Builds a ticket purchase CTA.
   */
  private function buildTicketCta(NodeInterface $event): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Buy Tickets'),
      '#url' => Url::fromRoute('myeventlane_commerce.event_book', ['node' => $event->id()]),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn-primary', 'mel-cta', 'mel-cta--tickets'],
      ],
    ];
  }

  /**
   * Builds an RSVP CTA.
   *
   * Uses the unified Commerce booking route for both RSVP and tickets.
   */
  private function buildRsvpCta(NodeInterface $event, bool $secondary = FALSE): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('RSVP Now'),
      '#url' => Url::fromRoute('myeventlane_commerce.event_book', ['node' => $event->id()]),
      '#attributes' => [
        'class' => [
          'mel-btn',
          $secondary ? 'mel-btn-secondary' : 'mel-btn-primary',
          'mel-cta',
          'mel-cta--rsvp',
        ],
      ],
    ];
  }

  /**
   * Builds a waitlist CTA.
   *
   * Links to the dedicated waitlist signup form.
   */
  private function buildWaitlistCta(NodeInterface $event): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Join Waitlist'),
      '#url' => Url::fromRoute('myeventlane_event_attendees.waitlist_signup', ['node' => $event->id()]),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn-secondary', 'mel-cta', 'mel-cta--waitlist'],
      ],
    ];
  }

  /**
   * Builds an external link CTA.
   */
  private function buildExternalCta(NodeInterface $event): array {
    $url = $this->getExternalUrl($event);

    return [
      '#type' => 'link',
      '#title' => $this->t('Get Tickets'),
      '#url' => $url ? Url::fromUri($url) : Url::fromRoute('<none>'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn-primary', 'mel-cta', 'mel-cta--external'],
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
      ],
    ];
  }

  /**
   * Builds a "coming soon" placeholder CTA.
   */
  private function buildComingSoonCta(): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $this->t('Coming Soon'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn-disabled', 'mel-cta', 'mel-cta--coming-soon'],
      ],
    ];
  }

  /**
   * Builds a "past event" placeholder.
   */
  private function buildPastEventCta(): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $this->t('Event Ended'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn-disabled', 'mel-cta', 'mel-cta--past'],
      ],
    ];
  }

  /**
   * Checks if the event date has passed.
   */
  private function isEventPast(NodeInterface $event): bool {
    $endDate = $this->getFieldValue($event, 'field_event_end');
    if (!$endDate) {
      $endDate = $this->getFieldValue($event, 'field_event_start');
    }

    if (!$endDate) {
      return FALSE;
    }

    try {
      $eventTime = new \DateTimeImmutable($endDate, new \DateTimeZone('UTC'));
      return $eventTime->getTimestamp() < $this->time->getRequestTime();
    }
    catch (\Exception) {
      return FALSE;
    }
  }

  /**
   * Gets a field value from the event.
   */
  private function getFieldValue(NodeInterface $event, string $fieldName): ?string {
    if (!$event->hasField($fieldName) || $event->get($fieldName)->isEmpty()) {
      return NULL;
    }

    return $event->get($fieldName)->value;
  }

  /**
   * Checks if the event has a linked Commerce product.
   */
  private function hasLinkedProduct(NodeInterface $event): bool {
    return $this->getLinkedProduct($event) !== NULL;
  }

  /**
   * Gets the linked Commerce product.
   */
  private function getLinkedProduct(NodeInterface $event): ?object {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return NULL;
    }

    $entities = $event->get('field_product_target')->referencedEntities();
    return $entities[0] ?? NULL;
  }

  /**
   * Checks if the event has an external URL configured.
   */
  private function hasExternalUrl(NodeInterface $event): bool {
    return $this->getExternalUrl($event) !== NULL;
  }

  /**
   * Gets the external URL for the event.
   */
  private function getExternalUrl(NodeInterface $event): ?string {
    if (!$event->hasField('field_external_url') || $event->get('field_external_url')->isEmpty()) {
      return NULL;
    }

    $link = $event->get('field_external_url')->first();
    return $link?->getUrl()?->toString();
  }

  /**
   * Checks if a product is a hybrid product (has paid + free variations).
   *
   * @param object $product
   *   The Commerce product entity.
   *
   * @return bool
   *   TRUE if product has multiple variations or any paid variation.
   */
  private function isHybridProduct(object $product): bool {
    if (!method_exists($product, 'getVariations')) {
      return FALSE;
    }

    $variations = $product->getVariations();

    // Multiple variations = likely hybrid.
    if (count($variations) > 1) {
      return TRUE;
    }

    // Single variation: check if it's paid.
    if (count($variations) === 1) {
      $variation = reset($variations);
      if (method_exists($variation, 'getPrice')) {
        $price = $variation->getPrice();
        // If price > 0, it's a paid product.
        return $price && $price->getNumber() > 0;
      }
    }

    return FALSE;
  }

}
