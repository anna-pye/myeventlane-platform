<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use CommerceGuys\Intl\Formatter\CurrencyFormatter as IntlCurrencyFormatter;
use CommerceGuys\Intl\NumberFormat\NumberFormatRepositoryInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\Repository\CurrencyRepositoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_commerce\Form\TicketSelectionForm;
use Drupal\myeventlane_commerce\Service\TicketAvailabilityService;
use Drupal\myeventlane_event_attendees\Service\AttendanceWaitlistManager;
use Drupal\myeventlane_event_state\Service\EventStateResolverInterface;
use Drupal\myeventlane_rsvp\Form\RsvpPublicForm;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the canonical public booking flow for an event.
 *
 * This service decides the booking mode before controllers or templates choose
 * CTAs, forms, or submit outcomes. Enforcement still remains in the active
 * forms/services until those callers are migrated to this resolver.
 */
final class BookingFlowResolver {

  public const MODE_PAID = 'paid';
  public const MODE_RSVP = 'rsvp';
  public const MODE_EXTERNAL = 'external';
  public const MODE_UNAVAILABLE = 'unavailable';

  public const AVAILABILITY_AVAILABLE = 'available';
  public const AVAILABILITY_SOLD_OUT = 'sold_out';
  public const AVAILABILITY_NOT_STARTED = 'not_started';
  public const AVAILABILITY_ENDED = 'ended';
  public const AVAILABILITY_UNAVAILABLE = 'unavailable';

  public const SUBMIT_OUTCOME_ADD_TO_CART = 'add_to_cart';
  public const SUBMIT_OUTCOME_RSVP_CONFIRM = 'rsvp_confirm';
  public const SUBMIT_OUTCOME_RSVP_DONATION_CHECKOUT = 'rsvp_donation_checkout';
  public const SUBMIT_OUTCOME_EXTERNAL_PROVIDER = 'external_provider';
  public const SUBMIT_OUTCOME_NONE = 'none';

  private ?IntlCurrencyFormatter $displayCurrencyFormatter = NULL;

  public function __construct(
    private readonly EventModeManager $modeManager,
    private readonly EventStateResolverInterface $stateResolver,
    private readonly TicketAvailabilityService $ticketAvailability,
    private readonly TicketTypeManager $ticketTypeManager,
    private readonly NumberFormatRepositoryInterface $numberFormatRepository,
    private readonly CurrencyRepositoryInterface $currencyRepository,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerInterface $logger,
    private readonly ?AttendanceWaitlistManager $waitlistManager = NULL,
  ) {}

  /**
   * Returns the canonical public booking mode.
   *
   * @return string
   *   One of self::MODE_PAID, self::MODE_RSVP, self::MODE_EXTERNAL, or
   *   self::MODE_UNAVAILABLE.
   */
  public function getBookingMode(NodeInterface $event): string {
    if ($event->bundle() !== 'event' || !$event->isPublished()) {
      return self::MODE_UNAVAILABLE;
    }

    if ($this->isExplicitExternalEvent($event)) {
      return self::MODE_EXTERNAL;
    }

    $legacyMode = $this->modeManager->getEffectiveMode($event);
    if ($legacyMode === EventModeManager::MODE_EXTERNAL) {
      return self::MODE_EXTERNAL;
    }

    if ($this->hasPaidTicketTypes($event)) {
      return self::MODE_PAID;
    }

    if (in_array($legacyMode, [EventModeManager::MODE_PAID, EventModeManager::MODE_BOTH], TRUE)) {
      return self::MODE_PAID;
    }

    if ($legacyMode === EventModeManager::MODE_RSVP) {
      return self::MODE_RSVP;
    }

    return self::MODE_UNAVAILABLE;
  }

