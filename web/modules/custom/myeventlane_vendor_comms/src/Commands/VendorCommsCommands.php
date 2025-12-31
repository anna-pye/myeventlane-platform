<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_comms\Commands;

use Drush\Commands\DrushCommands;

/**
 * Drush commands for vendor communications testing.
 */
final class VendorCommsCommands extends DrushCommands {

  /**
   * Tests recipient resolution for an event.
   *
   * @param int $event_id
   *   Event node ID.
   *
   * @command mel:comms-test-recipients
   * @aliases mel-comms-recipients
   * @usage drush mel:comms-test-recipients 123
   *   Test recipient resolution for event 123.
   */
  public function testRecipients(int $event_id): void {
    $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
    $event = $nodeStorage->load($event_id);

    if (!$event) {
      $this->logger()->error("Event {$event_id} not found.");
      return;
    }

    $recipientResolver = \Drupal::service('myeventlane_vendor_comms.recipient_resolver');
    $emails = $recipientResolver->getRecipientEmails($event);
    $count = $recipientResolver->getRecipientCount($event);

    $this->output()->writeln('');
    $this->output()->writeln("Event: {$event->label()} (ID: {$event_id})");
    $this->output()->writeln("Recipient count: {$count}");
    $this->output()->writeln('');

    if (empty($emails)) {
      $this->logger()->warning('No recipients found for this event.');
      return;
    }

    $this->output()->writeln('Recipients:');
    foreach ($emails as $email) {
      $this->output()->writeln("  - {$email}");
    }

    $this->logger()->success("Found {$count} recipient(s).");
  }

  /**
   * Tests rate limiting for a vendor and event.
   *
   * @param int $event_id
   *   Event node ID.
   * @param int $vendor_uid
   *   Vendor user ID (optional, defaults to current user).
   *
   * @command mel:comms-test-rate-limit
   * @aliases mel-comms-rate-limit
   * @usage drush mel:comms-test-rate-limit 123
   *   Test rate limit for event 123 and current user.
   * @usage drush mel:comms-test-rate-limit 123 5
   *   Test rate limit for event 123 and user 5.
   */
  public function testRateLimit(int $event_id, ?int $vendor_uid = NULL): void {
    if (!$vendor_uid) {
      $vendor_uid = (int) \Drupal::currentUser()->id();
    }

    $rateLimiter = \Drupal::service('myeventlane_vendor_comms.rate_limiter');
    $check = $rateLimiter->checkRateLimit($event_id, $vendor_uid);

    $this->output()->writeln('');
    $this->output()->writeln("Event ID: {$event_id}");
    $this->output()->writeln("Vendor UID: {$vendor_uid}");
    $this->output()->writeln('');

    if ($check['allowed']) {
      $this->logger()->success("Rate limit check passed. ({$check['count']}/{$check['limit']} daily)");
    }
    else {
      $this->logger()->error("Rate limit check failed: {$check['reason']}");
      $this->output()->writeln("Count: {$check['count']}/{$check['limit']}");
    }
  }

  /**
   * Manually queues a test communication (bypasses rate limits).
   *
   * @param int $event_id
   *   Event node ID.
   * @param string $type
   *   Message type: update, important_change, or cancellation.
   * @param string $subject
   *   Email subject.
   * @param string $body
   *   Message body.
   * @param int|null $vendor_uid
   *   Vendor user ID (optional, defaults to current user).
   *
   * @command mel:comms-queue-test
   * @aliases mel-comms-queue
   * @usage drush mel:comms-queue-test 123 update "Test Subject" "Test body"
   *   Queue a test update message for event 123.
   */
  public function queueTest(int $event_id, string $type, string $subject, string $body, ?int $vendor_uid = NULL): void {
    if (!in_array($type, ['update', 'important_change', 'cancellation'], TRUE)) {
      $this->logger()->error("Invalid message type. Use: update, important_change, or cancellation.");
      return;
    }

    $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
    $event = $nodeStorage->load($event_id);
    if (!$event) {
      $this->logger()->error("Event {$event_id} not found.");
      return;
    }

    if (!$vendor_uid) {
      $vendor_uid = (int) \Drupal::currentUser()->id();
    }

    // Get recipient count.
    $recipientResolver = \Drupal::service('myeventlane_vendor_comms.recipient_resolver');
    $recipientCount = $recipientResolver->getRecipientCount($event);

    if ($recipientCount === 0) {
      $this->logger()->error("No recipients found for event {$event_id}.");
      return;
    }

    // Create log entry.
    $now = \Drupal::time()->getRequestTime();
    $logId = \Drupal::database()->insert('myeventlane_event_comms_log')
      ->fields([
        'event_id' => $event_id,
        'vendor_uid' => $vendor_uid,
        'message_type' => $type,
        'subject' => $subject,
        'body' => $body,
        'recipient_count' => $recipientCount,
        'sent_count' => 0,
        'failed_count' => 0,
        'status' => 'pending',
        'sent_at' => $now,
      ])
      ->execute();

    // Enqueue send job.
    $queue = \Drupal::queue('vendor_event_comms');
    $queue->createItem([
      'log_id' => $logId,
      'event_id' => $event_id,
      'message_type' => $type,
      'subject' => $subject,
      'body' => $body,
    ]);

    $this->logger()->success("Test communication queued (log ID: {$logId}). Run 'drush queue:run vendor_event_comms' to process.");
  }

