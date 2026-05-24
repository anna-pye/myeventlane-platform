<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\address\Plugin\Field\FieldType\AddressItem;
use Drupal\commerce_price\Price;
use Drupal\Core\Url;
use Drupal\myeventlane_event_state\Service\EventStateResolver;
use Drupal\node\NodeInterface;

/**
 * Builds schema.org Event JSON-LD for public canonical event pages.
 */
final class EventStructuredDataBuilder {

  private const DISPLAY_TIMEZONE = 'Australia/Sydney';

  public function __construct(
    private readonly PublicEventVisibility $publicEventVisibility,
    private readonly BookingFlowResolver $bookingFlowResolver,
    private readonly TicketTypeManager $ticketTypeManager,
  ) {}

  /**
   * Returns render-safe JSON-LD data, or NULL when the event is not public SEO.
   *
   * @return array<string, mixed>|null
   */
  public function build(NodeInterface $event): ?array {
    if (!$this->publicEventVisibility->isPubliclyListable($event)) {
      return NULL;
    }

    $data = [
      '@context' => 'https://schema.org',
      '@type' => 'Event',
      'name' => $event->label(),
      'url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'eventStatus' => $this->resolveEventStatus($event),
    ];

    $description = $this->resolveDescription($event);
    if ($description !== '') {
      $data['description'] = $description;
    }

    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $start = $event->get('field_event_start')->date;
      if ($start !== NULL) {
        $data['startDate'] = $this->formatTimestamp($start->getTimestamp());
      }
    }

    if ($event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()) {
      $end = $event->get('field_event_end')->date;
      if ($end !== NULL) {
        $data['endDate'] = $this->formatTimestamp($end->getTimestamp());
      }
    }

    $location = $this->resolveLocation($event);
    if ($location !== NULL) {
      $data['location'] = $location;
    }

    $offer = $this->resolveOffer($event);
    if ($offer !== NULL) {
      $data['offers'] = $offer;
    }

    return array_filter($data, static fn (mixed $value): bool => $value !== NULL && $value !== '');
  }

  private function resolveEventStatus(NodeInterface $event): string {
    if ($event->hasField('field_event_state') && !$event->get('field_event_state')->isEmpty()) {
      $state = (string) $event->get('field_event_state')->value;
      if ($state === EventStateResolver::STATE_CANCELLED) {
        return 'https://schema.org/EventCancelled';
      }
    }

    return 'https://schema.org/EventScheduled';
  }

  private function resolveDescription(NodeInterface $event): string {
    if ($event->hasField('field_event_summary') && !$event->get('field_event_summary')->isEmpty()) {
      return trim(strip_tags((string) $event->get('field_event_summary')->value));
    }
    if ($event->hasField('field_event_intro') && !$event->get('field_event_intro')->isEmpty()) {
      return trim(strip_tags((string) $event->get('field_event_intro')->value));
    }
    if ($event->hasField('body') && !$event->get('body')->isEmpty()) {
      return trim(strip_tags((string) $event->get('body')->value));
    }
    return '';
  }

  /**
   * @return array<string, mixed>|null
   */
  private function resolveLocation(NodeInterface $event): ?array {
    $name = '';
    if ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      $name = trim((string) $event->get('field_venue_name')->value);
    }

    $place = ['@type' => 'Place'];
    if ($name !== '') {
      $place['name'] = $name;
    }

    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $address = $event->get('field_location')->first();
      if ($address instanceof AddressItem) {
        $addressData = array_filter([
          '@type' => 'PostalAddress',
          'streetAddress' => $address->getAddressLine1(),
          'addressLocality' => $address->getLocality(),
          'addressRegion' => $address->getAdministrativeArea(),
          'postalCode' => $address->getPostalCode(),
          'addressCountry' => $address->getCountryCode(),
        ]);
        if (count($addressData) > 1) {
          $place['address'] = $addressData;
        }
        if ($name === '' && $address->getLocality() !== NULL) {
          $place['name'] = $address->getLocality();
        }
      }
    }

    return isset($place['name']) || isset($place['address']) ? $place : NULL;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function resolveOffer(NodeInterface $event): ?array {
    $mode = $this->bookingFlowResolver->getBookingMode($event);
    if ($mode === BookingFlowResolver::MODE_UNAVAILABLE) {
      return NULL;
    }

    if ($mode === BookingFlowResolver::MODE_EXTERNAL) {
      return NULL;
    }

    $bookUrl = Url::fromRoute('myeventlane_commerce.event_book', ['node' => $event->id()], [
      'absolute' => TRUE,
    ])->toString();

    $offer = [
      '@type' => 'Offer',
      'url' => $bookUrl,
      'availability' => $this->resolveOfferAvailability($event),
    ];

    if ($mode === BookingFlowResolver::MODE_RSVP) {
      $offer['price'] = '0';
      $offer['priceCurrency'] = 'AUD';
      return $offer;
    }

    if ($mode !== BookingFlowResolver::MODE_PAID) {
      return $offer;
    }

    $prices = $this->ticketTypeManager->loadPublishedPaidTicketPrices($event);
    if ($prices === []) {
      return $offer;
    }

    usort($prices, static fn (Price $a, Price $b): int => $a->compareTo($b));
    $lowest = $prices[0];
    $offer['price'] = $lowest->getNumber();
    $offer['priceCurrency'] = $lowest->getCurrencyCode();

    return $offer;
  }

  private function resolveOfferAvailability(NodeInterface $event): string {
    if ($this->bookingFlowResolver->getAvailabilityState($event) === BookingFlowResolver::AVAILABILITY_SOLD_OUT) {
      return 'https://schema.org/SoldOut';
    }

    return 'https://schema.org/InStock';
  }

  private function formatTimestamp(int $timestamp): string {
    $utc = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
    return $utc->setTimezone(new \DateTimeZone(self::DISPLAY_TIMEZONE))->format(\DateTimeInterface::ATOM);
  }

}