  /**
   * Returns the primary CTA decision for this event.
   *
   * @return array{label: string, url: string, type: string, disabled: bool, reason: string|null}
   *   CTA data for callers to render. Type is the transport state: internal,
   *   external, or disabled.
   */
  public function getPrimaryCta(NodeInterface $event): array {
    $mode = $this->getBookingMode($event);

    if ($mode === self::MODE_EXTERNAL) {
      $externalUrl = $this->getExternalUrlString($event);
      if ($externalUrl === NULL) {
        return [
          'label' => 'Booking unavailable',
          'url' => '',
          'type' => 'disabled',
          'disabled' => TRUE,
          'reason' => 'external_missing_url',
        ];
      }

      return [
        'label' => 'Get tickets',
        'url' => $externalUrl,
        'type' => 'external',
        'disabled' => FALSE,
        'reason' => NULL,
      ];
    }

    $availability = $this->getAvailabilityState($event);

    if ($mode === self::MODE_UNAVAILABLE) {
      return [
        'label' => '',
        'url' => '',
        'type' => 'disabled',
        'disabled' => TRUE,
        'reason' => 'Booking is unavailable for this event.',
      ];
    }

    if ($availability === self::AVAILABILITY_SOLD_OUT) {
      if ($mode === self::MODE_RSVP && $this->isRsvpWaitlistAvailable($event)) {
        $waitlistUrl = Url::fromRoute('myeventlane_event_attendees.waitlist_signup', [
          'node' => (int) $event->id(),
        ])->toString();
        return [
          'label' => 'Join waitlist',
          'url' => $waitlistUrl,
          'type' => 'internal',
          'disabled' => FALSE,
          'reason' => NULL,
        ];
      }

      return [
        'label' => 'Sold out',
        'url' => '',
        'type' => 'disabled',
        'disabled' => TRUE,
        'reason' => 'This event is sold out.',
      ];
    }

    if ($availability === self::AVAILABILITY_NOT_STARTED) {
      $salesStart = $mode === self::MODE_PAID ? $this->stateResolver->getSalesStart($event) : NULL;
      $formatted = $salesStart ? date('F j, Y g:ia', $salesStart) : NULL;
      $label = $mode === self::MODE_PAID && $formatted
        ? 'Sales open on ' . $formatted
        : ($mode === self::MODE_PAID ? 'Sales opening soon' : 'Booking opening soon');
      return [
        'label' => $label,
        'url' => '',
        'type' => 'disabled',
        'disabled' => TRUE,
        'reason' => 'Booking is not open yet.',
      ];
    }

    if ($availability === self::AVAILABILITY_ENDED) {
      return [
        'label' => 'Event ended',
        'url' => '',
        'type' => 'disabled',
        'disabled' => TRUE,
        'reason' => 'This event has ended.',
      ];
    }

    if ($availability === self::AVAILABILITY_UNAVAILABLE) {
      return [
        'label' => '',
        'url' => '',
        'type' => 'disabled',
        'disabled' => TRUE,
        'reason' => 'Booking is unavailable for this event.',
      ];
    }

    $routeParameters = ['node' => (int) $event->id()];
    $bookingUrl = Url::fromRoute('myeventlane_commerce.event_book', $routeParameters)->toString();

    return [
      'label' => $mode === self::MODE_PAID ? 'Get your tickets' : 'RSVP free',
      'url' => $bookingUrl,
      'type' => 'internal',
      'disabled' => FALSE,
      'reason' => NULL,
    ];
  }

  /**
   * Resolves the booking form class for the event.
   *
   * @return class-string|null
   *   TicketSelectionForm, RsvpPublicForm, or NULL.
   */
  public function resolveBookingForm(NodeInterface $event): ?string {
    if ($this->getAvailabilityState($event) !== self::AVAILABILITY_AVAILABLE) {
      return NULL;
    }

    return match ($this->getBookingMode($event)) {
      self::MODE_PAID => TicketSelectionForm::class,
      self::MODE_RSVP => RsvpPublicForm::class,
      default => NULL,
    };
  }

