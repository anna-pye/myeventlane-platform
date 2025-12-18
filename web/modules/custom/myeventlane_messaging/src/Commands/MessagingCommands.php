<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Commands;

use Drush\Commands\DrushCommands;

/**
 * Provides Drush commands for MyEventLane Messaging.
 */
final class MessagingCommands extends DrushCommands {

  /**
   * Runs all schedulers to enqueue messages.
   *
   * @command mel:msg-scan
   * @aliases mel-msg-scan
   * @usage drush mel:msg-scan
   */
  public function scan(): void {
    \Drupal::service('myeventlane_messaging.scheduler.boost')->scan();
    \Drupal::service('myeventlane_messaging.scheduler.cart')->scan();
    \Drupal::service('myeventlane_messaging.scheduler.event')->scan();

    $this->logger()->success('Schedulers complete (boost/cart/event).');
  }

  /**
   * Runs the messaging queue now.
   *
   * @command mel:msg-run
   * @aliases mel-msg-run
   * @usage drush mel:msg-run
   */
  public function run(): void {
    $queue = \Drupal::queue('myeventlane_messaging');
    while ($item = $queue->claimItem()) {
      try {
        \Drupal::service('myeventlane_messaging.manager')->sendNow($item->data);
        $queue->deleteItem($item);
      }
      catch (\Throwable $e) {
        $queue->releaseItem($item);
        throw $e;
      }
    }

    $this->logger()->success('Queue processed.');
  }

  /**
   * Queues a test message.
   *
   * @param string $type
   *   The message template key.
   * @param string $email
   *   The email address.
   * @param int|null $id
   *   Optional entity ID.
   *
   * @command mel:msg-test
   * @aliases mel-msg-test
   * @usage drush mel:msg-test boost_reminder you@example.com
   */
  public function test(string $type, string $email, ?int $id = NULL): void {
    \Drupal::service('myeventlane_messaging.manager')->queue($type, $email, ['entity_id' => $id]);
    $this->logger()->success("Queued test message $type to $email.");
  }

}
