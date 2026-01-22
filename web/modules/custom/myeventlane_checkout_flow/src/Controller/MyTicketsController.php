<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for "My Tickets" self-service experience.
 */
final class MyTicketsController extends ControllerBase {

  /**
   * Constructs MyTicketsController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Access callback for My Tickets page.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function checkAccess() {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return AccessResult::forbidden()->addCacheContexts(['user']);
    }
    return AccessResult::allowed()->addCacheContexts(['user']);
  }

  /**
   * Title callback for order detail page.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The order.
   *
   * @return string
   *   Page title.
   */
  public function orderDetailTitle(OrderInterface $commerce_order): string {
    return $this->t('Order @order_number', ['@order_number' => $commerce_order->getOrderNumber()]);
  }

  /**
   * Renders the "My Tickets" overview page.
   *
   * @return array
   *   A render array for the My Tickets page.
   */
  public function overview(): array {
    $currentUser = $this->currentUser();

    // Anonymous users are redirected to login.
    if ($currentUser->isAnonymous()) {
      return [
        '#markup' => $this->t('Please <a href="@login">log in</a> to view your tickets.', [
          '@login' => Url::fromRoute('user.login')->toString(),
        ]),
        '#cache' => [
          'contexts' => ['user'],
        ],
      ];
    }

    // Load orders for current user.
    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
    $orderIds = $orderStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $currentUser->id())
      ->condition('state', ['placed', 'completed', 'fulfilled'], 'IN')
      ->sort('placed', 'DESC')
      ->execute();

    $orders = !empty($orderIds) ? $orderStorage->loadMultiple($orderIds) : [];

    // Group orders by upcoming vs past events.
    $now = time();
    $upcomingOrders = [];
    $pastOrders = [];

    foreach ($orders as $order) {
      $orderData = $this->buildOrderData($order);
      if ($orderData['has_upcoming_events']) {
        $upcomingOrders[] = $orderData;
      }
      else {
        $pastOrders[] = $orderData;
      }
    }

    return [
      '#theme' => 'myeventlane_my_tickets',
      '#title' => $this->t('My Tickets'),
      '#upcoming_orders' => $upcomingOrders,
      '#past_orders' => $pastOrders,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['commerce_order_list'],
      ],
    ];
  }

  /**
   * Renders a customer-facing order detail page.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The order entity.
   *
   * @return array
   *   A render array for the order detail page.
   */
  public function orderDetail(OrderInterface $commerce_order): array {
    // Access control: Commerce handles this via entity access.
    // If user doesn't have access, they'll get a 403.
    $orderData = $this->buildOrderData($commerce_order, TRUE);

    return [
      '#theme' => 'myeventlane_order_detail',
      '#title' => $this->t('Order @order_number', ['@order_number' => $commerce_order->getOrderNumber()]),
      '#order' => $orderData,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['commerce_order:' . $commerce_order->id()],
      ],
    ];
  }

  /**
   * Builds order data for display.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param bool $includeDetails
   *   Whether to include full details (for detail page).
   *
   * @return array
   *   Order data array.
   */
  private function buildOrderData(OrderInterface $order, bool $includeDetails = FALSE): array {
    $events = [];
    $ticketItems = [];
    $donationTotal = 0.0;
    $hasUpcomingEvents = FALSE;
    $now = time();

    foreach ($order->getItems() as $item) {
      // Check if donation.
      $bundle = $item->bundle();
      if (in_array($bundle, ['checkout_donation', 'platform_donation', 'rsvp_donation'], TRUE)) {
        $price = $item->getTotalPrice();
        if ($price) {
          $donationTotal += (float) $price->getNumber();
        }
        continue;
      }

      // Extract event from order item.
      $event = NULL;
      if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        $event = $item->get('field_target_event')->entity;
      }

      // Build ticket item data.
      $ticketItem = [
        'title' => $item->getTitle(),
        'quantity' => (int) $item->getQuantity(),
        'price' => $item->getTotalPrice() ? $item->getTotalPrice()->getNumber() : 0.0,
        'attendees' => [],
      ];

      // Extract attendees if details requested.
      if ($includeDetails && $item->hasField('field_ticket_holder') && !$item->get('field_ticket_holder')->isEmpty()) {
        foreach ($item->get('field_ticket_holder')->referencedEntities() as $paragraph) {
          $first_name = $paragraph->hasField('field_first_name') && !$paragraph->get('field_first_name')->isEmpty()
            ? $paragraph->get('field_first_name')->value : '';
          $last_name = $paragraph->hasField('field_last_name') && !$paragraph->get('field_last_name')->isEmpty()
            ? $paragraph->get('field_last_name')->value : '';
          $email = $paragraph->hasField('field_email') && !$paragraph->get('field_email')->isEmpty()
            ? $paragraph->get('field_email')->value : '';

          $ticketItem['attendees'][] = [
            'name' => trim($first_name . ' ' . $last_name),
            'email' => $email,
          ];
        }
      }

      $ticketItems[] = $ticketItem;

      // Track events.
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        $eventId = (int) $event->id();
        if (!isset($events[$eventId])) {
          $startTime = NULL;
          if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
            try {
              $startTime = strtotime($event->get('field_event_start')->value);
              if ($startTime && $startTime > $now) {
                $hasUpcomingEvents = TRUE;
              }
            }
            catch (\Exception $e) {
              // Ignore date parsing errors.
            }
          }

          $events[$eventId] = [
            'id' => $eventId,
            'title' => $event->label(),
            'url' => $event->toUrl()->toString(),
            'ics_url' => Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => $eventId])->toString(),
            'start_date' => $startTime ? date('F j, Y', $startTime) : NULL,
            'start_time' => $startTime ? date('g:i A', $startTime) : NULL,
            'start_timestamp' => $startTime ?: 0,
            'end_date' => NULL,
            'end_time' => NULL,
            'location' => NULL,
          ];

          if ($event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()) {
            try {
              $endTime = strtotime($event->get('field_event_end')->value);
              if ($endTime) {
                $events[$eventId]['end_date'] = date('F j, Y', $endTime);
                $events[$eventId]['end_time'] = date('g:i A', $endTime);
              }
            }
            catch (\Exception $e) {
              // Ignore date parsing errors.
            }
          }

          if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
            $events[$eventId]['location'] = $event->get('field_location')->value;
          }
        }
      }
    }

    return [
      'order' => $order,
      'order_id' => $order->id(),
      'order_number' => $order->getOrderNumber(),
      'order_url' => $order->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'placed_date' => $order->getPlacedTime() ? date('F j, Y g:i A', $order->getPlacedTime()) : NULL,
      'state' => $order->getState()->getLabel(),
      'total_price' => $order->getTotalPrice() ? (float) $order->getTotalPrice()->getNumber() : 0.0,
      'events' => array_values($events),
      'ticket_items' => $ticketItems,
      'donation_total' => $donationTotal,
      'has_upcoming_events' => $hasUpcomingEvents,
    ];
  }

}