  /**
   * Returns public pricing display data for the resolved booking flow.
   *
   * @return array{label: string, is_free: bool, type: string}|null
   *   Display pricing data, or NULL when booking is unavailable or pricing
   *   cannot be resolved safely.
   */
  public function getDisplayPricing(NodeInterface $event): ?array {
    $mode = $this->getBookingMode($event);

    if ($mode === self::MODE_UNAVAILABLE) {
      return NULL;
    }

    if ($mode === self::MODE_RSVP) {
      return [
        'label' => 'Free RSVP',
        'is_free' => TRUE,
        'type' => self::MODE_RSVP,
      ];
    }

    if ($mode === self::MODE_EXTERNAL) {
      return [
        'label' => 'External',
        'is_free' => FALSE,
        'type' => self::MODE_EXTERNAL,
      ];
    }

    if ($mode !== self::MODE_PAID) {
      return NULL;
    }

    $prices = $this->ticketTypeManager->loadPublishedPaidTicketPrices($event);
    if ($prices === []) {
      $this->logger->warning('Paid display pricing could not resolve any paid ticket prices for event @event_id.', [
        '@event_id' => (string) $event->id(),
      ]);
      return NULL;
    }

    $currencyCode = $prices[0]->getCurrencyCode();
    foreach ($prices as $price) {
      if (strtoupper($price->getCurrencyCode()) !== strtoupper($currencyCode)) {
        $this->logger->error('Paid display pricing found mixed currencies for event @event_id.', [
          '@event_id' => (string) $event->id(),
        ]);
        return [
          'label' => 'Multiple prices',
          'is_free' => FALSE,
          'type' => self::MODE_PAID,
        ];
      }
    }

    usort($prices, static fn (Price $a, Price $b): int => $a->compareTo($b));
    $lowest = $prices[0];
    $lowestNonZero = NULL;
    $hasMultiplePrices = FALSE;
    foreach ($prices as $price) {
      if ($price->compareTo($lowest) !== 0) {
        $hasMultiplePrices = TRUE;
      }
      if (!$price->isZero() && !($lowestNonZero instanceof Price)) {
        $lowestNonZero = $price;
      }
    }

    if ($lowest->isZero()) {
      $label = $lowestNonZero instanceof Price
        ? 'From ' . $this->formatDisplayPrice($lowestNonZero)
        : 'Free';
      $isFree = !($lowestNonZero instanceof Price);
    }
    else {
      $label = ($hasMultiplePrices ? 'From ' : '') . $this->formatDisplayPrice($lowest);
      $isFree = FALSE;
    }

    return [
      'label' => $label,
      'is_free' => $isFree,
      'type' => self::MODE_PAID,
    ];
  }

  /**
   * Returns the public booking availability state.
   *
   * @return string
   *   One of self::AVAILABILITY_AVAILABLE, self::AVAILABILITY_SOLD_OUT,
   *   self::AVAILABILITY_NOT_STARTED, self::AVAILABILITY_ENDED, or
   *   self::AVAILABILITY_UNAVAILABLE.
   */
  public function getAvailabilityState(NodeInterface $event): string {
    if ($event->bundle() !== 'event' || !$event->isPublished()) {
      return self::AVAILABILITY_UNAVAILABLE;
    }

    $mode = $this->getBookingMode($event);
    if ($mode === self::MODE_EXTERNAL) {
      return self::AVAILABILITY_AVAILABLE;
    }
    if ($mode === self::MODE_UNAVAILABLE) {
      return self::AVAILABILITY_UNAVAILABLE;
    }

    $state = $this->stateResolver->resolveState($event);
    if ($state === 'draft') {
      return self::AVAILABILITY_UNAVAILABLE;
    }
    if ($state === 'scheduled') {
      return self::AVAILABILITY_NOT_STARTED;
    }
    if ($state === 'ended') {
      return self::AVAILABILITY_ENDED;
    }
    if (in_array($state, ['cancelled', 'archived'], TRUE)) {
      return self::AVAILABILITY_UNAVAILABLE;
    }
    if ($state === 'sold_out') {
      return self::AVAILABILITY_SOLD_OUT;
    }

    return match ($mode) {
      self::MODE_PAID => $this->resolvePaidAvailability($event),
      self::MODE_RSVP => $this->resolveRsvpAvailability($event),
      default => self::AVAILABILITY_UNAVAILABLE,
    };
  }

