<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_boost\Entity\BoostEntitlementInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds cache-safe Boost impression metadata and validates beacon payloads.
 */
final class BoostImpressionAttributionService {

  /**
   * Constructs a BoostImpressionAttributionService.
   */
  public function __construct(
    private readonly BoostEntitlementManager $entitlementManager,
    private readonly BoostPlacementResolver $placementResolver,
    private readonly BoostImpressionTracker $impressionTracker,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds impression metadata for a boosted event card render.
   *
   * @return array{order_item_id: int, event_id: int, placement: string}|null
   *   Metadata for client-side beacons, or NULL when not attributable.
   */
  public function buildCardMetadata(NodeInterface $event): ?array {
    if ($event->bundle() !== 'event' || !$event->isPublished()) {
      return NULL;
    }

    $entitlement = $this->entitlementManager->getPrimaryActiveEntitlementForEvent((int) $event->id());
    if (!$entitlement instanceof BoostEntitlementInterface) {
      return NULL;
    }

    $orderItemId = (int) ($entitlement->get('order_item_id')->target_id ?? 0);
    if ($orderItemId <= 0) {
      return NULL;
    }

    $placement = $this->placementResolver->resolveCurrentPlacement();
    if (!$this->placementResolver->isValidPlacement($placement)) {
      return NULL;
    }

    return [
      'order_item_id' => $orderItemId,
      'event_id' => (int) $event->id(),
      'placement' => $placement,
    ];
  }

  /**
   * Builds cache metadata for event card Boost impression attachment.
   *
   * @return array{tags?: list<string>, max-age?: int}
   *   Cache tags and max-age for entitlement state transitions.
   */
  public function buildCardCacheMetadata(NodeInterface $event): array {
    if ($event->bundle() !== 'event' || !$event->isPublished()) {
      return [];
    }

    $event_id = (int) $event->id();
    $tags = [];
    foreach ($this->entitlementManager->getNonRevokedEntitlementsForEvent($event_id) as $entitlement) {
      $tags = Cache::mergeTags($tags, $entitlement->getCacheTags());
    }

    $metadata = [];
    if ($tags !== []) {
      $metadata['tags'] = $tags;
    }

    $max_age = $this->entitlementManager->getImpressionCacheMaxAgeForEvent($event_id);
    if ($max_age !== NULL) {
      $metadata['max-age'] = $max_age;
    }

    return $metadata;
  }

  /**
   * Validates a beacon payload and records the impression via the tracker.
   */
  public function recordValidatedImpression(int $orderItemId, int $eventId, string $placement): bool {
    if ($orderItemId <= 0 || $eventId <= 0 || !$this->placementResolver->isValidPlacement($placement)) {
      $this->logger->warning('Rejected Boost impression beacon: invalid payload shape.', [
        'order_item_id' => $orderItemId,
        'event_id' => $eventId,
        'placement' => $placement,
      ]);
      return FALSE;
    }

    if (!$this->validateActiveEntitlement($orderItemId, $eventId)) {
      return FALSE;
    }

    return $this->impressionTracker->recordImpression($orderItemId, $eventId, $placement);
  }

  /**
   * Ensures the order item maps to an active entitlement for the event.
   */
  private function validateActiveEntitlement(int $orderItemId, int $eventId): bool {
    $entitlement = $this->entitlementManager->getEntitlementByOrderItemId($orderItemId);
    if (!$entitlement instanceof BoostEntitlementInterface) {
      $this->logger->warning('Rejected Boost impression beacon: entitlement not found.', [
        'order_item_id' => $orderItemId,
        'event_id' => $eventId,
      ]);
      return FALSE;
    }

    $entitlementEventId = (int) ($entitlement->get('event')->target_id ?? 0);
    if ($entitlementEventId !== $eventId) {
      $this->logger->warning('Rejected Boost impression beacon: entitlement event mismatch.', [
        'order_item_id' => $orderItemId,
        'event_id' => $eventId,
        'entitlement_event_id' => $entitlementEventId,
      ]);
      return FALSE;
    }

    if ((string) $entitlement->get('status')->value !== BoostEntitlementInterface::STATUS_ACTIVE) {
      $this->logger->warning('Rejected Boost impression beacon: entitlement not active.', [
        'order_item_id' => $orderItemId,
        'event_id' => $eventId,
        'status' => (string) $entitlement->get('status')->value,
      ]);
      return FALSE;
    }

    if (!$this->entitlementManager->isEntitlementCurrentlyActive($entitlement)) {
      $this->logger->warning('Rejected Boost impression beacon: entitlement outside active window.', [
        'order_item_id' => $orderItemId,
        'event_id' => $eventId,
      ]);
      return FALSE;
    }

    $orderItem = $this->entityTypeManager->getStorage('commerce_order_item')->load($orderItemId);
    if (!$orderItem || $orderItem->bundle() !== 'boost') {
      $this->logger->warning('Rejected Boost impression beacon: invalid boost order item.', [
        'order_item_id' => $orderItemId,
        'event_id' => $eventId,
      ]);
      return FALSE;
    }

    if (!$orderItem->hasField('field_target_event')) {
      return FALSE;
    }

    $targetEventId = (int) ($orderItem->get('field_target_event')->target_id ?? 0);
    if ($targetEventId !== $eventId) {
      $this->logger->warning('Rejected Boost impression beacon: order item target mismatch.', [
        'order_item_id' => $orderItemId,
        'event_id' => $eventId,
        'target_event_id' => $targetEventId,
      ]);
      return FALSE;
    }

    return TRUE;
  }

}
