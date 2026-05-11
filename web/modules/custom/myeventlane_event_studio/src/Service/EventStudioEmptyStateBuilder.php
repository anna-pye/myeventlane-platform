<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Builds consistent empty states for Event Studio operational sections.
 */
final class EventStudioEmptyStateBuilder {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds an Event Studio empty-state render array.
   *
   * @param list<string> $guidance
   *   Optional guidance items shown below the main copy.
   *
   * @return array<string, mixed>
   */
  public function build(string $title, string $body, string $prompt = '', array $guidance = []): array {
    return [
      '#theme' => 'mel_event_studio_empty_state',
      '#title' => $title,
      '#body' => $body,
      '#prompt' => $prompt,
      '#guidance' => $guidance,
    ];
  }

  /**
   * Builds a deferred section empty state from a section label.
   *
   * @return array<string, mixed>
   */
  public function deferredSection(string $section_label): array {
    return $this->build(
      (string) $this->t('No @section workspace yet', ['@section' => $section_label]),
      (string) $this->t('@section is reserved for a governed Studio extension. It must register a section contract before adding operational UI.', [
        '@section' => $section_label,
      ]),
      (string) $this->t('Keep this section empty until the owning domain service and access contract exist.'),
    );
  }

}
