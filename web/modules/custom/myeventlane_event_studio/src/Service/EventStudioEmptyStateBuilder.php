<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Builds consistent empty states for Event Workspace sections.
 */
final class EventStudioEmptyStateBuilder {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds an Event Workspace empty-state render array.
   *
   * @param list<string> $guidance
   *   Optional guidance items shown below the main copy.
   * @param array<string, mixed> $actions
   *   Optional primary CTA render array (e.g. link) rendered inside the theme.
   *
   * @return array<string, mixed>
   */
  public function build(string $title, string $body, string $prompt = '', array $guidance = [], string $icon = 'spark', string $state = 'default', array $actions = []): array {
    return [
      '#theme' => 'mel_event_studio_empty_state',
      '#title' => $title,
      '#body' => $body,
      '#prompt' => $prompt,
      '#guidance' => $guidance,
      '#icon' => $icon,
      '#state' => $state,
      '#actions' => $actions,
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
        (string) $this->t('Sell event merch without leaving MyEventLane.'),
        (string) $this->t('This is planned. It will appear here when product ownership, payments, and collection are ready.'),
        [
          (string) $this->t('No merchandise controls are active yet.'),
          (string) $this->t('Learn more in Support when this capability launches.'),
        ],
        'merchandise',
        'deferred',
      );
    }
    if ($section_id === 'addons') {
      return $this->build(
        (string) $this->t('Add-ons'),
        (string) $this->t('Offer optional upgrades such as parking, meals, or experience extras.'),
        (string) $this->t('Reserved until pricing, checkout, refunds, and attendee reporting are ready end-to-end.'),
        [
          (string) $this->t('No attendee-facing add-on controls are exposed yet.'),
          (string) $this->t('Ticket sales continue as usual.'),
        ],
        'addons',
        'deferred',
      );
    }
    if ($section_id === 'fulfilment') {
      return $this->build(
        (string) $this->t('Collection'),
        (string) $this->t('Coordinate handoffs, delivery notes, and operational tasks.'),
        (string) $this->t('Reserved until collection tools have approved access and reporting.'),
        [
          (string) $this->t('Current ticket and RSVP operations are unaffected.'),
          (string) $this->t('Future collection tools stay event-scoped.'),
        ],
        'fulfilment',
        'deferred',
      );
    }

    return $this->build(
      (string) $this->t('No @section yet', ['@section' => $section_label]),
      (string) $this->t('@section is coming soon in Event Workspace.', [
        '@section' => $section_label,
      ]),
      (string) $this->t('Keep using the other sections for now — we will open this when it is ready.'),
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
      (string) $this->t('This part of Event Workspace is not available yet.'),
      (string) $this->t('It will appear here once the experience is ready for organisers.'),
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
    $defaults = [
      'Attendees' => [
        (string) $this->t('Guests will appear here after your first booking.'),
        (string) $this->t('Share your event page to start collecting attendees.'),
      ],
      'Orders' => [
        (string) $this->t('Orders will appear here after your first sale.'),
        (string) $this->t('Create tickets and publish when you are ready to sell.'),
      ],
      'Analytics' => [
        (string) $this->t('Publish your event to start tracking sales.'),
        (string) $this->t('Once live, you will see attendance and revenue here.'),
      ],
    ];
    $pair = $defaults[$section_label] ?? [
      $body !== '' ? $body : (string) $this->t('Nothing to show for this event yet.'),
      (string) $this->t('Check back after your first booking or publish.'),
    ];

    return $this->build(
      $section_label,
      $pair[0],
      $pair[1],
      [
        (string) $this->t('This view is event-scoped and read-only.'),
        (string) $this->t('Use Tickets, Messages, or Publishing to make changes.'),
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
      (string) $this->t('This section could not load right now.'),
      (string) $this->t('Try refreshing the page. If it keeps happening, contact Support — we have logged the issue.'),
      [],
      'alert',
      'unavailable',
    );
  }

}
