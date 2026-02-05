<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the current vendor from various contexts.
 *
 * This service provides a centralized, cached mechanism to determine
 * the relevant Vendor entity from user accounts, events, orders, or
 * explicit context arrays.
 */
final class CurrentVendorResolver implements CurrentVendorResolverInterface {

  /**
   * Static cache for user -> vendor lookups.
   *
   * @var array<int, \Drupal\myeventlane_vendor\Entity\Vendor|null>
   */
  private array $userVendorCache = [];

  /**
   * Static cache for event -> vendor lookups.
   *
   * @var array<int, \Drupal\myeventlane_vendor\Entity\Vendor|null>
   */
  private array $eventVendorCache = [];

  /**
   * Constructs a CurrentVendorResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolveFromUser(AccountInterface $account): ?Vendor {
    $uid = (int) $account->id();
    if ($uid === 0) {
      return NULL;
    }

    if (array_key_exists($uid, $this->userVendorCache)) {
      return $this->userVendorCache[$uid];
    }

    try {
      $vendors = $this->entityTypeManager
        ->getStorage('myeventlane_vendor')
        ->loadByProperties(['uid' => $uid]);

      $vendor = !empty($vendors) ? reset($vendors) : NULL;
      $this->userVendorCache[$uid] = $vendor instanceof Vendor ? $vendor : NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to resolve vendor for user @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
      $this->userVendorCache[$uid] = NULL;
    }

    return $this->userVendorCache[$uid];
  }

  /**
   * {@inheritdoc}
   */
  public function resolveFromCurrentUser(): ?Vendor {
    return $this->resolveFromUser($this->currentUser);
  }

  /**
   * {@inheritdoc}
   */
  public function resolveFromEvent(NodeInterface $event): ?Vendor {
    $eventId = (int) $event->id();

    if (array_key_exists($eventId, $this->eventVendorCache)) {
      return $this->eventVendorCache[$eventId];
    }

    $vendor = NULL;

    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendorRef = $event->get('field_event_vendor')->entity;
      if ($vendorRef instanceof Vendor) {
        $vendor = $vendorRef;
      }
    }

    $this->eventVendorCache[$eventId] = $vendor;
    return $vendor;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveFromEventId(int $eventId): ?Vendor {
    if ($eventId <= 0) {
      return NULL;
    }

    if (array_key_exists($eventId, $this->eventVendorCache)) {
      return $this->eventVendorCache[$eventId];
    }

    try {
      $event = $this->entityTypeManager->getStorage('node')->load($eventId);
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        return $this->resolveFromEvent($event);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to load event @id for vendor resolution: @message', [
        '@id' => $eventId,
        '@message' => $e->getMessage(),
      ]);
    }

    $this->eventVendorCache[$eventId] = NULL;
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveFromContext(array $context): ?Vendor {
    // 1. Explicit vendor_id.
    if (!empty($context['vendor_id']) && is_numeric($context['vendor_id'])) {
      $vendorId = (int) $context['vendor_id'];
      try {
        $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendorId);
        if ($vendor instanceof Vendor) {
          return $vendor;
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to load vendor @id: @message', [
          '@id' => $vendorId,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // 2. Explicit vendor entity.
    if (!empty($context['vendor']) && $context['vendor'] instanceof Vendor) {
      return $context['vendor'];
    }

    // 3. Event ID.
    if (!empty($context['event_id']) && is_numeric($context['event_id'])) {
      $vendor = $this->resolveFromEventId((int) $context['event_id']);
      if ($vendor) {
        return $vendor;
      }
    }

    // 4. Event entity.
    if (!empty($context['event']) && $context['event'] instanceof NodeInterface) {
      $vendor = $this->resolveFromEvent($context['event']);
      if ($vendor) {
        return $vendor;
      }
    }

    // 5. Order -> resolve via store or event reference.
    if (!empty($context['order']) && is_object($context['order'])) {
      $vendor = $this->resolveFromOrder($context['order']);
      if ($vendor) {
        return $vendor;
      }
    }

    return NULL;
  }

  /**
   * Resolves vendor from a commerce order.
   *
   * @param object $order
   *   The commerce order entity.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  private function resolveFromOrder(object $order): ?Vendor {
    // Try to get vendor from order's store.
    if (method_exists($order, 'getStore')) {
      $store = $order->getStore();
      if ($store && method_exists($store, 'hasField') && $store->hasField('field_vendor')) {
        $vendorRef = $store->get('field_vendor')->entity ?? NULL;
        if ($vendorRef instanceof Vendor) {
          return $vendorRef;
        }
      }
    }

    // Try to get event from order items and resolve vendor from event.
    if (method_exists($order, 'getItems')) {
      foreach ($order->getItems() as $item) {
        $purchasedEntity = $item->getPurchasedEntity();
        if ($purchasedEntity && method_exists($purchasedEntity, 'hasField')) {
          // Check for event reference on the purchased entity (ticket).
          if ($purchasedEntity->hasField('field_event') && !$purchasedEntity->get('field_event')->isEmpty()) {
            $event = $purchasedEntity->get('field_event')->entity;
            if ($event instanceof NodeInterface) {
              $vendor = $this->resolveFromEvent($event);
              if ($vendor) {
                return $vendor;
              }
            }
          }
        }
      }
    }

    return NULL;
  }

}
