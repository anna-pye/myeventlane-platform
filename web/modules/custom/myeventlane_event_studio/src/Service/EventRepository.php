<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\myeventlane_core\Service\EntityIdNormalizer;
use Drupal\myeventlane_event_studio\DTO\MelEventData;
use Drupal\myeventlane_event_state\Service\EventStateResolverInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Single read path for event nodes into MelEventData (vendor layer uses this, not loadMultiple).
 *
 * Internally may batch-load nodes; vendor code must not call node storage directly for events.
 */
final class EventRepository {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityIdNormalizer $entityIdNormalizer,
    private readonly LoggerInterface $logger,
    private readonly ?EventStateResolverInterface $eventStateResolver = NULL,
    private readonly ?EventCapacityServiceInterface $capacityService = NULL,
  ) {}

  /**
   * Loads one event as DTO, or NULL if missing / wrong bundle.
   */
  public function load(int $nid): ?MelEventData {
    if ($nid <= 0) {
      return NULL;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    return $node instanceof NodeInterface && $node->bundle() === 'event'
      ? $this->mapNode($node)
      : NULL;
  }

  /**
   * Loads many events as DTOs (stable order matches input IDs that resolved).
   *
   * @param array<int|string, mixed> $raw_ids
   *
   * @return list<MelEventData>
   */
  public function loadMany(array $raw_ids): array {
    $nids = $this->entityIdNormalizer->normalizeNodeIds(array_values($raw_ids));
    if ($nids === []) {
      return [];
    }
    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $out = [];
    foreach ($nids as $nid) {
      $node = $nodes[$nid] ?? NULL;
      if ($node instanceof NodeInterface && $node->bundle() === 'event') {
        $out[] = $this->mapNode($node);
      }
    }
    return $out;
  }

  /**
   * Query event nids for a user (newest first).
   *
   * @return list<int>
   */
  public function queryEventNidsForUser(int $uid, int $limit = 24, bool $accessCheck = TRUE): array {
    if ($uid <= 0) {
      return [];
    }
    try {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck($accessCheck)
        ->condition('type', 'event')
        ->condition('uid', $uid)
        ->sort('changed', 'DESC')
        ->range(0, $limit);
      $result = $query->execute();
      return $this->entityIdNormalizer->normalizeNodeIds(array_values($result));
    }
    catch (\Throwable $e) {
      $this->logger->error('EventRepository query failed: @m', ['@m' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Loads events for a user in one call (query + map).
   *
   * @return list<MelEventData>
   */
  public function loadRecentForUser(int $uid, int $limit = 24): array {
    return $this->loadMany($this->queryEventNidsForUser($uid, $limit, TRUE));
  }

  private function mapNode(NodeInterface $node): MelEventData {
    $dto = new MelEventData();
    $dto->id = (int) $node->id();
    $dto->title = $node->label();
    if ($node->hasField('field_event_summary') && !$node->get('field_event_summary')->isEmpty()) {
      $dto->summary = (string) $node->get('field_event_summary')->value;
    }
    if ($node->hasField('field_event_start') && !$node->get('field_event_start')->isEmpty()) {
      $dto->start = (string) $node->get('field_event_start')->value;
      $dto->start_timestamp = $this->timestampFromFieldItem($node->get('field_event_start')->first());
    }
    if ($node->hasField('field_event_end') && !$node->get('field_event_end')->isEmpty()) {
      $dto->end = (string) $node->get('field_event_end')->value;
      $dto->end_timestamp = $this->timestampFromFieldItem($node->get('field_event_end')->first());
    }
    if ($node->hasField('field_location') && !$node->get('field_location')->isEmpty()) {
      $dto->location = $node->get('field_location')->getValue()[0] ?? [];
    }
    $dto->venue_mode = 'one_off';
    if ($node->hasField('field_venue') && !$node->get('field_venue')->isEmpty()) {
      $dto->venue_mode = 'saved';
    }
    if ($node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()) {
      $dto->ticket_type = (string) $node->get('field_event_type')->value;
    }
    $dto->published = $node->isPublished();
    if ($node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()) {
      $dto->moderation_state = (string) $node->get('moderation_state')->value;
    }
    $dto->changed = (int) $node->getChangedTime();
    $dto->created = (int) $node->getCreatedTime();
    $dto->venue_label = $this->buildVenueLabel($node);
    $dto->image_url = $this->extractImageUrl($node);
    $dto->has_cover_image = $dto->image_url !== NULL;
    if ($node->hasField('field_is_series_template') && !$node->get('field_is_series_template')->isEmpty()) {
      $dto->is_series_template = (bool) $node->get('field_is_series_template')->value;
    }
    if ($node->hasField('field_event_capacity') && !$node->get('field_event_capacity')->isEmpty()) {
      $dto->capacity = (int) $node->get('field_event_capacity')->value;
    }
    if ($this->capacityService instanceof EventCapacityServiceInterface) {
      try {
        if ($dto->capacity === NULL) {
          $dto->capacity = $this->capacityService->getCapacityTotal($node);
        }
        $dto->is_sold_out = $this->capacityService->isSoldOut($node);
      }
      catch (\Throwable $e) {
        $this->logger->error('Event capacity snapshot failed for nid @nid: @m', [
          '@nid' => (string) $dto->id,
          '@m' => $e->getMessage(),
        ]);
      }
    }
    if ($node->hasField('field_product_target')) {
      $dto->needs_ticket_product = $node->get('field_product_target')->isEmpty();
    }
    if ($node->hasField('field_event_category') && !$node->get('field_event_category')->isEmpty()) {
      $term = $node->get('field_event_category')->entity;
      $dto->category_label = $term ? $term->label() : NULL;
    }

    try {
      $dto->lifecycle_state = $this->eventStateResolver !== NULL
        ? $this->eventStateResolver->resolveState($node)
        : ($dto->published ? 'live' : 'draft');
    }
    catch (\Throwable $e) {
      $this->logger->error('Event lifecycle resolve failed for nid @nid: @m', [
        '@nid' => (string) $dto->id,
        '@m' => $e->getMessage(),
      ]);
      $dto->lifecycle_state = $dto->published ? 'live' : 'draft';
    }

    return $dto;
  }

  private function timestampFromFieldItem(mixed $item): int {
    if ($item === NULL) {
      return 0;
    }
    if (isset($item->date) && $item->date) {
      return (int) $item->date->getTimestamp();
    }
    if (!empty($item->value)) {
      $ts = strtotime((string) $item->value);
      return $ts !== FALSE ? (int) $ts : 0;
    }
    return 0;
  }

  private function buildVenueLabel(NodeInterface $node): string {
    if ($node->hasField('field_event_venue') && !$node->get('field_event_venue')->isEmpty()) {
      return (string) $node->get('field_event_venue')->value;
    }
    if ($node->hasField('field_location') && !$node->get('field_location')->isEmpty()) {
      return (string) $node->get('field_location')->value;
    }
    return '';
  }

  private function extractImageUrl(NodeInterface $node): ?string {
    if (!$node->hasField('field_event_image') || $node->get('field_event_image')->isEmpty()) {
      return NULL;
    }
    $image_item = $node->get('field_event_image')->first();
    if (!$image_item || !isset($image_item->entity) || !$image_item->entity) {
      return NULL;
    }
    $image_entity = $image_item->entity;
    if (method_exists($image_entity, 'createFileUrl')) {
      return $image_entity->createFileUrl();
    }
    if (method_exists($image_entity, 'hasField')
      && $image_entity->hasField('field_media_image')
      && !$image_entity->get('field_media_image')->isEmpty()) {
      $media_image = $image_entity->get('field_media_image')->entity;
      if ($media_image && method_exists($media_image, 'createFileUrl')) {
        return $media_image->createFileUrl();
      }
    }
    return NULL;
  }

}
