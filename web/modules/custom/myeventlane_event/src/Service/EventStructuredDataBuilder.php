<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\address\Plugin\Field\FieldType\AddressItem;
use Drupal\commerce_price\Price;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\myeventlane_event_state\Service\EventStateResolver;
use Drupal\node\NodeInterface;

/**
 * Builds schema.org Event JSON-LD for public canonical event pages.
 */
final class EventStructuredDataBuilder {

  public function __construct(
    private readonly PublicEventVisibility $publicEventVisibility,
    private readonly BookingFlowResolver $bookingFlowResolver,
    private readonly TicketTypeManager $ticketTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly EventDateTimeResolver $eventDateTime,
  ) {}

  /**
   * Returns render-safe JSON-LD data, or NULL when the event is not public SEO.
   *
   * @return array<string, mixed>|null
   *   The schema data, or NULL when the event must not be indexed.
   */
  public function build(NodeInterface $event): ?array {
    if (!$this->publicEventVisibility->isSeoIndexable($event)) {
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
      $start = $this->formatFieldValue((string) $event->get('field_event_start')->value, $event);
      if ($start !== NULL) {
        $data['startDate'] = $start;
      }
    }

    if ($event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()) {
      $end = $this->formatFieldValue((string) $event->get('field_event_end')->value, $event);
      if ($end !== NULL) {
        $data['endDate'] = $end;
      }
    }

    $location = $this->resolveLocation($event);
    if ($location !== NULL) {
      $data['location'] = $location;
      $data['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';
    }

    $image = $this->resolveImage($event);
    if ($image !== NULL) {
      $data['image'] = $image;
    }

    $organizer = $this->resolveOrganizer($event);
    if ($organizer !== NULL) {
      $data['organizer'] = $organizer;
    }

    $offer = $this->resolveOffer($event);
    if ($offer !== NULL) {
      $data['offers'] = $offer;
    }

    return array_filter($data, static fn (mixed $value): bool => $value !== NULL && $value !== '');
  }

  /**
   * Resolves the schema.org lifecycle status.
   */
  private function resolveEventStatus(NodeInterface $event): string {
    if ($event->hasField('field_event_state') && !$event->get('field_event_state')->isEmpty()) {
      $state = (string) $event->get('field_event_state')->value;
      if ($state === EventStateResolver::STATE_CANCELLED) {
        return 'https://schema.org/EventCancelled';
      }
    }

    return 'https://schema.org/EventScheduled';
  }

  /**
   * Resolves plain event copy for search metadata.
   */
  private function resolveDescription(NodeInterface $event): string {
    $description = '';
    if ($event->hasField('field_event_summary') && !$event->get('field_event_summary')->isEmpty()) {
      $description = trim(strip_tags((string) $event->get('field_event_summary')->value));
    }
    elseif ($event->hasField('field_event_intro') && !$event->get('field_event_intro')->isEmpty()) {
      $description = trim(strip_tags((string) $event->get('field_event_intro')->value));
    }
    elseif ($event->hasField('body') && !$event->get('body')->isEmpty()) {
      $description = trim(strip_tags((string) $event->get('body')->value));
    }

    if ($description !== '' && str_contains($description, '[date]') && $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $start = $this->parseFieldValue((string) $event->get('field_event_start')->value, $event);
      if ($start !== NULL) {
        $description = str_replace('[date]', $start->format('j F Y'), $description);
      }
    }

    return $description;
  }

  /**
   * Resolves the canonical event image URL.
   */
  private function resolveImage(NodeInterface $event): ?string {
    if (!$event->hasField('field_event_image') || $event->get('field_event_image')->isEmpty()) {
      return NULL;
    }

    $file = $event->get('field_event_image')->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }

    return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
  }

  /**
   * Resolves the public event organiser.
   *
   * @return array<string, string>|null
   *   The schema.org organiser, or NULL when none is available.
   */
  private function resolveOrganizer(NodeInterface $event): ?array {
    if (!$event->hasField('field_event_vendor') || $event->get('field_event_vendor')->isEmpty()) {
      return NULL;
    }

    $vendor = $event->get('field_event_vendor')->entity;
    if ($vendor === NULL || trim((string) $vendor->label()) === '') {
      return NULL;
    }

    return [
      '@type' => 'Organization',
      'name' => trim((string) $vendor->label()),
    ];
  }

  /**
   * Resolves the physical event location.
   *
   * @return array<string, mixed>|null
   *   The schema.org place, or NULL when no location is available.
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
   * Resolves the public booking offer.
   *
   * @return array<string, mixed>|null
   *   The schema.org offer, or NULL when booking is unavailable.
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

  /**
   * Resolves schema.org ticket availability.
   */
  private function resolveOfferAvailability(NodeInterface $event): string {
    if ($this->bookingFlowResolver->getAvailabilityState($event) === BookingFlowResolver::AVAILABILITY_SOLD_OUT) {
      return 'https://schema.org/SoldOut';
    }

    return 'https://schema.org/InStock';
  }

  /**
   * Formats a stored event wall-clock value with its event-local offset.
   */
  private function formatFieldValue(string $value, ?NodeInterface $event = NULL): ?string {
    return $this->parseFieldValue($value, $event)?->format(\DateTimeInterface::ATOM);
  }

  /**
   * Parses a stored event wall-clock value in the event timezone.
   */
  private function parseFieldValue(string $value, ?NodeInterface $event = NULL): ?\DateTimeImmutable {
    return $this->eventDateTime->parseValue($value, $event);
  }

}
