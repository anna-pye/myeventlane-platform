<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber to auto-populate vendor and store fields on event nodes.
 *
 * On presave:
 * 1. If field_event_vendor is empty, resolve the vendor from the event owner.
 * 2. If field_event_vendor is set and field_event_store is empty, populate
 *    the store from the vendor's field_vendor_store.
 * 3. If the vendor changed, update the store to match.
 */
final class EventVendorSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger channel factory.
   */
  private LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Constructs an EventVendorSubscriber.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'entity.node.presave' => 'onNodePresave',
    ];
  }

  /**
   * Auto-populates vendor and store fields on event presave.
   *
   * @param mixed $event
   *   The entity presave event.
   */
  public function onNodePresave($event): void {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $event->getEntity();

    if ($node->bundle() !== 'event' || !$node->hasField('field_event_vendor') || !$node->hasField('field_event_store')) {
      return;
    }

    $logger = $this->loggerFactory->get('myeventlane_event');

    // Auto-assign vendor from event owner when field_event_vendor is empty.
    if ($node->get('field_event_vendor')->isEmpty()) {
      $owner_id = (int) $node->getOwnerId();
      if ($owner_id > 0) {
        $vendor = $this->resolveVendorForUser($owner_id);
        if ($vendor) {
          $node->set('field_event_vendor', $vendor);
          $logger->notice(
            'Event @nid: auto-assigned vendor @vid from owner UID @uid',
            ['@nid' => $node->id() ?? 'new', '@vid' => $vendor->id(), '@uid' => $owner_id]
          );
        }
      }
    }

    // If vendor is set and store is empty, auto-populate store from vendor.
    if (!$node->get('field_event_vendor')->isEmpty() && $node->get('field_event_store')->isEmpty()) {
      $vendor_id = $node->get('field_event_vendor')->target_id;
      if ($vendor_id) {
        $vendor_storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
        $vendor = $vendor_storage->load($vendor_id);

        if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
          $store = $vendor->get('field_vendor_store')->entity;
          if ($store) {
            $node->set('field_event_store', $store);
            $logger->notice(
              'Event @nid: auto-populated store @sid from vendor @vid',
              ['@nid' => $node->id() ?? 'new', '@sid' => $store->id(), '@vid' => $vendor_id]
            );
          }
        }
      }
    }

    // If vendor is changed, update store to match.
    if (!$node->get('field_event_vendor')->isEmpty() && !$node->isNew()) {
      $vendor_id = $node->get('field_event_vendor')->target_id;
      if ($vendor_id) {
        $vendor_storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
        $vendor = $vendor_storage->load($vendor_id);

        if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
          $store = $vendor->get('field_vendor_store')->entity;
          $current_store_id = $node->get('field_event_store')->target_id ?? NULL;

          if ($store && $current_store_id !== $store->id()) {
            $node->set('field_event_store', $store);
          }
        }
      }
    }
  }

  /**
   * Resolves the vendor entity for a given user ID.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The vendor entity, or NULL if not found.
   */
  private function resolveVendorForUser(int $uid): ?object {
    if (!$this->entityTypeManager->hasDefinition('myeventlane_vendor')) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    return $storage->load(reset($ids));
  }

}
