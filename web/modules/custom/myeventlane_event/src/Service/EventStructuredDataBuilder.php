<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\Url;
use Drupal\myeventlane_event_state\Service\EventStateResolver;
use Drupal\node\NodeInterface;

/**
 * Builds schema.org Event JSON-LD for public canonical event pages.
 */
final class EventStructuredDataBuilder {

  public function __construct(
    private readonly PublicEventVisibility $publicEventVisibility,
    private readonly EventMetadataBuilderInterface $metadataBuilder,
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

    $metadata = $this->metadataBuilder->build($event);
    $data = [
      '@context' => 'https://schema.org',
      '@type' => 'Event',
      'name' => $metadata['title'],
      'url' => $metadata['canonical_url'],
      'eventStatus' => $this->resolveEventStatus($event),
      'description' => $metadata['description'],
      'startDate' => $metadata['start_iso'],
      'endDate' => $metadata['end_iso'],
    ];

    $location = $metadata['location'] ?? NULL;
    if (is_array($location)) {
      $place = array_filter([
        '@type' => 'Place',
        'name' => $location['name'] ?? '',
        'address' => $location['address'] ?? NULL,
      ], static fn (mixed $value): bool => $value !== NULL && $value !== '');
      if (count($place) > 1) {
        $data['location'] = $place;
        $data['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';
      }
    }

    $image = $metadata['image'] ?? NULL;
    if (is_array($image) && !empty($image['url'])) {
      $data['image'] = (string) $image['url'];
    }

    $organizer = $metadata['organizer'] ?? NULL;
    if (is_array($organizer) && !empty($organizer['name'])) {
      $data['organizer'] = array_filter([
        '@type' => 'Organization',
        'name' => $organizer['name'],
        'url' => $organizer['url'] ?? NULL,
      ], static fn (mixed $value): bool => $value !== NULL && $value !== '');
    }

    $booking = is_array($metadata['booking'] ?? NULL) ? $metadata['booking'] : [];
    $offer = $this->resolveOffer($event, $booking);
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
   * Resolves the public booking offer from buyer-visible pricing.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event being described.
   * @param array<string, mixed> $booking
   *   Shared booking metadata.
   *
   * @return array<string, mixed>|null
   *   The schema.org offer, or NULL when booking is unavailable.
   */
  private function resolveOffer(NodeInterface $event, array $booking): ?array {
    $mode = (string) ($booking['mode'] ?? BookingFlowResolver::MODE_UNAVAILABLE);
    if (in_array($mode, [BookingFlowResolver::MODE_UNAVAILABLE, BookingFlowResolver::MODE_EXTERNAL], TRUE)) {
      return NULL;
    }

    $offer = [
      '@type' => 'Offer',
      'url' => Url::fromRoute('myeventlane_commerce.event_book', ['node' => $event->id()], [
        'absolute' => TRUE,
      ])->toString(),
      'availability' => ($booking['availability'] ?? '') === BookingFlowResolver::AVAILABILITY_SOLD_OUT
        ? 'https://schema.org/SoldOut'
        : 'https://schema.org/InStock',
    ];

    if ($mode === BookingFlowResolver::MODE_RSVP) {
      $offer['price'] = '0';
      $offer['priceCurrency'] = 'AUD';
      return $offer;
    }

    $pricing = $booking['pricing'] ?? NULL;
    if ($mode === BookingFlowResolver::MODE_PAID && is_array($pricing)) {
      if (isset($pricing['price_number'], $pricing['currency_code'])) {
        $offer['price'] = (string) $pricing['price_number'];
        $offer['priceCurrency'] = (string) $pricing['currency_code'];
      }
    }

    return $offer;
  }

}
