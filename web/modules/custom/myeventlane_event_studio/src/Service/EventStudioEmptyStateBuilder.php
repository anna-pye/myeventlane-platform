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
  public function deferredSection(string $section_label, string $section_id = ''): array {
    if ($section_id === 'merchandise') {
      return $this->build(
        (string) $this->t('Merchandise'),
        (string) $this->t('Sell event merchandise and products.'),
        (string) $this->t('This capability is planned for a future MEL release.'),
      );
    }
    if ($section_id === 'addons') {
      return $this->build(
        (string) $this->t('Add-ons'),
        (string) $this->t('Offer optional event upgrades like parking or meal packages.'),
        (string) $this->t('Coming soon.'),
      );
    }
    if ($section_id === 'fulfilment') {
      return $this->build(
        (string) $this->t('Fulfilment'),
        (string) $this->t('Coordinate fulfilment workflows for event products and operational handoffs.'),
        (string) $this->t('This capability is reserved until the fulfilment domain is ready.'),
      );
    }

    return $this->build(
      (string) $this->t('No @section workspace yet', ['@section' => $section_label]),
      (string) $this->t('@section is reserved for a governed Studio extension. It must register a section contract before adding operational UI.', [
        '@section' => $section_label,
      ]),
      (string) $this->t('Keep this section empty until the owning domain service and access contract exist.'),
    );
  }

  /**
   * Builds a coming-soon disabled section empty state.
   *
   * @return array<string, mixed>
   */
  public function comingSoonSection(string $section_label): array {
    return $this->build(
      $section_label,
      (string) $this->t('This Studio capability is intentionally disabled for now.'),
      (string) $this->t('It will become available only after the owning domain, access, and save contracts are approved.'),
    );
  }

  /**
   * Builds a readonly reporting empty state.
   *
   * @return array<string, mixed>
   */
  public function readonlyEmptySection(string $section_label, string $body = ''): array {
    return $this->build(
      $section_label,
      $body !== '' ? $body : (string) $this->t('No readonly reporting data is available for this event yet.'),
      (string) $this->t('This section does not mutate event state. Filters, pagination, and exports must be added through governed reporting services.'),
    );
  }

  /**
   * Builds a loudly governed unavailable state for bad render contracts.
   *
   * @return array<string, mixed>
   */
  public function unavailableSection(string $section_label): array {
    return $this->build(
      $section_label,
      (string) $this->t('This Studio section cannot render because its operational contract is incomplete.'),
      (string) $this->t('The issue has been logged for review.'),
    );
  }

}