  /**
   * Returns whether RSVP submission should be allowed.
   */
  public function isRsvpAllowed(NodeInterface $event): bool {
    return $this->getBookingMode($event) === self::MODE_RSVP
      && $this->getAvailabilityState($event) === self::AVAILABILITY_AVAILABLE;
  }

  /**
   * Returns whether paid tickets are required for this event's booking flow.
   */
  public function requiresPaidTickets(NodeInterface $event): bool {
    return $this->getBookingMode($event) === self::MODE_PAID;
  }

  /**
   * Returns the current submit outcome contract for the resolved flow.
   *
   * RSVP donations are conditional on submitted form values, so RSVP exposes
   * both the normal confirmation and optional donation checkout outcomes.
   *
   * @return array{primary: string, possible: list<string>}
   */
  public function getSubmitOutcome(NodeInterface $event): array {
    return match ($this->getBookingMode($event)) {
      self::MODE_PAID => [
        'primary' => self::SUBMIT_OUTCOME_ADD_TO_CART,
        'possible' => [self::SUBMIT_OUTCOME_ADD_TO_CART],
      ],
      self::MODE_RSVP => [
        'primary' => self::SUBMIT_OUTCOME_RSVP_CONFIRM,
        'possible' => [
          self::SUBMIT_OUTCOME_RSVP_CONFIRM,
          self::SUBMIT_OUTCOME_RSVP_DONATION_CHECKOUT,
        ],
      ],
      self::MODE_EXTERNAL => [
        'primary' => self::SUBMIT_OUTCOME_EXTERNAL_PROVIDER,
        'possible' => [self::SUBMIT_OUTCOME_EXTERNAL_PROVIDER],
      ],
      default => [
        'primary' => self::SUBMIT_OUTCOME_NONE,
        'possible' => [self::SUBMIT_OUTCOME_NONE],
      ],
    };
  }

  /**
   * Returns TRUE when the event has configured paid ticket type references.
   */
  private function hasPaidTicketTypes(NodeInterface $event): bool {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return FALSE;
    }