  /**
   * Processes the vendor communications queue.
   *
   * @command mel:comms-run
   * @aliases mel-comms-run
   * @usage drush mel:comms-run
   *   Process all queued vendor communications.
   */
  public function run(): void {
    $queue = \Drupal::queue('vendor_event_comms');
    $processed = 0;

    while ($item = $queue->claimItem()) {
      try {
        $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('vendor_event_comms');
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
      }
      catch (\Throwable $e) {
        $queue->releaseItem($item);
        $this->logger()->error("Failed to process item: " . $e->getMessage());
      }
    }

    $this->logger()->success("Processed {$processed} communication(s). Run 'drush mel:msg-run' to send queued emails.");
  }

  /**
   * Imports email templates if they don't exist.
   *
   * @command mel:comms-import-templates
   * @aliases mel-comms-import
   * @usage drush mel:comms-import-templates
   *   Import email templates for vendor communications.
   */
  public function importTemplates(): void {
    $config_factory = \Drupal::configFactory();
    $module_path = \Drupal::service('extension.list.module')->getPath('myeventlane_messaging');
    
    $templates = [
      'vendor_event_update',
      'vendor_event_important_change',
      'vendor_event_cancellation',
    ];

    $imported = 0;
    foreach ($templates as $template_key) {
      $config_name = "myeventlane_messaging.template.{$template_key}";
      $config = $config_factory->getEditable($config_name);
      
      // Only create if it doesn't exist.
      if ($config->isNew()) {
        $template_path = $module_path . "/config/install/{$config_name}.yml";
        
        if (file_exists($template_path)) {
          $template_data = \Drupal\Component\Serialization\Yaml::decode(file_get_contents($template_path));
          $config->setData($template_data)->save();
          $imported++;
          $this->logger()->success("Imported template: {$template_key}");
        }
        else {
          $this->logger()->warning("Template file not found: {$template_path}");
        }
      }
      else {
        $this->logger()->notice("Template already exists: {$template_key}");
      }
    }

    if ($imported > 0) {
      $this->logger()->success("Imported {$imported} template(s).");
    }
    else {
      $this->logger()->notice("No templates imported (all already exist).");
    }
  }

  /**
   * Lists recent communications log entries.
   *
   * @param int|null $event_id
   *   Optional event ID to filter by.
   * @param int $limit
   *   Number of entries to show (default: 10).
   *
   * @command mel:comms-list
   * @aliases mel-comms-list
   * @usage drush mel:comms-list
   *   List recent communications.
   * @usage drush mel:comms-list 123
   *   List communications for event 123.
   */
  public function list(?int $event_id = NULL, int $limit = 10): void {
    $query = \Drupal::database()->select('myeventlane_event_comms_log', 'log')
      ->fields('log', ['id', 'event_id', 'vendor_uid', 'message_type', 'subject', 'recipient_count', 'sent_count', 'failed_count', 'status', 'sent_at'])
      ->orderBy('sent_at', 'DESC')
      ->range(0, $limit);

    if ($event_id) {
      $query->condition('event_id', $event_id);
    }

    $entries = $query->execute()->fetchAll();

    if (empty($entries)) {
      $this->logger()->warning('No communications found.');
      return;
    }

    $this->output()->writeln('');
    $this->output()->writeln('Recent Communications:');
    $this->output()->writeln('');
    $this->output()->writeln(sprintf(
      '%-5s %-8s %-8s %-20s %-40s %-8s %-8s %-8s %-12s %-20s',
      'ID',
      'Event',
      'Vendor',
      'Type',
      'Subject',
      'Recip',
      'Sent',
      'Failed',
      'Status',
      'Sent At'
    ));
    $this->output()->writeln(str_repeat('-', 150));

    $dateFormatter = \Drupal::service('date.formatter');
    foreach ($entries as $entry) {
      $date = $dateFormatter->format($entry->sent_at, 'short');
      $subject = mb_substr($entry->subject, 0, 38);
      if (mb_strlen($entry->subject) > 38) {
        $subject .= '...';
      }

      $this->output()->writeln(sprintf(
        '%-5s %-8s %-8s %-20s %-40s %-8s %-8s %-8s %-12s %-20s',
        $entry->id,
        $entry->event_id,
        $entry->vendor_uid,
        $entry->message_type,
        $subject,
        $entry->recipient_count,
        $entry->sent_count,
        $entry->failed_count,
        $entry->status,
        $date
      ));
    }

    $this->output()->writeln('');
    $this->logger()->success("Found " . count($entries) . " communication(s).");
  }

}

