<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Controller;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_checkout_flow\Service\AttendeeEventStatsService;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for vendor Attendees & Sales dashboard.
 */
final class VendorAttendeesController extends ControllerBase {

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    private readonly TicketLabelResolver $ticketLabelResolver,
    private readonly ?AttendeeEventStatsService $attendeeStats = NULL,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('myeventlane_core.ticket_label_resolver'),
      $container->has('myeventlane_checkout_flow.attendee_event_stats')
        ? $container->get('myeventlane_checkout_flow.attendee_event_stats') : NULL
    );
  }

  public function checkAccess(): AccessResult {
    $account = $this->currentUser;

    if ($account->hasPermission('administer commerce_order') || $account->hasPermission('bypass node access')) {
      return AccessResult::allowed()->addCacheContexts(['user.permissions']);
    }

    if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      $store = $vendorResolver->getStoreForUser($account);
      if ($store) {
        return AccessResult::allowed()->addCacheContexts(['user']);
      }
    }

    return AccessResult::forbidden('Only vendors and administrators can access this page.')->addCacheContexts(['user']);
  }

  public function dashboard(): array {
    $account = $this->currentUser;
    $store = NULL;

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

    $events = $this->getVendorEvents($store);
    $eventData = [];
    $melCards = [];
    $melKpis = [
      'events' => 0,
      'tickets_sold' => 0,
      'revenue' => '0.00',
      'upcoming' => 0,
    ];

    if ($this->attendeeStats !== NULL && !empty($events)) {
      $stats = $this->attendeeStats->buildStatsForEvents(array_values($events));
      $melCards = $stats['cards'];
      $melKpis = $stats['kpis'];
    }

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
      '#mel_cards' => $melCards,
      '#mel_kpis' => $melKpis,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list:event'],
      ],
    ];
  }

  private function getVendorEvents(StoreInterface $store): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $events = [];

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

  private function calculateEventStats($event): array {
    $eventId = (int) $event->id();
    $ticketsSold = 0;
    $attendeeCount = 0;
    $revenue = 0.0;

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
      if (in_array($orderItem->bundle(), ['checkout_donation', 'platform_donation', 'rsvp_donation'], TRUE)) {
        continue;
      }
      if ($orderItem->bundle() === 'boost') {
        continue;
      }

      try {
        $order = $orderItem->getOrder();
        if (!$order || $order->getState()->getId() !== 'completed') {
          continue;
        }
      }
      catch (\Exception $e) {
        continue;
      }

      $quantity = (int) $orderItem->getQuantity();
      $ticketsSold += $quantity;

      if ($orderItem->hasField('field_ticket_holder') && !$orderItem->get('field_ticket_holder')->isEmpty()) {
        foreach ($orderItem->get('field_ticket_holder')->referencedEntities() as $paragraph) {
          if (!$paragraph instanceof ParagraphInterface) {
            continue;
          }
          $access = $accessHandler->access($paragraph, 'view', $this->currentUser);
          if ($access) {
            $attendeeCount++;
          }
        }
      }

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
