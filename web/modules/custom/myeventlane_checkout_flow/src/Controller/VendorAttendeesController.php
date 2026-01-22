<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Controller;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for vendor attendee management.
 */
final class VendorAttendeesController extends ControllerBase {

  /**
   * Constructs VendorAttendeesController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * Access callback for vendor attendee pages.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function checkAccess(): AccessResult {
    $account = $this->currentUser;

    // Admin users always allowed.
    if ($account->hasPermission('administer commerce_order') || $account->hasPermission('bypass node access')) {
      return AccessResult::allowed()->addCacheContexts(['user.permissions']);
    }

    // Check if user is a vendor.
    if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      $store = $vendorResolver->getStoreForUser($account);
      if ($store) {
        return AccessResult::allowed()->addCacheContexts(['user']);
      }
    }

    return AccessResult::forbidden('Only vendors and administrators can access this page.')->addCacheContexts(['user']);
  }

  /**
   * Renders the vendor attendee dashboard.
   *
   * @return array
   *   A render array for the vendor attendee dashboard.
   */
  public function dashboard(): array {
    $account = $this->currentUser;
    $store = NULL;

    // Get vendor store.
    if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      $store = $vendorResolver->getStoreForUser($account);
    }

    if (!$store) {
      return [
        '#markup' => $this->t('No vendor account found. Please contact support.'),
        '#cache' => ['contexts' => ['user']],
      ];
    }

    // Get events owned by this vendor.
    $events = $this->getVendorEvents($store);
    $eventData = [];

    foreach ($events as $event) {
      $stats = $this->calculateEventStats($event);
      $eventData[] = [
        'event' => $event,
        'id' => $event->id(),
        'title' => $event->label(),
        'url' => $event->toUrl()->toString(),
        'start_date' => $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()
          ? date('F j, Y', strtotime($event->get('field_event_start')->value))
          : NULL,
        'tickets_sold' => $stats['tickets_sold'],
        'attendee_count' => $stats['attendee_count'],
        'revenue' => $stats['revenue'],
      ];
    }

