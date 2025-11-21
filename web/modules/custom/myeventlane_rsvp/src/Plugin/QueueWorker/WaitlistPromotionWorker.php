<?php

namespace Drupal\myeventlane_rsvp\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmission;
use Drupal\node\Entity\Node;

/**
 * @QueueWorker(
 *   id = "myeventlane_waitlist_promotion",
 *   title = @Translation("MEL Waitlist Auto Promotion Worker"),
 *   cron = {"time" = 60}
 * )
 */
final class WaitlistPromotionWorker extends QueueWorkerBase {

  public function processItem($data) {
    $rsvp = RsvpSubmission::load($data['rsvp_id']);
    if (!$rsvp || $rsvp->get('status')->value !== 'waitlist') {
      return;
    }

    $event = Node::load($rsvp->get('event')->value);
    if (!$event) {
      return;
    }

    // Determine capacity
    $capacity = (int) $event->get('field_capacity')->value ?: 0;
    if ($capacity <= 0) {
      return;
    }

    // Check if a slot is free
    $confirmed_count = \Drupal::service('myeventlane_rsvp.capacity')
      ->getCurrentCount($event->id());

    if ($confirmed_count >= $capacity) {
      return;
    }

    // Promote
    $rsvp->set('status', 'promoted');
    $rsvp->save();

    // Send promotion email
    \Drupal::service('myeventlane_rsvp.mailer')
      ->sendWaitlistPromotion($rsvp, $event);

      $queue = \Drupal::service('queue')->get('myeventlane_rsvp_sms_delivery');
      $queue->createItem([
        'to' => $phone,
        'msg' => $sms_body,
      ]);

      $sms->queue('+' . $submission->get('field_phone')->value,
      "A spot opened up! You're now confirmed for {$event_title}. Details sent via email."
    );
  }

}