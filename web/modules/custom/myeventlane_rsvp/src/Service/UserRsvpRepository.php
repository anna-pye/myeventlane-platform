<?php

public function getEventRsvpCount(int $event_id, string $status): int {
  return $this->storage->getQuery()
    ->condition('event_id', $event_id)
    ->condition('status', $status)
    ->count()
    ->execute();
}

public function getEventRsvps(int $event_id): array {
  $ids = $this->storage->getQuery()
    ->condition('event_id', $event_id)
    ->sort('created', 'DESC')
    ->execute();

  if (!$ids) {
    return [];
  }

  $items = [];
  foreach ($this->storage->loadMultiple($ids) as $entity) {
    $items[] = [
      'id' => $entity->id(),
      'first_name' => $entity->first_name->value,
      'last_name' => $entity->last_name->value,
      'email' => $entity->email->value,
      'status' => $entity->status->value,
      'created' => (int) $entity->created->value,
    ];
  }
  return $items;
}