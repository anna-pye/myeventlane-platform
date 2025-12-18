<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Scheduler;

use Drupal\Component\Datetime\TimeInterface as DrupalTimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Psr\Log\LoggerInterface;

/**
 * Finds events starting in next 24h and notifies buyers/RSVPs (one email per order).
 */
final class EventReminderScheduler {

  /**
   * Constructs an EventReminderScheduler.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly DrupalTimeInterface $time,
    private readonly EntityTypeManagerInterface $etm,
    private readonly QueueFactory $queue,
    private readonly StateInterface $state,
    private readonly MessagingManager $messaging,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Scans for events starting soon and queues reminder emails.
   */
  public function scan(): void {
    $now = $this->time->getRequestTime();
    $in24 = $now + 86400;

    $nowIso = gmdate('Y-m-d\TH:i:s', $now);
    $in24Iso = gmdate('Y-m-d\TH:i:s', $in24);

    // Load events starting within 24h (uses field_event_start on event).
    $eids = $this->etm->getStorage('node')->getQuery()
      ->condition('type', 'event')
      ->condition('status', 1)
      ->exists('field_event_start')
      ->condition('field_event_start', $nowIso, '>=')
      ->condition('field_event_start', $in24Iso, '<=')
      ->accessCheck(FALSE)
      ->execute();

    if (!$eids) {
      $this->logger->notice('Event reminder scan: no candidates in next 24h.');
      return;
    }

    $events = $this->etm->getStorage('node')->loadMultiple($eids);
    $notified = 0;

    foreach ($events as $event) {
      // Find order items referencing this event via field_target_event.
      $oi_ids = $this->etm->getStorage('commerce_order_item')->getQuery()
        ->exists('field_target_event')
        ->condition('field_target_event', $event->id())
        ->accessCheck(FALSE)
        ->execute();

      if (!$oi_ids) {
        continue;
      }

      $ois = $this->etm->getStorage('commerce_order_item')->loadMultiple($oi_ids);
      foreach ($ois as $oi) {
        $order = $oi->getOrder();
        if (!$order) {
          continue;
        }

        $mail = $order->getEmail() ?: ($order->getCustomer() ? $order->getCustomer()->getEmail() : NULL);
        if (!$mail) {
          continue;
        }

        $state_key = "mel.msg.event24.sent.{$event->id()}.{$order->id()}";
        if ($this->state->get($state_key)) {
          // Already notified.
          continue;
        }

        // UTC ISO string.
        $startVal = $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()
          ? (string) $event->get('field_event_start')->value
          : '';

        $startTs = $startVal ? strtotime($startVal . ' UTC') : NULL;

        $ctx = [
          'title' => $event->label(),
          'event_url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
        ];

        if ($startTs) {
          $ctx['starts_at'] = $this->dateFormatter->format($startTs, 'custom', 'j M Y, g:ia T');
        }

        $this->messaging->queue('event_reminder', $mail, $ctx, ['langcode' => $order->language()->getId()]);
        $this->state->set($state_key, $this->time->getRequestTime());
        $notified++;
      }
    }

    $this->logger->info('EventReminderScheduler: queued @n reminders.', ['@n' => $notified]);
  }

}
