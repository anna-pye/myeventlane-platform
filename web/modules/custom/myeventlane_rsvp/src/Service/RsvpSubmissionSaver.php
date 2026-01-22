<?php

namespace Drupal\myeventlane_rsvp\Service;

/**
 *
 */
final class RsvpSubmissionSaver {

  /**
   *
   */
  public function createSubmission(
    int $event_id,
    string $first,
    string $last,
    string $email,
    string $notes = '',
  ) {
    $storage = $this->etm->getStorage('rsvp_submission');
    $event = $this->etm->getStorage('node')->load($event_id);

    $capacity = (int) ($event->get('field_rsvp_capacity')->value ?? 0);

    // Count confirmed RSVPs.
    $confirmed_count = $storage->getQuery()
      ->condition('event_id', $event_id)
      ->condition('status', 'confirmed')
      ->count()
      ->execute();

    $status = ($capacity > 0 && $confirmed_count >= $capacity)
        ? 'waitlist'
        : 'confirmed';

    $entity = $storage->create([
      'event_id' => $event_id,
      'first_name' => $first,
      'last_name' => $last,
      'email' => $email,
      'notes' => $notes,
      'status' => $status,
    ]);

    $entity->save();

    // Send relevant email.
    $this->sendStatusEmail($entity, $event);

    return $entity;
  }

  /**
   *
   */
  private function sendStatusEmail($rsvp, $event): void {
    $template = $rsvp->status->value === 'waitlist'
        ? 'mel_rsvp_waitlist_email'
        : 'mel_rsvp_confirmation_email';

    $body = [
      '#theme' => $template,
      '#event_title' => $event->label(),
      '#event_url' => $event->toUrl()->setAbsolute()->toString(),
      '#name' => $rsvp->first_name->value,
    ];

    $html = $this->renderer->renderRoot($body);

    $this->mail->mail(
        'myeventlane_rsvp',
        $template,
        $rsvp->email->value,
        \Drupal::languageManager()->getDefaultLanguage()->getId(),
        [
          'subject' => $template === 'mel_rsvp_waitlist_email'
            ? 'You are on the waitlist'
            : 'Your RSVP is confirmed',
          'body' => $html,
        ]
    );
  }

  /**
   *
   */
  private function sendPromotionEmail($rsvp, $event): void {
    $body = [
      '#theme' => 'mel_rsvp_promotion_email',
      '#event_title' => $event->label(),
      '#event_url' => $event->toUrl()->setAbsolute()->toString(),
      '#name' => $rsvp->first_name->value,
    ];

    $html = $this->renderer->renderRoot($body);

    $this->mail->mail(
        'myeventlane_rsvp',
        'rsvp_promotion',
        $rsvp->email->value,
        \Drupal::languageManager()->getDefaultLanguage()->getId(),
        [
          'subject' => 'Good news! You now have a spot',
          'body' => $html,
        ]
    );
  }

}
