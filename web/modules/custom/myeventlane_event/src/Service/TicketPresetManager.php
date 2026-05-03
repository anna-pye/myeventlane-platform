<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

/**
 * Provides organiser-facing ticket presets for the event ticket builder.
 *
 * Presets are only input defaults. Ticket creation remains owned by
 * TicketTierLifecycleService through the existing ticket builder submit flow.
 */
final class TicketPresetManager {

  /**
   * Returns the available ticket presets.
   *
   * @return array<string, array{
   *   label: string,
   *   description: string,
   *   values: array<string, mixed>
   * }>
   *   Presets keyed by stable machine name.
   */
  public function getPresets(): array {
    return [
      'general' => [
        'label' => 'General Admission',
        'description' => 'Standard entry ticket',
        'values' => [
          'title' => 'General Admission',
          'ticket_kind' => 'paid',
          'price' => '',
          'capacity' => NULL,
          'visibility' => 'public',
        ],
      ],
      'vip' => [
        'label' => 'VIP / Premium',
        'description' => 'Higher price with perks',
        'values' => [
          'title' => 'VIP',
          'ticket_kind' => 'paid',
          'price' => '',
          'capacity' => 50,
          'visibility' => 'public',
        ],
      ],
      'early_bird' => [
        'label' => 'Early Bird',
        'description' => 'Limited discounted tickets',
        'values' => [
          'title' => 'Early Bird',
          'ticket_kind' => 'paid',
          'price' => '',
          'capacity' => 20,
          'visibility' => 'public',
        ],
      ],
      'donation' => [
        'label' => 'Donation / Pay what you want',
        'description' => 'Flexible contribution',
        'values' => [
          'title' => 'Supporter Ticket',
          'ticket_kind' => 'rsvp',
          'price' => 0,
          'capacity' => NULL,
        ],
      ],
      'custom' => [
        'label' => 'Custom',
        'description' => 'Start from scratch',
        'values' => [],
      ],
    ];
  }

  /**
   * Returns one preset by machine name.
   *
   * @return array{label: string, description: string, values: array<string, mixed>}|null
   *   The preset definition, or NULL when the key is unknown.
   */
  public function getPreset(string $key): ?array {
    $presets = $this->getPresets();
    return $presets[$key] ?? NULL;
  }

}
