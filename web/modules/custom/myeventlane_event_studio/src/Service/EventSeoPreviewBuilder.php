<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event\Service\BookingFlowResolver;
use Drupal\myeventlane_event\Service\EventMetadataBuilderInterface;
use Drupal\node\NodeInterface;

/**
 * Builds an honest preview and health check of public event metadata.
 */
final class EventSeoPreviewBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly EventMetadataBuilderInterface $metadataBuilder,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds preview values from the same facts used by public metadata.
   *
   * @return array<string, mixed>
   *   Search, social, and event search-health values.
   */
  public function build(NodeInterface $event, string $publicUrl): array {
    $metadata = $this->metadataBuilder->build($event, $publicUrl);
    $title = (string) $metadata['title'];
    $description = (string) $metadata['description'];
    $image = is_array($metadata['image'] ?? NULL) ? $metadata['image'] : NULL;
    $location = is_array($metadata['location'] ?? NULL) ? $metadata['location'] : NULL;
    $organizer = is_array($metadata['organizer'] ?? NULL) ? $metadata['organizer'] : NULL;
    $booking = is_array($metadata['booking'] ?? NULL) ? $metadata['booking'] : [];
    $pricing = is_array($booking['pricing'] ?? NULL) ? $booking['pricing'] : NULL;
    $scheduleValue = $this->scheduleValue($metadata);
    $scheduleStatus = empty($metadata['start_iso']) ? 'missing' : (empty($metadata['end_iso']) ? 'attention' : 'good');
    $locationValue = $location === NULL
      ? (string) $this->t('No venue or structured address is available.')
      : (string) ($location['display'] ?? '');
    $locationStatus = $location === NULL ? 'missing' : (!empty($location['complete']) ? 'good' : 'attention');
    $ticketingValue = $this->ticketingValue($booking, $pricing);
    $ticketingStatus = $this->ticketingStatus($booking, $pricing);

    $health = [
      $this->healthItem(
        (string) $this->t('Event title'),
        $title,
        $title === '' ? 'missing' : (mb_strlen($title . ' | ' . $metadata['site_name']) > 60 ? 'attention' : 'good'),
        $this->studioUrl($event, 'myeventlane_event_studio.workspace_details'),
      ),
      $this->healthItem(
        (string) $this->t('Search description'),
        $description === '' ? (string) $this->t('No event summary is available.') : (string) $metadata['search_description'],
        $description === '' ? 'missing' : (mb_strlen($description) < 50 || mb_strlen($description) > 160 ? 'attention' : 'good'),
        $this->studioUrl($event, 'myeventlane_event_studio.workspace_details'),
      ),
      $this->healthItem(
        (string) $this->t('Public URL'),
        (string) $metadata['canonical_url'],
        $metadata['canonical_url'] === '' ? 'missing' : 'good',
        $this->studioUrl($event, 'myeventlane_event_studio.workspace_settings'),
      ),
      $this->healthItem(
        (string) $this->t('Date, time and timezone'),
        $scheduleValue,
        $scheduleStatus,
        $this->studioUrl($event, 'myeventlane_event_studio.workspace_schedule'),
      ),
      $this->healthItem(
        (string) $this->t('Venue and address'),
        $locationValue,
        $locationStatus,
        $this->studioUrl($event, 'myeventlane_event_studio.workspace_venue'),
      ),
      $this->healthItem(
        (string) $this->t('Ticketing'),
        $ticketingValue,
        $ticketingStatus,
        $this->studioUrl($event, 'myeventlane_event_studio.workspace_tickets'),
      ),
      $this->healthItem(
        (string) $this->t('Organiser'),
        $organizer === NULL ? (string) $this->t('No organiser is connected.') : (string) ($organizer['name'] ?? ''),
        $organizer === NULL ? 'missing' : (empty($organizer['url']) ? 'attention' : 'good'),
        Url::fromRoute('myeventlane_vendor.console.settings_profile')->toString(),
      ),
      $this->healthItem(
        (string) $this->t('Share image'),
        $this->imageValue($image),
        $this->imageStatus($image, $title),
        $this->studioUrl($event, 'myeventlane_event_studio.workspace_images'),
      ),
    ];

    $warnings = [];
    if (($booking['mode'] ?? '') === BookingFlowResolver::MODE_PAID
      && preg_match('/\bfree\b/ui', (string) $metadata['copy_text']) === 1) {
      $warnings[] = [
        'message' => (string) $this->t('Your event copy mentions “free”, but paid tickets are active. Check that the description and ticketing agree.'),
        'edit_url' => $this->studioUrl($event, 'myeventlane_event_studio.workspace_content'),
        'edit_label' => (string) $this->t('Review event copy'),
      ];
    }

    return [
      'url' => $metadata['canonical_url'],
      'domain' => $metadata['domain'],
      'search_title' => $metadata['search_title'],
      'social_title' => $title,
      'search_description' => $metadata['search_description'],
      'social_description' => $metadata['social_description'],
      'image_url' => $image['url'] ?? NULL,
      'image_alt' => trim((string) ($image['alt'] ?? '')) ?: $title,
      'preview_details' => [
        [
          'label' => (string) $this->t('Date and time'),
          'value' => $scheduleValue,
          'status' => $scheduleStatus,
        ],
        [
          'label' => (string) $this->t('Venue'),
          'value' => $locationValue,
          'status' => $locationStatus,
        ],
      ],
      'health' => $health,
      'health_summary' => [
        'good' => count(array_filter($health, static fn (array $item): bool => $item['status'] === 'good')),
        'attention' => count(array_filter($health, static fn (array $item): bool => $item['status'] === 'attention')),
        'missing' => count(array_filter($health, static fn (array $item): bool => $item['status'] === 'missing')),
      ],
      'warnings' => $warnings,
    ];
  }

  /**
   * Builds a consistent health row.
   *
   * @return array<string, string>
   *   Health row values.
   */
  private function healthItem(string $label, string $value, string $status, string $editUrl): array {
    $statusLabel = match ($status) {
      'good' => (string) $this->t('Good'),
      'missing' => (string) $this->t('Missing'),
      default => (string) $this->t('Needs attention'),
    };

    return [
      'label' => $label,
      'value' => $value,
      'status' => $status,
      'status_label' => $statusLabel,
      'edit_url' => $editUrl,
      'edit_label' => (string) $this->t('Edit'),
    ];
  }

  /**
   * Formats a schedule without hiding the event timezone.
   *
   * @param array<string, mixed> $metadata
   *   Shared event metadata.
   */
  private function scheduleValue(array $metadata): string {
    $start = trim((string) ($metadata['start_display'] ?? ''));
    $end = trim((string) ($metadata['end_display'] ?? ''));
    $timezone = trim((string) ($metadata['timezone'] ?? ''));
    if ($start === '') {
      return (string) $this->t('No start date is available.');
    }

    $value = $end !== '' ? $start . ' – ' . $end : $start;
    return $timezone !== '' ? $value . ' · ' . $timezone : $value;
  }

  /**
   * Formats ticketing metadata for an organiser.
   *
   * @param array<string, mixed> $booking
   *   Booking metadata.
   * @param array<string, mixed>|null $pricing
   *   Buyer-visible pricing metadata.
   */
  private function ticketingValue(array $booking, ?array $pricing): string {
    $mode = (string) ($booking['mode'] ?? BookingFlowResolver::MODE_UNAVAILABLE);
    $availability = str_replace('_', ' ', (string) ($booking['availability'] ?? 'unavailable'));
    $label = trim((string) ($pricing['label'] ?? ''));
    if ($mode === BookingFlowResolver::MODE_UNAVAILABLE) {
      return (string) $this->t('Booking is unavailable.');
    }
    if ($label === '') {
      $label = ucfirst($mode);
    }
    return $label . ' · ' . ucfirst($availability);
  }

  /**
   * Resolves ticketing health from public booking behaviour.
   *
   * @param array<string, mixed> $booking
   *   Booking metadata.
   * @param array<string, mixed>|null $pricing
   *   Buyer-visible pricing metadata.
   */
  private function ticketingStatus(array $booking, ?array $pricing): string {
    $mode = (string) ($booking['mode'] ?? BookingFlowResolver::MODE_UNAVAILABLE);
    if ($mode === BookingFlowResolver::MODE_UNAVAILABLE) {
      return 'missing';
    }
    if ($mode === BookingFlowResolver::MODE_PAID && !isset($pricing['price_number'], $pricing['currency_code'])) {
      return 'attention';
    }
    return ($booking['availability'] ?? '') === BookingFlowResolver::AVAILABILITY_AVAILABLE ? 'good' : 'attention';
  }

  /**
   * Formats image dimensions, type, and alt-text state.
   *
   * @param array<string, mixed>|null $image
   *   Image metadata.
   */
  private function imageValue(?array $image): string {
    if ($image === NULL) {
      return (string) $this->t('No share image is available.');
    }
    $parts = [];
    if (!empty($image['width']) && !empty($image['height'])) {
      $parts[] = $image['width'] . ' × ' . $image['height'] . ' px';
    }
    if (!empty($image['mime'])) {
      $parts[] = strtoupper(str_replace('image/', '', (string) $image['mime']));
    }
    $parts[] = trim((string) ($image['alt'] ?? '')) !== ''
      ? (string) $this->t('Alt text added')
      : (string) $this->t('Alt text missing');
    return implode(' · ', $parts);
  }

  /**
   * Resolves image health without mistaking a title for descriptive alt text.
   *
   * @param array<string, mixed>|null $image
   *   Image metadata.
   * @param string $title
   *   The event title used to detect weak fallback alt text.
   */
  private function imageStatus(?array $image, string $title): string {
    if ($image === NULL) {
      return 'missing';
    }
    $alt = trim((string) ($image['alt'] ?? ''));
    $weakAlt = $alt === '' || mb_strtolower($alt) === mb_strtolower(trim($title));
    $dimensionsMissing = empty($image['width']) || empty($image['height']);
    return $weakAlt || $dimensionsMissing ? 'attention' : 'good';
  }

  /**
   * Builds a route-safe Event Studio edit URL.
   */
  private function studioUrl(NodeInterface $event, string $route): string {
    return Url::fromRoute($route, ['node' => (int) $event->id()])->toString();
  }

}
