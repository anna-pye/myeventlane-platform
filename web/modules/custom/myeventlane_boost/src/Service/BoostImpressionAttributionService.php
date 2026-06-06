<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\myeventlane_boost\Entity\BoostEntitlementInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds cache-safe Boost impression metadata and validates beacon payloads.
 */
final class BoostImpressionAttributionService {

  /**
   * Cache tag prefix for event-scoped Boost card renders.
   */
  public const EVENT_CACHE_TAG_PREFIX = 'myeventlane_boost_event:';

  /**
   * Dedupe window for repeated impression beacons from the same client.
   */
  private const IMPRESSION_DEDUPE_TTL = 86400;

  /**
   * Lock timeout while claiming an impression dedupe slot.
   */
  private const IMPRESSION_LOCK_TIMEOUT = 5.0;

  /**
   * Constructs a BoostImpressionAttributionService.
   */
  public function __construct(
    private readonly BoostEntitlementManager $entitlementManager,
    private readonly BoostPlacementResolver $placementResolver,
    private readonly BoostImpressionTracker $impressionTracker,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly LockBackendInterface $lock,
    private readonly RequestStack $requestStack,
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
   * Cache tags for event card renders that may include Boost impressions.
   *
   * @return list<string>
   *   Tags that must invalidate when Boost entitlements for the event change.
   */
  public function buildCardCacheTags(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      return [];
    }

    $eventId = (int) $event->id();
    $tags = [self::EVENT_CACHE_TAG_PREFIX . $eventId];

    $entitlement = $this->entitlementManager->getPrimaryActiveEntitlementForEvent($eventId);
    if ($entitlement instanceof BoostEntitlementInterface) {
      $tags[] = 'myeventlane_boost_entitlement:' . $entitlement->id();
    }

    return $tags;
  }

  /**
   * Validates a beacon payload and records the impression via the tracker.
   */
  public function recordValidatedImpression(int $orderItemId, int $eventId, string $placement, ?Request $request = NULL): bool {
    if ($orderItemId <= 0 || $eventId <= 0 || !$this->placementResolver->isValidPlacement($placement)) {
      $this->logger->warning('Rejected Boost impression beacon: invalid payload shape.', [
        'order_item_id' => $orderItemId,
        'event_id' => $eventId,
        'placement' => $placement,
      ]);
      return FALSE;
    }

    $request = $request ?? $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request || !$this->validatePostedPlacement($placement, $request)) {
      return FALSE;
    }

    if (!$this->validateActiveEntitlement($orderItemId, $eventId)) {
      return FALSE;
    }

    $lockName = $this->buildImpressionDedupeKey($orderItemId, $placement, $request);
    if (!$this->lock->acquire($lockName, self::IMPRESSION_LOCK_TIMEOUT)) {
      return TRUE;
    }

    try {
      if ($this->isDuplicateImpression($orderItemId, $placement, $request)) {
        return TRUE;
      }

      if (!$this->impressionTracker->recordImpression($orderItemId, $eventId, $placement)) {
        return FALSE;
      }

      $this->rememberImpression($orderItemId, $placement, $request);
      return TRUE;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Ensures the posted placement matches the page that sent the beacon.
   */
  private function validatePostedPlacement(string $postedPlacement, Request $request): bool {
    $referer = $request->headers->get('Referer');
    if (!is_string($referer) || $referer === '') {
      $this->logger->warning('Rejected Boost impression beacon: missing Referer header.');
      return FALSE;
    }

    $resolvedPlacement = $this->placementResolver->resolvePlacementFromReferer($referer);
    if ($resolvedPlacement === NULL) {
      $this->logger->warning('Rejected Boost impression beacon: could not resolve Referer placement.', [
        'referer' => $referer,
        'placement' => $postedPlacement,
      ]);
      return FALSE;
    }

    if ($resolvedPlacement !== $postedPlacement) {
      $this->logger->warning('Rejected Boost impression beacon: placement mismatch.', [
        'posted_placement' => $postedPlacement,
        'resolved_placement' => $resolvedPlacement,
        'referer' => $referer,
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Checks whether this client already recorded the impression recently.
   */
  private function isDuplicateImpression(int $orderItemId, string $placement, Request $request): bool {
    return (bool) $this->cache->get($this->buildImpressionCacheId($orderItemId, $placement, $request));
  }

  /**
   * Stores a short-lived dedupe marker after a successful impression record.
   */
  private function rememberImpression(int $orderItemId, string $placement, Request $request): void {
    $this->cache->set(
      $this->buildImpressionCacheId($orderItemId, $placement, $request),
      TRUE,
      time() + self::IMPRESSION_DEDUPE_TTL,
    );
  }

  private function buildImpressionDedupeKey(int $orderItemId, string $placement, Request $request): string {
    return 'myeventlane_boost:impression:' . $orderItemId . ':' . $placement . ':' . $this->buildClientFingerprint($request);
  }

  private function buildImpressionCacheId(int $orderItemId, string $placement, Request $request): string {
    return $this->buildImpressionDedupeKey($orderItemId, $placement, $request);
  }

  private function buildClientFingerprint(Request $request): string {
    if ($request->hasSession()) {
      $session = $request->getSession();
      if ($session->isStarted()) {
        return 'session:' . $session->getId();
      }
    }

    return 'anon:' . hash('sha256', ($request->getClientIp() ?? '') . '|' . ($request->headers->get('User-Agent') ?? ''));
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
