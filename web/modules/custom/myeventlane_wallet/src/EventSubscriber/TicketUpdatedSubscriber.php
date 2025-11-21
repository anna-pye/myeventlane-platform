<?php

namespace Drupal\myeventlane_wallet\EventSubscriber;

use Drupal\Core\Entity\EntityEvents;
use Drupal\Core\Entity\EntityEvent;
use Drupal\Core\Entity\EntityEventSubscriberInterface;

class TicketUpdatedSubscriber implements EntityEventSubscriberInterface {

  public static function getSubscribedEvents() {
    $events[EntityEvents::UPDATE][] = ['onUpdate'];
    return $events;
  }

  public function onUpdate(EntityEvent $event) {
    $entity = $event->getEntity();

    if ($entity->getEntityTypeId() === 'commerce_order_item') {
      // TODO: regenerate wallet pass
    }
  }

}