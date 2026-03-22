<?php

declare(strict_types=1);

namespace Drupal\myeventlane_automation\Service;

/**
 * Builds subject/body pairs for automation messages.
 *
 * Australian English. MEL tone: calm, friendly, non-corporate.
 * Does not send; only composes content.
 */
final class AutomationMessageBuilder {

  /**
   * Builds attendee reminder subject and body.
   *
   * @param array<string, mixed> $context
   *   Keys: event_title, event_date, event_time, event_location, event_url.
   *
   * @return array{subject: string, body: string}
   */
  public function buildAttendeeReminder(array $context): array {
    $title = (string) ($context['event_title'] ?? 'your event');
    $subject = t('Reminder: @title is coming up soon', ['@title' => $title]);

    $lines = [
      t('Hi there,'),
      '',
      t('This is a friendly reminder that @title is coming up soon.', ['@title' => $title]),
    ];

    if (!empty($context['event_date'])) {
      $lines[] = t('Date: @date', ['@date' => $context['event_date']]);
    }
    if (!empty($context['event_time'])) {
      $lines[] = t('Time: @time', ['@time' => $context['event_time']]);
    }
    if (!empty($context['event_location'])) {
      $lines[] = t('Location: @location', ['@location' => $context['event_location']]);
    }
    if (!empty($context['event_url'])) {
      $lines[] = '';
      $lines[] = t('View your tickets and event details: @url', ['@url' => $context['event_url']]);
    }

    $lines[] = '';
    $lines[] = t('See you there!');

    return [
      'subject' => $subject,
      'body' => implode("\n", $lines),
    ];
  }

  /**
   * Builds organiser reminder subject and body.
   *
   * @param array<string, mixed> $context
   *   Keys: event_title, event_date, attendee_count.
   *
   * @return array{subject: string, body: string}
   */
  public function buildOrganiserReminder(array $context): array {
    $title = (string) ($context['event_title'] ?? 'your event');
    $subject = t('Reminder: @title starts soon', ['@title' => $title]);

    $attendees = (int) ($context['attendee_count'] ?? 0);
    $attendeeLine = $attendees > 0
      ? t('You have @count attendees registered.', ['@count' => $attendees])
      : t('Your event is coming up.');

    $body = implode("\n", [
      t('Hi there,'),
      '',
      t('A quick reminder that @title starts soon.', ['@title' => $title]),
      $attendeeLine,
      '',
      t('Make sure you\'re all set for the big day.'),
      '',
      t('View your event: @url', ['@url' => $context['event_url'] ?? '#']),
    ]);

    return [
      'subject' => $subject,
      'body' => $body,
    ];
  }

  /**
   * Builds post-event follow-up subject and body.
   *
   * @param array<string, mixed> $context
   *   Keys: event_title, attendee_count, attendees_url, duplicate_url.
   *
   * @return array{subject: string, body: string}
   */
  public function buildPostEventFollowup(array $context): array {
    $subject = t('Thanks for hosting with MyEventLane');

    $title = (string) ($context['event_title'] ?? 'your event');
    $attendees = (int) ($context['attendee_count'] ?? 0);

    $lines = [
      t('Hi there,'),
      '',
      t('Congratulations on hosting @title!', ['@title' => $title]),
      '',
    ];

    if ($attendees > 0) {
      $lines[] = t('You had @count attendees. Thank you for making it a success!', ['@count' => $attendees]);
      $lines[] = '';
    }

    $lines[] = t('Here are some next steps:');
    if (!empty($context['attendees_url'])) {
      $lines[] = t('- View your attendees: @url', ['@url' => $context['attendees_url']]);
    }
    if (!empty($context['duplicate_url'])) {
      $lines[] = t('- Run this event again: @url', ['@url' => $context['duplicate_url']]);
    }
    $lines[] = t('- Review performance in your dashboard');
    $lines[] = '';
    $lines[] = t('We hope to see your next event on MyEventLane soon.');

    return [
      'subject' => $subject,
      'body' => implode("\n", $lines),
    ];
  }

  /**
   * Builds draft reminder subject and body.
   *
   * @param array<string, mixed> $context
   *   Keys: event_title, edit_url.
   *
   * @return array{subject: string, body: string}
   */
  public function buildDraftReminder(array $context): array {
    $subject = t('Your event draft is nearly ready');

    $title = (string) ($context['event_title'] ?? 'your event');
    $editUrl = (string) ($context['edit_url'] ?? '#');

    $body = implode("\n", [
      t('Hi there,'),
      '',
      t('You started setting up "@title" on MyEventLane.', ['@title' => $title]),
      '',
      t('Your draft is nearly ready. Finish the details and publish when you\'re ready to go live.'),
      '',
      t('Edit your event: @url', ['@url' => $editUrl]),
      '',
      t('Need help? Check our help centre or reply to this email.'),
    ]);

    return [
      'subject' => $subject,
      'body' => $body,
    ];
  }

}
