<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

final class RsvpPromotionManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
  ) {}

  public function promoteNext(int $event_id): ?int {
    $storage = $this->etm->getStorage('rsvp_submission');

    $next = $storage->getQuery()
      ->condition('event_id', $event_id)
      ->condition('status', 'waitlist')
      ->sort('created', 'ASC')
      ->range(0, 1)
      ->execute();

    if (!$next) {
      return NULL;
    }

    $id = reset($next);
    $entity = $storage->load($id);

    $entity->set('status', 'confirmed');
    $entity->save();

    return $id;
  }
}