<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\EventSubscriber;

use Drupal\Core\Entity\EntityEvent;
use Drupal\myeventlane_domain_events\Service\DomainEventBus;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmission;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Publishes rsvp.created domain events on RSVP insert.
 */
final class RsvpCreatedDomainEventSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DomainEventBus $eventBus,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events['entity.insert'][] = ['onEntityInsert'];
    return $events;
  }

  /**
   * Handles RSVP insert events.
   */
  public function onEntityInsert(EntityEvent $event): void {
    $entity = $event->getEntity();
    if (!$entity instanceof RsvpSubmission) {
      return;
    }

    $eventNodeId = (int) $entity->get('event_id')->target_id;
    $userId = (int) $entity->get('user_id')->target_id;

    $this->eventBus->publish(
      'rsvp.created',
      'rsvp_submission',
      (string) $entity->id(),
      [
        'rsvp_submission_id' => (int) $entity->id(),
        'event_node_id' => $eventNodeId > 0 ? $eventNodeId : NULL,
        'user_id' => $userId > 0 ? $userId : NULL,
        'email' => (string) $entity->get('email')->value,
        'guests' => max(1, (int) ($entity->get('guests')->value ?? 1)),
      ],
      [
        'event_node_id' => $eventNodeId > 0 ? $eventNodeId : NULL,
        'user_id' => $userId > 0 ? $userId : NULL,
        'source_module' => 'myeventlane_domain_events',
        'source_idempotency_key' => 'rsvp.created:' . (string) $entity->id(),
      ],
    );
  }

}