    return [
      '#theme' => 'myeventlane_vendor_attendees_dashboard',
      '#title' => $this->t('Attendees & Sales'),
      '#events' => $eventData,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list:event'],
      ],
    ];
  }

  /**
   * Title callback for event attendees page.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return string
   *   Page title.
   */
  public function eventAttendeesTitle(NodeInterface $node): string {
    return (string) $this->t('Attendees for @event', ['@event' => $node->label()]);
  }

  /**
   * Renders the attendee list for a specific event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return array
   *   A render array for the attendee list.
   */
  public function eventAttendees(NodeInterface $node): array {
    // Verify vendor owns this event.
    $account = $this->currentUser;
    $store = NULL;

    if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      $store = $vendorResolver->getStoreForUser($account);
    }

    if ($store && \Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      if (!$vendorResolver->vendorOwnsEvent($store, $node)) {
        return [
          '#markup' => $this->t('Access denied. You do not own this event.'),
          '#cache' => ['contexts' => ['user']],
        ];
      }
    }

    // Load attendees for this event.
    $attendees = $this->getEventAttendees($node);

    // Calculate stats.
    $stats = $this->calculateEventStats($node);

    return [
      '#theme' => 'myeventlane_vendor_event_attendees',
      '#title' => $this->t('Attendees for @event', ['@event' => $node->label()]),
      '#event' => $node,
      '#attendees' => $attendees,
      '#stats' => $stats,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node:' . $node->id()],
      ],
    ];
  }

  /**
   * Gets events owned by a vendor store.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The vendor store.
   *
   * @return array<\Drupal\node\NodeInterface>
   *   Array of event nodes.
   */
  private function getVendorEvents(StoreInterface $store): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $events = [];

    // Query events by vendor.
    // Method 1: Check field_event_vendor -> vendor entity -> field_vendor_store.
    if (\Drupal::moduleHandler()->moduleExists('myeventlane_vendor')) {
      $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
      $vendorIds = $vendorStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_vendor_store', $store->id())
        ->execute();

      if (!empty($vendorIds)) {
        $vendorIds = array_values($vendorIds);
        $eventIds = $nodeStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'event')
          ->condition('field_event_vendor', $vendorIds, 'IN')
          ->condition('status', 1)
          ->sort('field_event_start', 'DESC')
          ->execute();

        if (!empty($eventIds)) {
          $events = $nodeStorage->loadMultiple($eventIds);
        }
      }
    }

    // Method 2: Fallback - check event owner matches store owner.
    if (empty($events)) {
      $eventIds = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $store->getOwnerId())
        ->condition('status', 1)
        ->sort('field_event_start', 'DESC')
        ->execute();

      if (!empty($eventIds)) {
        $events = $nodeStorage->loadMultiple($eventIds);
      }
    }

    return $events;
  }

  /**
   * Gets attendees for an event.
   *
   * Uses entity access to filter paragraphs - only paragraphs the vendor
   * can view will be included.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of attendee data.
   */
  private function getEventAttendees(NodeInterface $event): array {
    $attendees = [];
    $eventId = (int) $event->id();

    // Load order items for this event.
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderItemIds = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_target_event', $eventId)
      ->execute();

    if (empty($orderItemIds)) {
      return $attendees;
    }

    $orderItems = $orderItemStorage->loadMultiple($orderItemIds);
    $accessHandler = $this->entityTypeManager->getAccessControlHandler('paragraph');

    foreach ($orderItems as $orderItem) {
      if (!$orderItem instanceof OrderItemInterface) {
        continue;
      }

      // Get order.
      try {
        $order = $orderItem->getOrder();
        if (!$order || $order->getState()->getId() !== 'completed') {
          continue;
        }
      }
      catch (\Exception $e) {
        continue;
      }

      // Get ticket type.
      $ticketType = $orderItem->getTitle();

      // Get attendees from paragraphs.
      if ($orderItem->hasField('field_ticket_holder') && !$orderItem->get('field_ticket_holder')->isEmpty()) {
        foreach ($orderItem->get('field_ticket_holder')->referencedEntities() as $paragraph) {
          if (!$paragraph instanceof ParagraphInterface) {
            continue;
          }

          // Check entity access - this enforces vendor access rules from Phase 4.
          $access = $accessHandler->access($paragraph, 'view', $this->currentUser);
          if (!$access) {
            continue;
          }

          $first_name = $paragraph->hasField('field_first_name') && !$paragraph->get('field_first_name')->isEmpty()
            ? $paragraph->get('field_first_name')->value : '';
          $last_name = $paragraph->hasField('field_last_name') && !$paragraph->get('field_last_name')->isEmpty()
            ? $paragraph->get('field_last_name')->value : '';
          $email = $paragraph->hasField('field_email') && !$paragraph->get('field_email')->isEmpty()
            ? $paragraph->get('field_email')->value : '';

          $attendees[] = [
            'name' => trim($first_name . ' ' . $last_name),
            'email' => $email,
            'ticket_type' => $ticketType,
            'order_number' => $order->getOrderNumber(),
            'order_id' => $order->id(),
          ];
        }
      }
    }

    return $attendees;
  }

  /**
   * Calculates event statistics.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array with 'tickets_sold', 'attendee_count', 'revenue'.
   */
  private function calculateEventStats(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $ticketsSold = 0;
    $attendeeCount = 0;
    $revenue = 0.0;

    // Load order items for this event.
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderItemIds = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_target_event', $eventId)
      ->execute();

    if (empty($orderItemIds)) {
      return [
        'tickets_sold' => 0,
        'attendee_count' => 0,
        'revenue' => 0.0,
      ];
    }

    $orderItems = $orderItemStorage->loadMultiple($orderItemIds);
    $accessHandler = $this->entityTypeManager->getAccessControlHandler('paragraph');

    foreach ($orderItems as $orderItem) {
      if (!$orderItem instanceof OrderItemInterface) {
        continue;
      }

      // Skip donation items.
      $bundle = $orderItem->bundle();
      if (in_array($bundle, ['checkout_donation', 'platform_donation', 'rsvp_donation'], TRUE)) {
        continue;
      }

      // Get order.
      try {
        $order = $orderItem->getOrder();
        if (!$order || $order->getState()->getId() !== 'completed') {
          continue;
        }
      }
      catch (\Exception $e) {
        continue;
      }

      // Count tickets.
      $quantity = (int) $orderItem->getQuantity();
      $ticketsSold += $quantity;

      // Count attendees (only those vendor can access).
      if ($orderItem->hasField('field_ticket_holder') && !$orderItem->get('field_ticket_holder')->isEmpty()) {
        foreach ($orderItem->get('field_ticket_holder')->referencedEntities() as $paragraph) {
          if (!$paragraph instanceof ParagraphInterface) {
            continue;
          }

          // Check entity access.
          $access = $accessHandler->access($paragraph, 'view', $this->currentUser);
          if ($access) {
            $attendeeCount++;
          }
        }
      }

      // Calculate revenue (ticket items only, excludes donations).
      $price = $orderItem->getTotalPrice();
      if ($price) {
        $revenue += (float) $price->getNumber();
      }
    }

    return [
      'tickets_sold' => $ticketsSold,
      'attendee_count' => $attendeeCount,
      'revenue' => $revenue,
    ];
  }

}
