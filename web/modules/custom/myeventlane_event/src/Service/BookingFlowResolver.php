<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\Url;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\myeventlane_commerce\Form\TicketSelectionForm;
use Drupal\myeventlane_commerce\Service\TicketAvailabilityService;
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

  public function __construct(
    private readonly EventModeManager $modeManager,
    private readonly EventStateResolverInterface $stateResolver,
    private readonly TicketAvailabilityService $ticketAvailability,
    private readonly LoggerInterface $logger,
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

    $legacyMode = $this->modeManager->getEffectiveMode($event);
    if ($legacyMode === EventModeManager::MODE_EXTERNAL) {
      return self::MODE_EXTERNAL;
    }

    if ($this->hasTicketTypes($event)) {
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
   * @return array{cta_type: string, type: string|null, label: string, url: string|null, route: string|null, disabled: bool, reason?: string|null, route_parameters?: array<string, int|string>, external_url?: string|null, helper?: string|null, remaining?: int|null}
   *   CTA data for callers to render. External events deliberately return no
   *   MEL route.
   */
  public function getPrimaryCta(NodeInterface $event): array {
    $mode = $this->getBookingMode($event);

    if ($mode === self::MODE_EXTERNAL) {
      $externalUrl = $this->getExternalUrlString($event);
      return [
        'cta_type' => self::MODE_EXTERNAL,
        'type' => NULL,
        'label' => 'View details',
        'url' => $externalUrl,
        'route' => NULL,
        'disabled' => $externalUrl === NULL,
        'external_url' => $externalUrl,
        'reason' => $externalUrl === NULL ? 'External booking URL is not configured.' : NULL,
        'helper' => NULL,
        'remaining' => NULL,
      ];
    }

    $availability = $this->getAvailabilityState($event);
    $ctaType = $mode === self::MODE_UNAVAILABLE ? 'none' : $mode;

    if ($mode === self::MODE_UNAVAILABLE) {
      return [
        'cta_type' => 'none',
        'type' => NULL,
        'label' => '',
        'url' => NULL,
        'route' => NULL,
        'disabled' => TRUE,
        'reason' => 'Booking is unavailable for this event.',
        'helper' => NULL,
        'remaining' => NULL,
      ];
    }

    if ($availability === self::AVAILABILITY_SOLD_OUT) {
      return [
        'cta_type' => $ctaType,
        'type' => self::AVAILABILITY_SOLD_OUT,
        'label' => 'Sold out',
        'url' => NULL,
        'route' => NULL,
        'disabled' => TRUE,
        'reason' => 'This event is sold out.',
        'helper' => NULL,
        'remaining' => 0,
      ];
    }

    if ($availability === self::AVAILABILITY_NOT_STARTED) {
      $salesStart = $mode === self::MODE_PAID ? $this->stateResolver->getSalesStart($event) : NULL;
      $formatted = $salesStart ? date('F j, Y g:ia', $salesStart) : NULL;
      return [
        'cta_type' => $ctaType,
        'type' => self::AVAILABILITY_NOT_STARTED,
        'label' => $mode === self::MODE_PAID && $formatted ? 'Sales open on ' . $formatted : ($mode === self::MODE_PAID ? 'Sales opening soon' : 'Booking opening soon'),
        'url' => NULL,
        'route' => NULL,
        'disabled' => TRUE,
        'reason' => 'Booking is not open yet.',
        'helper' => $formatted,
        'remaining' => NULL,
      ];
    }

    if ($availability === self::AVAILABILITY_ENDED) {
      return [
        'cta_type' => $ctaType,
        'type' => self::AVAILABILITY_ENDED,
        'label' => 'Event ended',
        'url' => NULL,
        'route' => NULL,
        'disabled' => TRUE,
        'reason' => 'This event has ended.',
        'helper' => NULL,
        'remaining' => NULL,
      ];
    }

    if ($availability === self::AVAILABILITY_UNAVAILABLE) {
      return [
        'cta_type' => $ctaType,
        'type' => self::AVAILABILITY_UNAVAILABLE,
        'label' => '',
        'url' => NULL,
        'route' => NULL,
        'disabled' => TRUE,
        'reason' => 'Booking is unavailable for this event.',
        'helper' => NULL,
        'remaining' => NULL,
      ];
    }

    $remaining = $this->getRemainingCount($event, $mode);
    $helper = NULL;
    if ($remaining !== NULL && $remaining > 0 && $remaining <= 10) {
      $helper = $mode === self::MODE_PAID
        ? 'Only ' . $remaining . ' tickets remaining'
        : 'Only ' . $remaining . ' spots left';
    }

    $routeParameters = ['node' => (int) $event->id()];
    $bookingUrl = Url::fromRoute('myeventlane_commerce.event_book', $routeParameters)->toString();

    return [
      'cta_type' => $ctaType,
      'type' => NULL,
      'label' => $mode === self::MODE_PAID ? 'Get your tickets' : 'RSVP free',
      'url' => $bookingUrl,
      'route' => 'myeventlane_commerce.event_book',
      'route_parameters' => $routeParameters,
      'disabled' => FALSE,
      'reason' => NULL,
      'helper' => $helper,
      'remaining' => $remaining,
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
   * Returns TRUE when the event has configured ticket type references.
   */
  private function hasTicketTypes(NodeInterface $event): bool {
    return $event->hasField('field_ticket_types')
      && !$event->get('field_ticket_types')->isEmpty();
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
      $variations = $this->ticketAvailability->filterPurchasableVariations($event, $product);
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
    return $link?->getUrl()?->toString();
  }

  /**
   * Returns a low-availability count, where existing services expose one.
   */
  private function getRemainingCount(NodeInterface $event, string $mode): ?int {
    if ($mode === self::MODE_PAID) {
      $availability = $this->modeManager->getTicketAvailability($event);
      return isset($availability['remaining']) ? (int) $availability['remaining'] : NULL;
    }

    if ($mode === self::MODE_RSVP) {
      $availability = $this->modeManager->getRsvpAvailability($event);
      return isset($availability['spots_remaining']) ? (int) $availability['spots_remaining'] : NULL;
    }

    return NULL;
  }

}
