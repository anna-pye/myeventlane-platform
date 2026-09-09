<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\file\FileInterface;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\node\NodeInterface;

/**
 * Builds one canonical set of public event metadata facts.
 */
final class EventMetadataBuilder implements EventMetadataBuilderInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly ImageFactory $imageFactory,
    private readonly EventDateTimeResolver $eventDateTime,
    private readonly BookingFlowResolver $bookingFlowResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(NodeInterface $event, ?string $canonicalUrl = NULL): array {
    $title = trim((string) $event->label());
    $siteName = trim((string) $this->configFactory->get('system.site')->get('name')) ?: 'MyEventLane';
    $canonicalUrl = trim((string) ($canonicalUrl ?? ''));
    if ($canonicalUrl === '') {
      $canonicalUrl = $event->toUrl('canonical', ['absolute' => TRUE])->toString();
    }

    $description = $this->resolveDescription($event);
    $start = $this->eventDateTime->getFieldDateTime($event, 'field_event_start');
    $end = $this->eventDateTime->getFieldDateTime($event, 'field_event_end');
    $timezone = $this->eventDateTime->getTimezoneId($event);
    $pricing = $this->bookingFlowResolver->getDisplayPricing($event);

    return [
      'title' => $title,
      'site_name' => $siteName,
      'canonical_url' => $canonicalUrl,
      'domain' => (string) (parse_url($canonicalUrl, PHP_URL_HOST) ?: $siteName),
      'search_title' => $this->truncate($title . ' | ' . $siteName, 60),
      'description' => $description,
      'search_description' => $this->truncate($description, 160),
      'social_description' => $this->truncate($description, 300),
      'copy_text' => $this->resolveAllCopy($event),
      'timezone' => $timezone,
      'start_iso' => $start?->format(\DateTimeInterface::ATOM),
      'end_iso' => $end?->format(\DateTimeInterface::ATOM),
      'start_display' => $start?->format('D, j M Y, g:ia'),
      'end_display' => $end?->format('D, j M Y, g:ia'),
      'image' => $this->resolveImage($event),
      'location' => $this->resolveLocation($event),
      'organizer' => $this->resolveOrganizer($event),
      'booking' => [
        'mode' => $this->bookingFlowResolver->getBookingMode($event),
        'availability' => $this->bookingFlowResolver->getAvailabilityState($event),
        'pricing' => $pricing,
      ],
    ];
  }

  /**
   * Resolves the best available plain-text description.
   */
  private function resolveDescription(NodeInterface $event): string {
    foreach (['field_event_summary', 'field_event_intro', 'body'] as $fieldName) {
      $value = $this->plainFieldValue($event, $fieldName);
      if ($value !== '') {
        if (str_contains($value, '[date]')) {
          $date = $this->eventDateTime->formatField($event, 'field_event_start', 'j F Y');
          if ($date !== NULL) {
            $value = str_replace('[date]', $date, $value);
          }
        }
        return $value;
      }
    }

    return '';
  }

  /**
   * Resolves all event copy for contradiction checks.
   */
  private function resolveAllCopy(NodeInterface $event): string {
    $copy = [];
    foreach (['field_event_summary', 'field_event_intro', 'body'] as $fieldName) {
      $value = $this->plainFieldValue($event, $fieldName);
      if ($value !== '') {
        $copy[] = $value;
      }
    }
    return implode(' ', $copy);
  }

  /**
   * Reads and normalises a formatted text field.
   */
  private function plainFieldValue(NodeInterface $event, string $fieldName): string {
    if (!$event->hasField($fieldName) || $event->get($fieldName)->isEmpty()) {
      return '';
    }

    $value = (string) ($event->get($fieldName)->getValue()[0]['value'] ?? '');
    return trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
  }

  /**
   * Resolves the event image and its share metadata.
   *
   * @return array<string, int|string>|null
   *   Image metadata, or NULL when no usable file exists.
   */
  private function resolveImage(NodeInterface $event): ?array {
    if (!$event->hasField('field_event_image') || $event->get('field_event_image')->isEmpty()) {
      return NULL;
    }

    $item = $event->get('field_event_image')->first();
    $file = $event->get('field_event_image')->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }

    $values = $item?->getValue() ?? [];
    $width = (int) ($values['width'] ?? 0);
    $height = (int) ($values['height'] ?? 0);
    if ($width < 1 || $height < 1) {
      $image = $this->imageFactory->get($file->getFileUri());
      if ($image->isValid()) {
        $width = (int) $image->getWidth();
        $height = (int) $image->getHeight();
      }
    }

    return array_filter([
      'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
      'alt' => trim((string) ($values['alt'] ?? '')),
      'mime' => trim((string) $file->getMimeType()),
      'width' => $width,
      'height' => $height,
    ], static fn (mixed $value): bool => $value !== '' && $value !== 0);
  }

  /**
   * Resolves the physical location and structured address components.
   *
   * @return array<string, mixed>|null
   *   Location metadata, or NULL when no usable location exists.
   */
  private function resolveLocation(NodeInterface $event): ?array {
    $name = $this->plainFieldValue($event, 'field_venue_name');
    if ($name === '' && $event->hasField('field_venue') && !$event->get('field_venue')->isEmpty()) {
      $venue = $event->get('field_venue')->entity;
      if ($venue instanceof EntityInterface) {
        $name = trim((string) $venue->label());
      }
    }

    $address = [];
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $values = $event->get('field_location')->getValue()[0] ?? [];
      $address = array_filter([
        '@type' => 'PostalAddress',
        'streetAddress' => trim(implode(', ', array_filter([
          (string) ($values['address_line1'] ?? ''),
          (string) ($values['address_line2'] ?? ''),
        ]))),
        'addressLocality' => trim((string) ($values['locality'] ?? '')),
        'addressRegion' => trim((string) ($values['administrative_area'] ?? '')),
        'postalCode' => trim((string) ($values['postal_code'] ?? '')),
        'addressCountry' => trim((string) ($values['country_code'] ?? '')),
      ], static fn (mixed $value): bool => $value !== '');
    }

    if ($name === '' && isset($address['addressLocality'])) {
      $name = (string) $address['addressLocality'];
    }
    if ($name === '' && count($address) <= 1) {
      return NULL;
    }

    $display = implode(', ', array_filter([
      $name,
      $address['streetAddress'] ?? '',
      $address['addressLocality'] ?? '',
      $address['addressRegion'] ?? '',
      $address['postalCode'] ?? '',
    ]));
    $complete = $name !== ''
      && isset($address['streetAddress'], $address['addressLocality'], $address['addressRegion'], $address['postalCode'], $address['addressCountry']);

    return [
      'name' => $name,
      'address' => count($address) > 1 ? $address : NULL,
      'display' => $display,
      'complete' => $complete,
    ];
  }

  /**
   * Resolves the public organiser name and canonical profile URL.
   *
   * @return array<string, string>|null
   *   Organiser metadata, or NULL when no organiser is referenced.
   */
  private function resolveOrganizer(NodeInterface $event): ?array {
    if (!$event->hasField('field_event_vendor') || $event->get('field_event_vendor')->isEmpty()) {
      return NULL;
    }

    $vendor = $event->get('field_event_vendor')->entity;
    if (!$vendor instanceof EntityInterface || trim((string) $vendor->label()) === '') {
      return NULL;
    }

    $organizer = [
      'name' => trim((string) $vendor->label()),
    ];
    try {
      $organizer['url'] = $vendor->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    catch (\Throwable) {
      // The organiser name remains valid when its route is unavailable.
    }
    return $organizer;
  }

  /**
   * Truncates metadata copy without splitting multibyte characters.
   */
  private function truncate(string $value, int $limit): string {
    return Unicode::truncate($value, $limit, TRUE, TRUE);
  }

}
