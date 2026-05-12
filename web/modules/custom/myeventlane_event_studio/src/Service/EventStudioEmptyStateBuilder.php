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
  public function build(string $title, string $body, string $prompt = '', array $guidance = [], string $icon = 'spark', string $state = 'default'): array {
    return [
      '#theme' => 'mel_event_studio_empty_state',
      '#title' => $title,
      '#body' => $body,
      '#prompt' => $prompt,
      '#guidance' => $guidance,
      '#icon' => $icon,
      '#state' => $state,
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
        (string) $this->t('Merchandise will help organisers sell event products without leaving MEL.'),
        (string) $this->t('Planned capability. It will appear here once product ownership, payments, fulfilment, and access rules are ready.'),
        [
          (string) $this->t('No merchandise controls are active yet.'),
          (string) $this->t('Future setup will reuse governed Commerce and fulfilment services.'),
        ],
        'merchandise',
        'deferred',
      );
    }
    if ($section_id === 'addons') {
      return $this->build(
        (string) $this->t('Add-ons'),
        (string) $this->t('Add-ons will support optional upgrades such as parking, meals, or experience extras.'),
        (string) $this->t('Reserved until add-on pricing, checkout, refunds, and attendee reporting are governed end-to-end.'),
        [
          (string) $this->t('No attendee-facing add-on controls are exposed yet.'),
          (string) $this->t('Future controls must use existing Commerce checkout ownership.'),
        ],
        'addons',
        'deferred',
      );
    }
    if ($section_id === 'fulfilment') {
      return $this->build(
        (string) $this->t('Fulfilment'),
        (string) $this->t('Fulfilment will coordinate product handoffs, delivery notes, and operational tasks.'),
        (string) $this->t('Reserved until the fulfilment domain has approved access, reporting, and state contracts.'),
        [
          (string) $this->t('Current ticket and RSVP operations are unaffected.'),
          (string) $this->t('Future fulfilment tools will be event-scoped and audit-friendly.'),
        ],
        'fulfilment',
        'deferred',
      );
    }

    return $this->build(
      (string) $this->t('No @section workspace yet', ['@section' => $section_label]),
      (string) $this->t('@section is reserved for a governed Studio extension. It must register a section contract before adding operational UI.', [
        '@section' => $section_label,
      ]),
      (string) $this->t('Keep this section empty until the owning domain service and access contract exist.'),
      [],
      'roadmap',
      'deferred',
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
      [],
      'roadmap',
      'coming-soon',
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
      [
        (string) $this->t('Reporting stays event-scoped.'),
        (string) $this->t('Write actions remain in their owning operational sections.'),
      ],
      'reporting',
      'readonly',
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
      [],
      'alert',
      'unavailable',
    );
  }

}
