<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\node\NodeInterface;

/**
 * Maps platform events into queued MEL notifications (no synchronous mass sends).
 */
final class PlatformNotificationTriggerService {

  private const STATE_EVENT_PUBLISHED_PREFIX = 'myeventlane_notifications.event_published.';

  public function __construct(
    private readonly NotificationManager $notificationManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
    private readonly AudienceResolver $audienceResolver,
    private readonly TranslationInterface $translation,
    private readonly StateInterface $state,
  ) {}

  public function onOrderPaid(OrderInterface $order): void {
    $customerId = (int) $order->getCustomerId();
    if ($customerId < 1) {
      return;
    }

    $eventIds = [];
    foreach ($order->getItems() as $item) {
      if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        $eventIds[] = (int) $item->get('field_target_event')->target_id;
      }
    }
    $eventIds = array_values(array_unique(array_filter($eventIds)));
    if ($eventIds === []) {
      return;
    }

    $titles = [];
    foreach ($eventIds as $eventId) {
      $node = $this->entityTypeManager->getStorage('node')->load($eventId);
      if ($node instanceof NodeInterface) {
        $titles[] = $node->label();
      }
    }
    $label = $titles !== [] ? implode(', ', $titles) : $this->shortOrderLabel($order);

    try {
      $notification = $this->notificationManager->createNotification([
        'type' => MelNotification::TYPE_TICKET,
        'title' => (string) $this->translation->translate('Your ticket is confirmed'),
        'message' => (string) $this->translation->translate('Thanks for your purchase. Your ticket for @events is confirmed.', ['@events' => $label]),
        'audience_type' => MelNotification::AUDIENCE_USER_IDS,
        'audience_data' => ['user_ids' => [$customerId]],
        'channels' => ['toast'],
        'status' => MelNotification::STATUS_DRAFT,
        'priority' => MelNotification::PRIORITY_HIGH,
        'priority_score' => 90,
        'suppression_key' => 'order_paid:' . $order->id(),
        'group_key' => 'ticket_order:' . $order->id(),
        'action_label' => (string) $this->translation->translate('View tickets'),
        'action_uri' => '/my-tickets',
        'action_context' => 'checkout_ticket_confirm',
      ]);
      $this->notificationManager->queueNotification($notification);
    }
    catch (\RuntimeException $e) {
      // Suppression or duplicate path; already logged in manager.
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to queue ticket confirmation for order @order: @message', [
        '@order' => $order->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  public function onEventPublished(NodeInterface $node): void {
    if ($node->bundle() !== 'event' || !$node->isPublished()) {
      return;
    }

    $nid = (int) $node->id();
    $stateKey = self::STATE_EVENT_PUBLISHED_PREFIX . $nid;
    if ($this->state->get($stateKey)) {
      return;
    }

    $termIds = [];
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      foreach ($node->get('field_category')->referencedEntities() as $term) {
        $termIds[] = (int) $term->id();
      }
    }

    $followerUids = $this->audienceResolver->resolveCategoryFollowerUserIds($termIds);
    if ($followerUids === []) {
      $this->state->set($stateKey, 1);
      return;
    }

    $eventPath = '/node/' . $nid;
    try {
      $eventPath = Url::fromRoute('entity.node.canonical', ['node' => $nid], ['absolute' => FALSE])->toString();
    }
    catch (\Throwable) {
    }

    try {
      $notification = $this->notificationManager->createNotification([
        'type' => MelNotification::TYPE_EVENT,
        'title' => (string) $this->translation->translate('New event: @title', ['@title' => $node->label()]),
        'message' => (string) $this->translation->translate('An event you may be interested in is now live.'),
        'audience_type' => MelNotification::AUDIENCE_USER_IDS,
        'audience_data' => ['user_ids' => $followerUids],
        'channels' => ['toast'],
        'status' => MelNotification::STATUS_DRAFT,
        'priority' => MelNotification::PRIORITY_NORMAL,
        'priority_score' => 40,
        'suppression_key' => 'event_published:' . $nid,
        'group_key' => 'category_event_digest',
        'action_label' => (string) $this->translation->translate('See event'),
        'action_uri' => $eventPath,
        'action_context' => 'event_published_follower',
      ]);
      $this->notificationManager->queueNotification($notification);
      $this->state->set($stateKey, 1);
    }
    catch (\RuntimeException $e) {
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to queue event published notification for node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  public function onRsvpEntityInserted(EntityInterface $submission): void {
    if (!$submission->hasField('event_id') || !$submission->hasField('user_id')) {
      return;
    }

    $eventId = (int) $submission->get('event_id')->target_id;
    if ($eventId < 1) {
      return;
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      return;
    }

    $attendeeUid = (int) $submission->get('user_id')->target_id;

    $eventPath = '/node/' . $eventId;
    try {
      $eventPath = Url::fromRoute('entity.node.canonical', ['node' => $eventId], ['absolute' => FALSE])->toString();
    }
    catch (\Throwable) {
    }

    if ($attendeeUid > 0) {
      try {
        $confirm = $this->notificationManager->createNotification([
          'type' => MelNotification::TYPE_EVENT,
          'title' => (string) $this->translation->translate('RSVP confirmed'),
          'message' => (string) $this->translation->translate('You are confirmed for @event.', ['@event' => $event->label()]),
          'audience_type' => MelNotification::AUDIENCE_USER_IDS,
          'audience_data' => ['user_ids' => [$attendeeUid]],
          'channels' => ['toast'],
          'status' => MelNotification::STATUS_DRAFT,
          'priority_score' => 55,
          'suppression_key' => 'rsvp_confirm:' . $submission->id(),
          'action_label' => (string) $this->translation->translate('View event'),
          'action_uri' => $eventPath,
          'action_context' => 'rsvp_attendee_confirm',
        ]);
        $this->notificationManager->queueNotification($confirm);
      }
      catch (\RuntimeException $e) {
      }
      catch (\Throwable $e) {
        $this->logger->error('Failed RSVP attendee notification @id: @message', [
          '@id' => $submission->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $organiserUid = $this->audienceResolver->resolveEventOrganiserUid($event);
    if ($organiserUid === NULL || $organiserUid === $attendeeUid) {
      return;
    }

    try {
      $vendorNote = $this->notificationManager->createNotification([
        'type' => MelNotification::TYPE_SYSTEM,
        'title' => (string) $this->translation->translate('New RSVP'),
        'message' => (string) $this->translation->translate('Someone RSVP’d to @event.', ['@event' => $event->label()]),
        'audience_type' => MelNotification::AUDIENCE_USER_IDS,
        'audience_data' => ['user_ids' => [$organiserUid]],
        'channels' => ['toast'],
        'status' => MelNotification::STATUS_DRAFT,
        'priority' => MelNotification::PRIORITY_NORMAL,
        'priority_score' => 35,
        'suppression_key' => 'rsvp_vendor:' . $submission->id(),
        'action_label' => (string) $this->translation->translate('Review event'),
        'action_uri' => $eventPath,
        'action_context' => 'rsvp_vendor_alert',
      ]);
      $this->notificationManager->queueNotification($vendorNote);
    }
    catch (\RuntimeException $e) {
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed RSVP vendor notification @id: @message', [
        '@id' => $submission->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Detects first transition to published for updates.
   */
  public function onNodeUpdate(EntityInterface $entity): void {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'event') {
      return;
    }
    $original = $entity->original ?? NULL;
    if (!$original instanceof NodeInterface) {
      return;
    }
    if (!$entity->isPublished() || $original->isPublished()) {
      return;
    }
    $this->onEventPublished($entity);
  }

  private function shortOrderLabel(OrderInterface $order): string {
    return $order->label() ?: (string) $order->id();
  }

}
