<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\Core\Database\Connection;

/**
 * Storage for myeventlane_message_preference (marketing/operational opt-out).
 */
final class MessagePreferenceStorage {

  /**
   * Constructs MessagePreferenceStorage.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * Gets preferences for a recipient (by email or uid).
   *
   * @param string $recipient
   *   Email address or numeric string uid.
   * @param string $recipientType
   *   'email' or 'uid'.
   *
   * @return array
   *   Keys: marketing_opt_out, operational_reminder_opt_out (booleans).
   */
  public function get(string $recipient, string $recipientType = 'email'): array {
    $r = $this->connection->select('myeventlane_message_preference', 'p')
      ->fields('p', ['marketing_opt_out', 'operational_reminder_opt_out'])
      ->condition('p.recipient', $recipient)
      ->condition('p.recipient_type', $recipientType)
      ->execute()
      ->fetchObject();

    if (!$r) {
      return [
        'marketing_opt_out' => FALSE,
        'operational_reminder_opt_out' => FALSE,
      ];
    }
    return [
      'marketing_opt_out' => (bool) $r->marketing_opt_out,
      'operational_reminder_opt_out' => (bool) $r->operational_reminder_opt_out,
    ];
  }

  /**
   * Sets marketing opt-out for a recipient.
   *
   * @param string $recipient
   *   Email or uid string.
   * @param string $recipientType
   *   'email' or 'uid'.
   * @param bool $value
   *   TRUE to opt out.
   */
  public function setMarketingOptOut(string $recipient, string $recipientType, bool $value): void {
    $this->upsert($recipient, $recipientType, ['marketing_opt_out' => $value ? 1 : 0]);
  }

  /**
   * Sets operational reminder opt-out for a recipient.
   *
   * @param string $recipient
   *   Email or uid string.
   * @param string $recipientType
   *   'email' or 'uid'.
   * @param bool $value
   *   TRUE to opt out.
   */
  public function setOperationalReminderOptOut(string $recipient, string $recipientType, bool $value): void {
    $this->upsert($recipient, $recipientType, ['operational_reminder_opt_out' => $value ? 1 : 0]);
  }

  /**
   * Upserts a preference row.
   *
   * @param string $recipient
   *   Recipient identifier.
   * @param string $recipientType
   *   'email' or 'uid'.
   * @param array $fields
   *   marketing_opt_out, operational_reminder_opt_out.
   */
  private function upsert(string $recipient, string $recipientType, array $fields): void {
    $now = (int) time();
    $allowed = ['marketing_opt_out', 'operational_reminder_opt_out'];
    $update = array_intersect_key($fields, array_flip($allowed));
    $update['updated'] = $now;

    $this->connection->merge('myeventlane_message_preference')
      ->key(['recipient_type' => $recipientType, 'recipient' => $recipient])
      ->fields(array_merge([
        'recipient' => $recipient,
        'recipient_type' => $recipientType,
        'marketing_opt_out' => 0,
        'operational_reminder_opt_out' => 0,
        'updated' => $now,
      ], $update))
      ->execute();
  }

}
