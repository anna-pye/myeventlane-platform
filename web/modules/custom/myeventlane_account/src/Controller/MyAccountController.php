<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Url;
use Drupal\image\ImageStyleInterface;
use Drupal\myeventlane_account\Service\AccountLinksService;
use Drupal\myeventlane_core\Service\DisplayNameResolver;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for the customer My Account experience.
 */
final class MyAccountController extends ControllerBase {

  /**
   * Constructs MyAccountController.
   */
  public function __construct(
    private readonly AccountLinksService $accountLinksService,
    private readonly DisplayNameResolver $displayNameResolver,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_account.account_links'),
      $container->get('myeventlane_core.display_name_resolver'),
      $container->get('datetime.time')
    );
  }

  /**
   * Loads the image style for event card thumbnails.
   *
   * Uses 'medium' if available, falls back to 'thumbnail' or 'large'.
   *
   * @return \Drupal\image\ImageStyleInterface|null
   *   The image style entity, or NULL if none exist.
   */
  private function getEventImageStyle(): ?ImageStyleInterface {
    $storage = $this->entityTypeManager()->getStorage('image_style');
    foreach (['medium', 'thumbnail', 'large'] as $styleId) {
      $style = $storage->load($styleId);
      if ($style instanceof ImageStyleInterface) {
        return $style;
      }
    }
    return NULL;
  }

  /**
   * Renders the customer My Account dashboard.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array or redirect for anonymous users.
   */
  public function dashboard(): array|RedirectResponse {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('user.login', [], ['query' => ['destination' => '/my-account']])->toString(),
        302
      );
    }

    $displayName = $this->displayNameResolver->getDisplayName($account);
    $userId = (int) $account->id();
    $now = (int) $this->time->getRequestTime();

    [$upcomingTickets, $upcomingRsvps, $pastEvents] = $this->buildEventData($userId, $account->getEmail(), $now);

    $accountLinks = $this->accountLinksService->buildLinks('dashboard');

    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'route'])
      ->addCacheTags(['user:' . $userId, 'node_list'])
      ->setCacheMaxAge(300);
    foreach (array_merge($upcomingTickets, $upcomingRsvps, $pastEvents) as $event) {
      $cache->addCacheTags(['node:' . $event['id']]);
    }

    $reviewEligible = $this->getReviewEligibleEvents(array_slice($pastEvents, 0, 3), $userId);

    $build = [
      '#theme' => 'myeventlane_my_account_dashboard',
      '#display_name' => $displayName,
      '#upcoming_tickets' => array_slice($upcomingTickets, 0, 3),
      '#upcoming_rsvps' => array_slice($upcomingRsvps, 0, 3),
      '#past_events' => array_slice($pastEvents, 0, 3),
      '#account_links' => $accountLinks,
      '#show_review_cta' => $this->config('myeventlane_account.reviews')->get('enabled') ?? FALSE,
      '#review_eligible' => $reviewEligible,
      '#attached' => [
        'library' => ['myeventlane_theme/global-styling'],
      ],
    ];
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Redirects to the user edit form (Profile & Settings).
   */
  public function settings(): RedirectResponse {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return new RedirectResponse(Url::fromRoute('user.login')->toString(), 302);
    }
    return new RedirectResponse(
      Url::fromRoute('entity.user.edit_form', ['user' => $account->id()])->toString(),
      302
    );
  }

  /**
   * Renders the Past Events page.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array or redirect for anonymous users.
   */
  public function pastEvents(): array|RedirectResponse {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('user.login', [], ['query' => ['destination' => '/my-past-events']])->toString(),
        302
      );
    }

    $userId = (int) $account->id();
    $now = (int) $this->time->getRequestTime();

    [, , $pastEvents] = $this->buildEventData($userId, $account->getEmail(), $now);

    $accountLinks = $this->accountLinksService->buildLinks('past_events');

    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'route'])
      ->addCacheTags(['user:' . $userId, 'node_list'])
      ->setCacheMaxAge(300);
    foreach ($pastEvents as $event) {
      $cache->addCacheTags(['node:' . $event['id']]);
    }

    $reviewEligible = $this->getReviewEligibleEvents($pastEvents, $userId);

    $build = [
      '#theme' => 'myeventlane_my_account_past_events',
      '#past_events' => $pastEvents,
      '#account_links' => $accountLinks,
      '#show_review_cta' => $this->config('myeventlane_account.reviews')->get('enabled') ?? FALSE,
      '#review_eligible' => $reviewEligible,
      '#attached' => [
        'library' => ['myeventlane_theme/global-styling'],
      ],
    ];
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Builds event data (tickets, RSVPs, past) from attendees and orders.
   *
   * Past events: event_end < now (or start < now if no end - documented).
   * Upcoming: event_end >= now (or start >= now if no end).
   *
   * @return array{0: array, 1: array, 2: array}
   *   [upcoming_tickets, upcoming_rsvps, past_events]
   */
  private function buildEventData(int $userId, string $userEmail, int $now): array {
    $eventMap = [];
    $nodeStorage = $this->entityTypeManager()->getStorage('node');

    // Load event_attendee records for this user.
    $attendeeStorage = $this->entityTypeManager()->getStorage('event_attendee');
    $attendeeIds = $attendeeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', EventAttendee::STATUS_CONFIRMED)
      ->condition('uid', $userId)
      ->execute();

    $attendees = !empty($attendeeIds) ? $attendeeStorage->loadMultiple($attendeeIds) : [];

    foreach ($attendees as $attendee) {
      $eventId = $attendee->get('event')->target_id;
      if (!$eventId || isset($eventMap[$eventId])) {
        continue;
      }

      $event = $nodeStorage->load($eventId);
      if (!$event || $event->bundle() !== 'event') {
        continue;
      }

      $orderItemId = $attendee->hasField('order_item') && !$attendee->get('order_item')->isEmpty() ? $attendee->get('order_item')->target_id : NULL;
      $eventMap[$eventId] = $this->buildEventItem($event, $attendee->get('source')->value ?? 'ticket', $attendee->get('ticket_code')->value ?? '', (int) $attendee->id(), $orderItemId !== NULL ? (int) $orderItemId : NULL);
    }

    // Load Commerce orders for this user.
    $orderStorage = $this->entityTypeManager()->getStorage('commerce_order');
    $orderIds = $orderStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', 'completed')
      ->condition('uid', $userId)
      ->execute();

    $orders = !empty($orderIds) ? $orderStorage->loadMultiple($orderIds) : [];

    foreach ($orders as $order) {
      foreach ($order->getItems() as $orderItem) {
        if (!$orderItem->hasField('field_target_event') || $orderItem->get('field_target_event')->isEmpty()) {
          continue;
        }

        $event = $orderItem->get('field_target_event')->entity;
        if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
          continue;
        }

        $eventId = (int) $event->id();
        if (isset($eventMap[$eventId])) {
          continue;
        }

        $attendeeIds = $this->entityTypeManager()
          ->getStorage('event_attendee')
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('event', $eventId)
          ->condition('order_item', $orderItem->id())
          ->range(0, 1)
          ->execute();

        $ticketCode = '';
        $attendeeId = NULL;
        if (!empty($attendeeIds)) {
          $attendee = $attendeeStorage->load(reset($attendeeIds));
          if ($attendee) {
            $ticketCode = $attendee->get('ticket_code')->value ?? '';
            $attendeeId = (int) $attendee->id();
          }
        }

        $eventMap[$eventId] = $this->buildEventItem($event, 'ticket', $ticketCode, $attendeeId, (int) $orderItem->id());
      }
    }

    // Also check rsvp_submission for RSVPs not in event_attendee.
    if ($this->entityTypeManager()->hasDefinition('rsvp_submission')) {
      $rsvpStorage = $this->entityTypeManager()->getStorage('rsvp_submission');
      $rsvpIds = $rsvpStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('user_id', $userId)
        ->condition('status', 'confirmed')
        ->execute();

      $rsvps = !empty($rsvpIds) ? $rsvpStorage->loadMultiple($rsvpIds) : [];

      foreach ($rsvps as $rsvp) {
        if (!$rsvp->hasField('event_id') || $rsvp->get('event_id')->isEmpty()) {
          continue;
        }

        $event = $rsvp->get('event_id')->entity;
        if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
          continue;
        }

        $eventId = (int) $event->id();
        if (isset($eventMap[$eventId])) {
          continue;
        }

        $eventMap[$eventId] = $this->buildEventItem($event, 'rsvp', '', NULL, NULL);
      }
    }

    $upcomingTickets = [];
    $upcomingRsvps = [];
    $pastEvents = [];

    foreach ($eventMap as $eventData) {
      // Past: end < now (or start < now if no end - fallback).
      $isPast = $eventData['end_timestamp'] > 0
        ? $eventData['end_timestamp'] < $now
        : $eventData['start_timestamp'] < $now;

      if ($isPast) {
        $pastEvents[] = $eventData;
      }
      else {
        if ($eventData['source'] === 'ticket') {
          $upcomingTickets[] = $eventData;
        }
        else {
          $upcomingRsvps[] = $eventData;
        }
      }
    }

    $sortTs = fn($a, $b) => ($a['end_timestamp'] ?: $a['start_timestamp']) <=> ($b['end_timestamp'] ?: $b['start_timestamp']);
    usort($upcomingTickets, $sortTs);
    usort($upcomingRsvps, $sortTs);
    usort($pastEvents, fn($a, $b) => ($b['end_timestamp'] ?: $b['start_timestamp']) <=> ($a['end_timestamp'] ?: $a['start_timestamp']));

    return [$upcomingTickets, $upcomingRsvps, $pastEvents];
  }

  /**
   * Builds a single event item for display.
   */
  private function buildEventItem(NodeInterface $event, string $source, string $ticketCode, ?int $attendeeId, ?int $orderItemId): array {
    $eventId = (int) $event->id();
    $startTime = $this->getEventStartTime($event);
    $endTime = $this->getEventEndTime($event);

    $imageUrl = '';
    if ($event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty()) {
      $file = $event->get('field_event_image')->entity;
      if ($file && $file->getFileUri()) {
        $style = $this->getEventImageStyle();
        $imageUrl = $style ? $style->buildUrl($file->getFileUri()) : '';
      }
    }

    $location = '';
    if ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      $location = $event->get('field_venue_name')->value;
    }
    elseif ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $location = $event->get('field_location')->value;
    }

    return [
      'id' => $eventId,
      'title' => $event->label(),
      'url' => $event->toUrl()->toString(),
      'ics_url' => Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => $eventId])->toString(),
      'start_date' => $startTime ? date('M j, Y', $startTime) : '',
      'start_time' => $startTime ? date('g:ia', $startTime) : '',
      'start_timestamp' => $startTime ?: 0,
      'end_timestamp' => $endTime ?: 0,
      'image_url' => $imageUrl,
      'location' => $location,
      'source' => $source,
      'ticket_code' => $ticketCode,
      'attendee_id' => $attendeeId,
      'order_item_id' => $orderItemId,
    ];
  }

  /**
   * Gets event start timestamp.
   */
  private function getEventStartTime(NodeInterface $event): ?int {
    if (!$event->hasField('field_event_start') || $event->get('field_event_start')->isEmpty()) {
      return NULL;
    }
    try {
      $time = strtotime($event->get('field_event_start')->value);
      return $time ?: NULL;
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Gets event end timestamp.
   *
   * Past events MUST use end time when available. If only start exists,
   * fallback uses start (documented: single-day events).
   */
  private function getEventEndTime(NodeInterface $event): ?int {
    if (!$event->hasField('field_event_end') || $event->get('field_event_end')->isEmpty()) {
      return NULL;
    }
    try {
      $time = strtotime($event->get('field_event_end')->value);
      return $time ?: NULL;
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Gets event IDs for which the user is eligible to leave a review.
   *
   * Eligibility: feature enabled, event has reviews enabled, event ended within
   * window_days, user attended, user has not already reviewed.
   *
   * @param array $pastEvents
   *   Array of past event data (id, end_timestamp, etc.).
   * @param int $userId
   *   The user ID.
   *
   * @return array
   *   Map of event_id => TRUE for eligible events.
   */
  private function getReviewEligibleEvents(array $pastEvents, int $userId): array {
    $config = $this->config('myeventlane_account.reviews');
    if (!$config->get('enabled')) {
      return [];
    }

    $windowDays = (int) ($config->get('window_days') ?? 14);
    $windowSeconds = $windowDays * 86400;
    $now = (int) $this->time->getRequestTime();

    $eligible = [];
    $nodeStorage = $this->entityTypeManager()->getStorage('node');

    if (!$this->entityTypeManager()->hasDefinition('event_review')) {
      return [];
    }

    $reviewStorage = $this->entityTypeManager()->getStorage('event_review');
    $existingIds = $reviewStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $userId)
      ->execute();
    $existingByEvent = [];
    if (!empty($existingIds)) {
      foreach ($reviewStorage->loadMultiple($existingIds) as $review) {
        $eid = $review->getEventId();
        if ($eid) {
          $existingByEvent[$eid] = TRUE;
        }
      }
    }

    foreach ($pastEvents as $eventData) {
      $eventId = $eventData['id'] ?? 0;
      if (!$eventId || isset($existingByEvent[$eventId])) {
        continue;
      }

      $endTs = $eventData['end_timestamp'] ?? $eventData['start_timestamp'] ?? 0;
      if ($endTs <= 0 || $endTs >= $now) {
        continue;
      }
      if (($now - $endTs) > $windowSeconds) {
        continue;
      }

      $event = $nodeStorage->load($eventId);
      if (!$event || $event->bundle() !== 'event') {
        continue;
      }
      if (!$event->hasField('field_reviews_enabled') || $event->get('field_reviews_enabled')->isEmpty()) {
        continue;
      }
      if (!(bool) $event->get('field_reviews_enabled')->value) {
        continue;
      }

      $eligible[$eventId] = TRUE;
    }

    return $eligible;
  }

}
