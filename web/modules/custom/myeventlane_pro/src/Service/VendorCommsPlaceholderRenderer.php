<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;

/**
 * Renders the deliberately small Pro email placeholder vocabulary.
 *
 * This is shared by the organiser preview and the delivery resolver so the
 * preview cannot promise replacements the sent email will not perform.
 */
final class VendorCommsPlaceholderRenderer {

  /**
   * HTML allowed in organiser-authored email wording.
   *
   * @var string[]
   */
  private const ALLOWED_TAGS = ['p', 'strong', 'em', 'br', 'ul', 'li', 'a'];

  /**
   * Replaces supported placeholders and sanitises the resulting HTML.
   */
  public function render(string $template, array $context): string {
    $replacements = [
      '[event:title]' => $this->escape($context['event_title'] ?? ''),
      '[event:date]' => $this->escape($context['event_date'] ?? $context['event_start'] ?? ''),
      '[event:location]' => $this->escape($context['event_location'] ?? $context['venue'] ?? ''),
      '[customer:first_name]' => $this->escape($context['first_name'] ?? $context['name'] ?? 'there'),
      '[order:total]' => $this->escape($context['order_total'] ?? $context['total_paid'] ?? ''),
      '[ticket:type]' => $this->escape($context['ticket_type'] ?? ''),
    ];

    return trim(Xss::filter(strtr($template, $replacements), self::ALLOWED_TAGS));
  }

  /**
   * Sample context used by the organiser preview.
   *
   * @return array<string, string>
   *   Values representing a realistic Australian event email.
   */
  public function sampleContext(): array {
    return [
      'event_title' => 'Sample Event',
      'event_date' => '31 March 2026, 7:00 pm',
      'event_location' => 'Moore Park NSW',
      'first_name' => 'Alex',
      'order_total' => '$49.00',
      'ticket_type' => 'General Admission',
    ];
  }

  private function escape(mixed $value): string {
    return Html::escape(trim((string) $value));
  }

}
