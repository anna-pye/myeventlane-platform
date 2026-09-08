<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\node\NodeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Event-specific acknowledgement of the consumer-rights statement.
 */
final class RefundPolicyRequirement {

  public const VERSION = '2026-09-08';
  public const FIELD = 'field_refund_acknowledgement';

  public static function acknowledged(NodeInterface $event): bool {
    if (!$event->hasField(self::FIELD) || $event->get(self::FIELD)->isEmpty()) {
      return FALSE;
    }
    $record = json_decode((string) $event->get(self::FIELD)->value, TRUE);
    return is_array($record) && ($record['version'] ?? '') === self::VERSION;
  }

  public static function statement(): string {
    return '<p>If you choose to cancel an event or make a major change, ticket buyers are entitled to a refund under Australian Consumer Law (ACL). They may also have refund rights if the event cannot be delivered safely, and may be entitled to compensation depending on the circumstances.</p>'
      . '<p>Where a cancellation or change is due only to the actions of someone other than the organiser, rights depend on the ticket terms and applicable law. Your change-of-mind policy cannot exclude or restrict consumer rights.</p>'
      . '<p>Free RSVP bookings have no ticket payment to refund. Tell attendees promptly about cancellations or major changes.</p>'
      . '<p><a href="https://www.accc.gov.au/business/selling-products-and-services/selling-tickets-to-events" target="_blank" rel="noopener noreferrer">Read the ACCC guidance on event tickets</a>.</p>';
  }

  public static function checkbox(bool $accepted): array {
    return [
      '#type' => 'checkbox',
      '#title' => t('I understand my event refund obligations and that my policy cannot override rights under Australian Consumer Law.'),
      '#description' => t('Required before publishing. You can save a draft without acknowledging yet.'),
      '#default_value' => $accepted ? 1 : 0,
    ];
  }

  public static function options(array $options): array {
    unset($options['none_specified']);
    if (isset($options['no_refunds'])) {
      $options['no_refunds'] = t('No change-of-mind refunds');
    }
    return $options;
  }

  public static function errors(NodeInterface $event, ?TranslationInterface $translation = NULL): array {
    $errors = [];
    $type = $event->hasField('field_event_type') ? (string) $event->get('field_event_type')->value : 'rsvp';
    if (in_array($type, ['paid', 'both'], TRUE)) {
      $policy = $event->hasField('field_refund_policy') ? (string) $event->get('field_refund_policy')->value : '';
      if (!in_array($policy, ['no_refunds', 'refund_24h', 'refund_7d', 'case_by_case', '1_day', '7_days', '14_days', '30_days'], TRUE)) {
        $errors[] = (string) new TranslatableMarkup('Choose a change-of-mind refund policy in Refunds and consumer rights before publishing.', [], [], $translation);
      }
    }
    if (!self::acknowledged($event)) {
      $errors[] = (string) new TranslatableMarkup('Acknowledge your obligations in Refunds and consumer rights before publishing.', [], [], $translation);
    }
    return $errors;
  }

}
