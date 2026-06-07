<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\myeventlane_notifications\NotificationContext;
use Drupal\myeventlane_notifications\NotificationDomain;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\node\NodeInterface;

/**
 * Personal-context in-app notifications (event updates, order updates).
 */
final class PersonalNotificationTriggerService {

  public function __construct(
    private readonly NotificationManager $notificationManager,
    private readonly AudienceResolver $audienceResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
    private readonly TranslationInterface $translation,
  ) {}

  /**
   * Notifies ticket holders / attendees when a published event changes materially.
   */
  public function onEventUpdated(NodeInterface $event, NodeInterface $original): void {
    if ($event->bundle() !== 'event' || !$event->isPublished() || !$original->isPublished()) {
      return;
    }

    $attendeeUids = $this->audienceResolver->resolveEventAttendeeUserIds($event);
    if ($attendeeUids === []) {
      return;
    }

    $eventId = (int) $event->id();
    $organiserUid = $this->audienceResolver->resolveEventOrganiserUid($event);
    $attendeeUids = array_values(array_filter(
      $attendeeUids,
      static fn(int $uid): bool => $organiserUid === NULL || $uid !== $organiserUid,
    ));
    if ($attendeeUids === []) {
      return;
    }

    $newState = $this->eventStateValue($event);
    $oldState = $this->eventStateValue($original);
    if ($newState === 'cancelled' && $oldState !== 'cancelled') {
      $this->queuePersonal(
        $attendeeUids,
        NotificationDomain::EVENT_UPDATES,
        MelNotification::PRIORITY_HIGH,
        80,
        (string) $this->translation->translate('Event cancelled'),
        (string) $this->translation->translate('@event has been cancelled by the organiser.', ['@event' => $event->label()]),
        'event_cancelled',
        'event_cancelled:' . $eventId,
        (string) $this->translation->translate('View event'),
        'entity.node.canonical',
        ['node' => $eventId],
      );
      return;
    }

    $startChanged = $this->fieldValueChanged($event, $original, 'field_event_start');
    $endChanged = $this->fieldValueChanged($event, $original, 'field_event_end');
    $venueChanged = $this->venueChanged($event, $original);

    if (!$startChanged && !$endChanged && !$venueChanged) {
      return;
    }

    if ($venueChanged && ($startChanged || $endChanged)) {
      $title = (string) $this->translation->translate('Event details updated');
      $message = (string) $this->translation->translate('Important details for @event have changed. Check the latest time and venue.', [
        '@event' => $event->label(),
      ]);
      $actionContext = 'event_updated_schedule_venue';
    }
    elseif ($venueChanged) {
      $title = (string) $this->translation->translate('Venue updated');
      $message = (string) $this->translation->translate('The venue for @event has changed.', ['@event' => $event->label()]);
      $actionContext = 'event_updated_venue';
    }
    else {
      $title = (string) $this->translation->translate('Event time updated');
      $message = (string) $this->translation->translate('The schedule for @event has changed.', ['@event' => $event->label()]);
      $actionContext = 'event_updated_schedule';
    }

    $this->queuePersonal(
      $attendeeUids,
      NotificationDomain::EVENT_UPDATES,
      MelNotification::PRIORITY_HIGH,
      70,
      $title,
      $message,
      $actionContext,
      $actionContext . ':' . $eventId . ':' . md5($title . $message),
      (string) $this->translation->translate('View event'),
      'entity.node.canonical',
      ['node' => $eventId],
    );
  }

  /**
   * Notifies the order owner when a ticket is assigned to a holder.
   */
  public function onTicketAssigned(Ticket $ticket, ?Ticket $original = NULL): void {
    $newStatus = (string) $ticket->get('status')->value;
    $oldStatus = $original ? (string) $original->get('status')->value : NULL;
    if ($newStatus !== Ticket::STATUS_ASSIGNED || $oldStatus === Ticket::STATUS_ASSIGNED) {
      return;
    }

    if ($ticket->get('order_id')->isEmpty()) {
      return;
    }

    $order = $ticket->get('order_id')->entity;
    if (!$order instanceof OrderInterface) {
      return;
    }

    $customerId = (int) $order->getCustomerId();
    if ($customerId < 1) {
      return;
    }

    $this->queuePersonal(
      [$customerId],
      NotificationDomain::ORDERS,
      MelNotification::PRIORITY_NORMAL,
      55,
      (string) $this->translation->translate('Ticket assigned'),
      (string) $this->translation->translate('Your ticket for order @order is ready.', [
        '@order' => $order->getOrderNumber() ?: (string) $order->id(),
      ]),
      'ticket_reassigned',
      'ticket_assigned:' . $ticket->id(),
      (string) $this->translation->translate('View tickets'),
      'myeventlane_checkout_flow.order_detail',
      ['commerce_order' => (int) $order->id()],
    );
  }

  /**
   * @param list<int> $userIds
   * @param array<string, scalar> $routeParameters
   */
  private function queuePersonal(
    array $userIds,
    string $domain,
    string $priority,
    int $priorityScore,
    string $title,
    string $message,
    string $actionContext,
    string $suppressionKey,
    string $actionLabel,
    string $routeName,
    array $routeParameters,
  ): void {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if ($userIds === []) {
      return;
    }

    try {
      $notification = $this->notificationManager->createNotification([
        'type' => MelNotification::TYPE_EVENT,
        'title' => $title,
        'message' => $message,
        'audience_type' => MelNotification::AUDIENCE_USER_IDS,
        'audience_data' => ['user_ids' => $userIds],
        'channels' => ['toast'],
        'status' => MelNotification::STATUS_DRAFT,
        'priority' => $priority,
        'priority_score' => $priorityScore,
        'suppression_key' => $suppressionKey,
        'context' => NotificationContext::PERSONAL,
        'domain' => $domain,
        'action_label' => $actionLabel,
        'route_name' => $routeName,
        'route_parameters' => $routeParameters,
        'action_context' => $actionContext,
      ]);
      $this->notificationManager->queueNotification($notification);
    }
    catch (\RuntimeException) {
      // Suppressed duplicate.
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to queue personal notification (@ctx): @message', [
        '@ctx' => $actionContext,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  private function eventStateValue(NodeInterface $event): string {
    if (!$event->hasField('field_event_state') || $event->get('field_event_state')->isEmpty()) {
      return '';
    }
    return (string) $event->get('field_event_state')->value;
  }

  private function fieldValueChanged(NodeInterface $event, NodeInterface $original, string $field): bool {
    if (!$event->hasField($field) || !$original->hasField($field)) {
      return FALSE;
    }
    $new = $event->get($field)->isEmpty() ? '' : (string) $event->get($field)->value;
    $old = $original->get($field)->isEmpty() ? '' : (string) $original->get($field)->value;
    return $new !== $old;
  }

  private function venueChanged(NodeInterface $event, NodeInterface $original): bool {
    foreach (['field_location', 'field_venue_name'] as $field) {
      if ($this->fieldValueChanged($event, $original, $field)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
