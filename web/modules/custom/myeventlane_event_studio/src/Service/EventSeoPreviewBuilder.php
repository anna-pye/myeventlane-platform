<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;

/**
 * Builds an honest preview of event search and social metadata.
 */
final class EventSeoPreviewBuilder {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Builds preview values from the same event fields used by public metadata.
   *
   * @return array<string, mixed>
   *   Search and social preview values.
   */
  public function build(NodeInterface $event, string $publicUrl): array {
    $title = trim((string) $event->label());
    $siteName = trim((string) $this->configFactory->get('system.site')->get('name')) ?: 'MyEventLane';
    $description = $this->description($event);
    $imageUrl = NULL;
    $imageAlt = '';

    if ($event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty()) {
      $item = $event->get('field_event_image')->first();
      $imageAlt = trim((string) ($item?->getValue()['alt'] ?? ''));
      $file = $event->get('field_event_image')->entity;
      if ($file instanceof FileInterface) {
        $imageUrl = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
    }

    return [
      'url' => $publicUrl,
      'domain' => (string) (parse_url($publicUrl, PHP_URL_HOST) ?: $siteName),
      'search_title' => $this->truncate($title . ' | ' . $siteName, 60),
      'social_title' => $title,
      'description' => $this->truncate($description, 160),
      'image_url' => $imageUrl,
      'image_alt' => $imageAlt !== '' ? $imageAlt : $title,
    ];
  }

  /**
   * Resolves the best available plain-text event description.
   */
  private function description(NodeInterface $event): string {
    foreach (['field_event_summary', 'field_event_intro', 'body'] as $fieldName) {
      if ($event->hasField($fieldName) && !$event->get($fieldName)->isEmpty()) {
        $value = trim(preg_replace('/\s+/', ' ', strip_tags((string) $event->get($fieldName)->value)) ?? '');
        if ($value !== '') {
          return $value;
        }
      }
    }
    return '';
  }

  /**
   * Truncates preview copy without splitting multibyte characters.
   */
  private function truncate(string $value, int $limit): string {
    if (mb_strlen($value) <= $limit) {
      return $value;
    }
    return rtrim(mb_substr($value, 0, $limit - 1)) . '…';
  }

}
