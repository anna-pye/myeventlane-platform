<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for "My Tickets" self-service experience.
 */
final class MyTicketsController extends ControllerBase {

  /**
   * Order workflow states that represent a finished purchase for My Tickets.
   *
   * @var list<string>
   */
  private const COMPLETED_ORDER_STATES = [
    'placed',
    'completed',
    'fulfilled',
    'fulfillment',
  ];

  /**
   * Constructs MyTicketsController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketLabelResolver $ticketLabelResolver,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_core.ticket_label_resolver')
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
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   Page title (translatable).
   */
  public function orderDetailTitle(OrderInterface $commerce_order) {
    return $this->t('Booking @order_number', ['@order_number' => $commerce_order->getOrderNumber()]);
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

    // Load orders: owned by uid, or guest checkout (uid 0) with matching email.
    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
    $query = $orderStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('state', self::COMPLETED_ORDER_STATES, 'IN');

    $or = $query->orConditionGroup();
    $or->condition('uid', $currentUser->id());
    $userEmail = trim((string) $currentUser->getEmail());
    if ($userEmail !== '') {
      $guestGroup = $query->andConditionGroup();
      $guestGroup->condition('uid', 0);
      $guestGroup->condition('mail', $userEmail);
      $or->condition($guestGroup);
    }
    $query->condition($or);

    $orderIds = $query
      ->sort('placed', 'DESC')
      ->sort('order_id', 'DESC')
      ->execute();

    $orders = !empty($orderIds) ? $orderStorage->loadMultiple($orderIds) : [];

    // Group orders by upcoming vs past events.
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
      '#title' => $this->t('My tickets'),
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
    $orderData = $this->buildOrderData($commerce_order, TRUE);

    $cacheTags = ['commerce_order:' . $commerce_order->id()];
    foreach ($orderData['events'] ?? [] as $eventData) {
      $nid = (int) ($eventData['id'] ?? 0);
      if ($nid) {
        $cacheTags[] = 'node:' . $nid;
      }
    }

    return [
      '#theme' => 'myeventlane_order_detail',
      '#title' => $this->t('Booking @order_number', ['@order_number' => $commerce_order->getOrderNumber()]),
      '#order' => $orderData,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => $cacheTags,
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

      // Boost is an admin product; exclude from My Tickets.
      if ($bundle === 'boost') {
        continue;
      }

      // Same event resolution as TicketIssuer (variation/product field_event, then field_target_event).
      $event = $this->resolveEventFromOrderItem($item);

      // Build ticket item data. Use product variation label as ticket name.
      $ticketItem = [
        'title' => $this->ticketLabelResolver->getTicketLabel($item),
        'quantity' => (int) $item->getQuantity(),
        'price' => $item->getTotalPrice() ? $item->getTotalPrice()->getNumber() : 0.0,
        'attendees' => [],
      ];

      // Extract attendees if details requested.
      if ($includeDetails && $item->hasField('field_ticket_holder') && !$item->get('field_ticket_holder')->isEmpty()) {
        foreach ($item->get('field_ticket_holder')->referencedEntities() as $paragraph) {
          if (!$paragraph instanceof ParagraphInterface) {
            continue;
          }
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

    // If we have ticket lines but could not resolve any event (missing
    // field_target_event), do not bury the order under "past" only — show it
    // in the upcoming section so the buyer still sees the purchase.
    if (!$hasUpcomingEvents && $ticketItems !== [] && $events === []) {
      $hasUpcomingEvents = TRUE;
    }

    $stateId = $order->getState()->getId();
    $stateCustomer = in_array($stateId, ['completed', 'fulfillment', 'fulfilled', 'placed'], TRUE)
      ? $this->t('Confirmed')
      : $order->getState()->getLabel();

    return [
      'order' => $order,
      'order_id' => $order->id(),
      'order_number' => $order->getOrderNumber(),
      'order_url' => $order->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'placed_date' => $order->getPlacedTime() ? date('F j, Y g:i A', $order->getPlacedTime()) : NULL,
      'state' => $order->getState()->getLabel(),
      'state_customer_presentation' => $stateCustomer,
      'total_price' => $order->getTotalPrice() ? (float) $order->getTotalPrice()->getNumber() : 0.0,
      'events' => array_values($events),
      'ticket_items' => $ticketItems,
      'donation_total' => $donationTotal,
      'has_upcoming_events' => $hasUpcomingEvents,
    ];
  }

  /**
   * Resolves event node from order item.
   *
   * Mirrors \Drupal\myeventlane_tickets\Ticket\TicketIssuer::resolveEventFromOrderItem
   * (same precedence and instanceof-only checks) so My Tickets matches issuance.
   */
  private function resolveEventFromOrderItem(OrderItemInterface $order_item): ?NodeInterface {
    $purchased_entity = $order_item->getPurchasedEntity();
    if ($purchased_entity && $purchased_entity->hasField('field_event') && !$purchased_entity->get('field_event')->isEmpty()) {
      $node = $purchased_entity->get('field_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    $product = $purchased_entity !== NULL && method_exists($purchased_entity, 'getProduct')
      ? $purchased_entity->getProduct()
      : NULL;
    if ($product && $product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
      $node = $product->get('field_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    if ($order_item->hasField('field_target_event') && !$order_item->get('field_target_event')->isEmpty()) {
      $node = $order_item->get('field_target_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    return NULL;
  }

}