    foreach ($event->get('field_ticket_types')->referencedEntities() as $ticketType) {
      if ($ticketType instanceof TicketTypeInterface && $ticketType->getTicketKind() === 'paid') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Formats a price for compact public event display.
   *
   * Uses Commerce Guys intl directly with repository services — not the
   * commerce_price.currency_formatter container service — so node preprocess
   * cannot recurse into formatter construction (lazy proxies still call the
   * same service during partial container builds).
   */
  private function formatDisplayPrice(Price $price): string {
    return $this->getDisplayCurrencyFormatter()->format(
      $price->getNumber(),
      $price->getCurrencyCode(),
      [
        'currency_display' => 'symbol',
        'minimum_fraction_digits' => 0,
        'maximum_fraction_digits' => 2,
      ]
    );
  }

  private function getDisplayCurrencyFormatter(): IntlCurrencyFormatter {
    if ($this->displayCurrencyFormatter === NULL) {
      $this->displayCurrencyFormatter = new IntlCurrencyFormatter(
        $this->numberFormatRepository,
        $this->currencyRepository,
        [
          'locale' => $this->localeForFormatting(),
          'maximum_fraction_digits' => 6,
          'rounding_mode' => 'none',
        ]
      );
    }
    return $this->displayCurrencyFormatter;
  }

  /**
   * Maps Drupal language IDs to BCP 47-style locales for intl formatting.
   */
  private function localeForFormatting(): string {
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    if (str_contains($langcode, '-')) {
      return $langcode;
    }

    return match ($langcode) {
      'en' => 'en-US',
      'fr' => 'fr-FR',
      'de' => 'de-DE',
      'es' => 'es-ES',
      'it' => 'it-IT',
      'pt-br' => 'pt-BR',
      'pt-pt' => 'pt-PT',
      'nl' => 'nl-NL',
      'ja' => 'ja-JP',
      default => $langcode . '-' . strtoupper($langcode),
    };
  }

  /**
   * Returns TRUE when the event is explicitly configured as external.
   */
  private function isExplicitExternalEvent(NodeInterface $event): bool {
    return $event->hasField('field_event_type')
      && !$event->get('field_event_type')->isEmpty()
      && $event->get('field_event_type')->value === self::MODE_EXTERNAL;
  }

  /**
   * Resolves paid availability using existing ticket services.
   */
  private function resolvePaidAvailability(NodeInterface $event): string {
    $ticketAvailability = $this->modeManager->getTicketAvailability($event);
    if (empty($ticketAvailability['available'])) {
      return self::AVAILABILITY_NOT_STARTED;
    }

    $product = $ticketAvailability['product'] ?? NULL;
    if (!$product instanceof ProductInterface) {
      return self::AVAILABILITY_NOT_STARTED;
    }

    try {
      $context = $this->ticketAvailability->buildPublicAccessContext($event);
      $variations = $this->ticketAvailability->filterPurchasableVariations($event, $product, $context);
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not resolve paid booking availability for event @event_id: @message', [
        '@event_id' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return self::AVAILABILITY_UNAVAILABLE;
    }

    if ($variations === []) {
      return self::AVAILABILITY_SOLD_OUT;
    }

    return self::AVAILABILITY_AVAILABLE;
  }

  /**
   * Resolves RSVP availability using the existing mode manager.
   */
  private function resolveRsvpAvailability(NodeInterface $event): string {
    $rsvpAvailability = $this->modeManager->getRsvpAvailability($event);
    if (!empty($rsvpAvailability['available'])) {
      return self::AVAILABILITY_AVAILABLE;
    }
    if (isset($rsvpAvailability['spots_remaining']) && $rsvpAvailability['spots_remaining'] === 0) {
      return self::AVAILABILITY_SOLD_OUT;
    }
    return self::AVAILABILITY_NOT_STARTED;
  }

  /**
   * Returns the external provider URL for off-platform booking.
   */
  private function getExternalUrlString(NodeInterface $event): ?string {
    if (!$event->hasField('field_external_url') || $event->get('field_external_url')->isEmpty()) {
      return NULL;
    }

    $link = $event->get('field_external_url')->first();
    $uri = trim((string) ($link?->uri ?? ''));
    if ($uri === '' || !UrlHelper::isValid($uri, TRUE)) {
      $this->logger->warning('External booking URL is missing or invalid for event @event_id.', [
        '@event_id' => (string) $event->id(),
      ]);
      return NULL;
    }

    try {
      $url = trim((string) $link?->getUrl()?->toString());
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->warning('External booking URL could not be generated for event @event_id: @message', [
        '@event_id' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    if ($url === '' || !UrlHelper::isValid($url, TRUE)) {
      $this->logger->warning('Generated external booking URL is invalid for event @event_id.', [
        '@event_id' => (string) $event->id(),
      ]);
      return NULL;
    }

    return $url;
  }

  /**
   * Returns TRUE when RSVP waitlist signup is offered for a sold-out event.
   */
  private function isRsvpWaitlistAvailable(NodeInterface $event): bool {
    if (!$this->waitlistManager instanceof AttendanceWaitlistManager) {
      return FALSE;
    }

    return $this->waitlistManager->isWaitlistEnabled($event);
  }

}
