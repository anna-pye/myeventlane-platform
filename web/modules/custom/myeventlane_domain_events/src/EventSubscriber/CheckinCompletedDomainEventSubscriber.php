<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\EventSubscriber;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityEvent;
use Drupal\myeventlane_domain_events\Service\DomainEventBus;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmission;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Publishes checkin.completed events for ticket/RSVP/attendee check-ins.
 */
final class CheckinCompletedDomainEventSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DomainEventBus $eventBus,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events['entity.update'][] = ['onEntityUpdate'];
    return $events;
  }

  /**
   * Handles entity update events and detects check-in transitions.
   */
  public function onEntityUpdate(EntityEvent $event): void {
    $entity = $event->getEntity();
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }

    $entityTypeId = $entity->getEntityTypeId();
    if (!$this->isCheckinTransition($entity)) {
      return;
    }

    $eventNodeId = $this->extractEventNodeId($entity);
    $userId = $this->extractUserId($entity);
    $idempotencyKey = sprintf(
      'checkin.completed:%s:%s:%s',
      $entityTypeId,
      (string) $entity->id(),
      (string) ($entity->get('changed')->value ?? time()),
    );

    $this->eventBus->publish(
      'checkin.completed',
      $entityTypeId,
      (string) $entity->id(),
      [
        'entity_type' => $entityTypeId,
        'entity_id' => (int) $entity->id(),
        'event_node_id' => $eventNodeId,
        'user_id' => $userId,
        'quantity' => 1,
      ],
      [
        'event_node_id' => $eventNodeId,
        'user_id' => $userId,
        'source_module' => 'myeventlane_domain_events',
        'source_idempotency_key' => $idempotencyKey,
      ],
    );
  }

  /**
   * Returns TRUE only when this update changed state to checked-in.
   */
  private function isCheckinTransition(ContentEntityInterface $entity): bool {
    if ($entity instanceof Ticket) {
      $new = (string) $entity->get('status')->value;
      $old = $this->originalFieldValue($entity, 'status');
      return $new === Ticket::STATUS_CHECKED_IN && $old !== Ticket::STATUS_CHECKED_IN;
    }

    if ($entity instanceof RsvpSubmission) {
      $new = (bool) $entity->get('checked_in')->value;
      $old = (bool) $this->originalFieldValue($entity, 'checked_in');
      return $new && !$old;
    }

    if ($entity->getEntityTypeId() === 'event_attendee' && $entity->hasField('status')) {
      $new = (string) $entity->get('status')->value;
      $old = (string) $this->originalFieldValue($entity, 'status');
      return $new === 'checked_in' && $old !== 'checked_in';
    }

    return FALSE;
  }

  /**
   * Resolves event node ID from known field names.
   */
  private function extractEventNodeId(ContentEntityInterface $entity): ?int {
    foreach (['event_id', 'event'] as $field) {
      if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
        $id = (int) $entity->get($field)->target_id;
        if ($id > 0) {
          return $id;
        }
      }
    }
    return NULL;
  }

  /**
   * Resolves user ID from known field names.
   */
  private function extractUserId(ContentEntityInterface $entity): ?int {
    foreach (['user_id', 'uid', 'purchaser_uid'] as $field) {
      if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
        $id = (int) $entity->get($field)->target_id;
        if ($id > 0) {
          return $id;
        }
      }
    }
    return NULL;
  }

  /**
   * Reads a field value from the original entity, if available.
   */
  private function originalFieldValue(ContentEntityInterface $entity, string $fieldName): mixed {
    if (!property_exists($entity, 'original') || !$entity->original instanceof ContentEntityInterface) {
      return NULL;
    }
    if (!$entity->original->hasField($fieldName) || $entity->original->get($fieldName)->isEmpty()) {
      return NULL;
    }
    return $entity->original->get($fieldName)->value;
  }

}

