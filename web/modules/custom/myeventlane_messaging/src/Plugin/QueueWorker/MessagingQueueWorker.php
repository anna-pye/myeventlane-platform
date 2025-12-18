<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;

/**
 * Processes queued MyEventLane messages.
 *
 * @QueueWorker(
 *   id = "myeventlane_messaging",
 *   title = @Translation("MyEventLane Messaging queue"),
 *   cron = {"time" = 60}
 * )
 */
final class MessagingQueueWorker extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    /** @var \Drupal\myeventlane_messaging\Service\MessagingManager $mgr */
    $mgr = \Drupal::service('myeventlane_messaging.manager');
    $mgr->sendNow($data);
  }

}
