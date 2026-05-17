<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_commerce\Service\OperationalOrderItemDisplayBuilder;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\UniversalTicketViewModelBuilder;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Builds My Tickets order view models from canonical ticket models.
 */
final class MyTicketsOrderViewModelBuilder {

  use StringTranslationTrait;

  /**
   * Order workflow states that represent a finished purchase for My Tickets.
   *
   * @var list<string>
   */
  public const COMPLETED_ORDER_STATES = [
    'placed',
    'completed',
    'fulfilled',
    'fulfillment',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UniversalTicketViewModelBuilder $ticketViewModelBuilder,
    private readonly TicketLabelResolver $ticketLabelResolver,
    TranslationInterface $string_translation,
    private readonly ?OperationalOrderItemDisplayBuilder $operationalOrderItemDisplayBuilder = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds My Tickets order view models in input order.
   *
   * @param array<int|string, \Drupal\commerce_order\Entity\OrderInterface> $orders
   *   Commerce orders keyed by order ID or arbitrary list indexes.
   *
   * @return list<array<string, mixed>>
   *   My Tickets order view models.
   */
  public function buildMultiple(array $orders, bool $includeDetails = FALSE): array {
    $ticketModelsByOrder = $this->loadTicketModelsByOrder($orders);
    $models = [];

    foreach ($orders as $order) {
      if (!$order instanceof OrderInterface) {
        continue;
      }
      $orderId = (int) $order->id();
      $models[] = $this->build($order, $includeDetails, $ticketModelsByOrder[$orderId] ?? []);
    }

    return $models;
  }

  /**
   * Builds one My Tickets order view model.
   *
   * @param list<array<string, mixed>>|null $ticketModels
   *   Optional preloaded canonical ticket models for the order.
   *
   * @return array<string, mixed>
   *   My Tickets order view model.
   */
  public function build(OrderInterface $order, bool $includeDetails = FALSE, ?array $ticketModels = NULL): array {
    $ticketModels ??= $this->loadTicketModelsByOrder([$order])[(int) $order->id()] ?? [];

    $events = [];
    $legacyTicketItems = [];
    $donationTotal = 0.0;
    $hasUpcomingEvents = FALSE;
    $now = time();

    foreach ($ticketModels as $ticketModel) {
      if (!is_array($ticketModel)) {
        continue;
      }
      $event = $this->eventFromTicketModel($ticketModel);
      if ($event !== NULL) {
        $events[$event['id']] = $event;
        if ((int) $event['start_timestamp'] > $now) {
          $hasUpcomingEvents = TRUE;
        }
      }
    }

    foreach ($order->getItems() as $item) {
      if (!$item instanceof OrderItemInterface) {
        continue;
      }

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

      if ($ticketModels === []) {
        $legacyTicketItems[] = $this->legacyTicketItem($item, $includeDetails);
      }

      if ($ticketModels !== []) {
        continue;
      }

      $event = $this->resolveEventFromOrderItem($item);
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        $eventId = (int) $event->id();
        if (!isset($events[$eventId])) {
          $eventData = $this->eventFromNode($event);
          $events[$eventId] = $eventData;
          if ((int) $eventData['start_timestamp'] > $now) {
            $hasUpcomingEvents = TRUE;
          }
        }
      }
    }

    if (!$hasUpcomingEvents && ($ticketModels !== [] || $legacyTicketItems !== []) && $events === []) {
      $hasUpcomingEvents = TRUE;
    }

    $stateId = $order->getState()->getId();
    $stateCustomer = in_array($stateId, self::COMPLETED_ORDER_STATES, TRUE)
      ? (string) $this->t('Confirmed')
      : (string) $order->getState()->getLabel();

    return [
      'order' => $order,
      'order_id' => $order->id(),
      'order_number' => $order->getOrderNumber(),
      'order_url' => $this->orderUrl($order),
      'placed_date' => $order->getPlacedTime() ? date('F j, Y g:i A', $order->getPlacedTime()) : NULL,
      'state' => $order->getState()->getLabel(),
      'state_customer_presentation' => $stateCustomer,
      'total_price' => $order->getTotalPrice() ? (float) $order->getTotalPrice()->getNumber() : 0.0,
      'events' => array_values($events),
      'ticket_models' => array_values($ticketModels),
      'ticket_items' => $legacyTicketItems,
      'donation_total' => $donationTotal,
      'has_upcoming_events' => $hasUpcomingEvents,
    ];
  }

  /**
   * Loads canonical ticket models for the supplied orders.
   *
   * @param array<int|string, \Drupal\commerce_order\Entity\OrderInterface> $orders
   *   Orders already scoped by My Tickets customer access.
   *
   * @return array<int, list<array<string, mixed>>>
   *   Canonical ticket models keyed by order ID.
   */
  private function loadTicketModelsByOrder(array $orders): array {
    $orderIds = [];
    foreach ($orders as $order) {
      if ($order instanceof OrderInterface && $order->id() !== NULL) {
        $orderIds[] = (int) $order->id();
      }
    }
    $orderIds = array_values(array_unique(array_filter($orderIds)));
    if ($orderIds === []) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ids = $storage->getQuery()
      ->condition('order_id', $orderIds, 'IN')
      // Orders are already customer-scoped before this query; ticket entity
      // access is admin-oriented and would hide valid customer entitlements.
      ->accessCheck(FALSE)
      ->sort('order_id', 'ASC')
      ->sort('order_item_id', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    if ($ids === []) {
      return [];
    }

    $models = [];
    foreach ($storage->loadMultiple($ids) as $ticket) {
      if (!$ticket instanceof Ticket || $ticket->get('order_id')->isEmpty()) {
        continue;
      }
      $orderId = (int) $ticket->get('order_id')->target_id;
      $models[$orderId][] = $this->ticketViewModelBuilder->build($ticket);
    }

    return $models;
  }

  /**
   * Builds event display data from a canonical ticket model.
   *
   * @return array<string, mixed>|null
   *   Event data, or NULL when no event is linked.
   */
  private function eventFromTicketModel(array $ticketModel): ?array {
    $event = $ticketModel['event'] ?? [];
    if (!is_array($event)) {
      return NULL;
    }

    $eventId = (int) ($event['id'] ?? 0);
    if ($eventId < 1) {
      return NULL;
    }

    $start = is_array($event['start'] ?? NULL) ? $event['start'] : [];
    $end = is_array($event['end'] ?? NULL) ? $event['end'] : [];
    $startTimestamp = (int) ($start['timestamp'] ?? 0);
    $endTimestamp = (int) ($end['timestamp'] ?? 0);

    return [
      'id' => $eventId,
      'title' => (string) ($event['label'] ?? ''),
      'url' => (string) ($event['url'] ?? ''),
      'ics_url' => $this->calendarUrl($eventId),
      'start_date' => $startTimestamp > 0 ? date('F j, Y', $startTimestamp) : NULL,
      'start_time' => $startTimestamp > 0 ? date('g:i A', $startTimestamp) : NULL,
      'start_timestamp' => $startTimestamp,
      'end_date' => $endTimestamp > 0 ? date('F j, Y', $endTimestamp) : NULL,
      'end_time' => $endTimestamp > 0 ? date('g:i A', $endTimestamp) : NULL,
      'location' => (string) ($event['location'] ?? ''),
    ];
  }

  /**
   * Builds event display data from a legacy order item event.
   *
   * @return array<string, mixed>
   *   Event data.
   */
  private function eventFromNode(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $startTime = $this->timestampFromField($event, 'field_event_start');
    $endTime = $this->timestampFromField($event, 'field_event_end');

    return [
      'id' => $eventId,
      'title' => $event->label(),
      'url' => $event->toUrl()->toString(),
      'ics_url' => $this->calendarUrl($eventId),
      'start_date' => $startTime > 0 ? date('F j, Y', $startTime) : NULL,
      'start_time' => $startTime > 0 ? date('g:i A', $startTime) : NULL,
      'start_timestamp' => $startTime,
      'end_date' => $endTime > 0 ? date('F j, Y', $endTime) : NULL,
      'end_time' => $endTime > 0 ? date('g:i A', $endTime) : NULL,
      'location' => $this->eventLocation($event),
    ];
  }

  /**
   * Builds a legacy order-item ticket row when no issued tickets exist yet.
   *
   * @return array<string, mixed>
   *   Legacy ticket line display data.
   */
  private function legacyTicketItem(OrderItemInterface $item, bool $includeDetails): array {
    $operational_display = $this->operationalOrderItemDisplayBuilder?->buildForOrderItem($item);
    $ticketItem = [
      'title' => is_array($operational_display)
        ? (string) ($operational_display['title'] ?? '')
        : $this->ticketLabelResolver->getTicketLabel($item),
      'quantity' => (int) $item->getQuantity(),
      'price' => $item->getTotalPrice() ? $item->getTotalPrice()->getNumber() : 0.0,
      'attendees' => [],
      'operational_addon_display' => $operational_display,
    ];

    if ($includeDetails && $item->hasField('field_ticket_holder') && !$item->get('field_ticket_holder')->isEmpty()) {
      foreach ($item->get('field_ticket_holder')->referencedEntities() as $paragraph) {
        if (!$paragraph instanceof ParagraphInterface) {
          continue;
        }
        $firstName = $paragraph->hasField('field_first_name') && !$paragraph->get('field_first_name')->isEmpty()
          ? $paragraph->get('field_first_name')->value : '';
        $lastName = $paragraph->hasField('field_last_name') && !$paragraph->get('field_last_name')->isEmpty()
          ? $paragraph->get('field_last_name')->value : '';
        $email = $paragraph->hasField('field_email') && !$paragraph->get('field_email')->isEmpty()
          ? $paragraph->get('field_email')->value : '';

        $ticketItem['attendees'][] = [
          'name' => trim($firstName . ' ' . $lastName),
          'email' => $email,
        ];
      }
    }

    return $ticketItem;
  }

  /**
   * Resolves event node from order item.
   *
   * Mirrors \Drupal\myeventlane_tickets\Ticket\TicketIssuer::resolveEventFromOrderItem
   * (same precedence and instanceof-only checks) so legacy My Tickets rows
   * continue to match issuance when issued ticket entities are absent.
   */
  private function resolveEventFromOrderItem(OrderItemInterface $orderItem): ?NodeInterface {
    $purchasedEntity = $orderItem->getPurchasedEntity();
    if ($purchasedEntity && $purchasedEntity->hasField('field_event') && !$purchasedEntity->get('field_event')->isEmpty()) {
      $node = $purchasedEntity->get('field_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    $product = $purchasedEntity !== NULL && method_exists($purchasedEntity, 'getProduct')
      ? $purchasedEntity->getProduct()
      : NULL;
    if ($product && $product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
      $node = $product->get('field_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    if ($orderItem->hasField('field_target_event') && !$orderItem->get('field_target_event')->isEmpty()) {
      $node = $orderItem->get('field_target_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    return NULL;
  }

  /**
   * Reads a timestamp from a datetime field.
   */
  private function timestampFromField(NodeInterface $event, string $fieldName): int {
    if (!$event->hasField($fieldName) || $event->get($fieldName)->isEmpty()) {
      return 0;
    }

    $timestamp = strtotime((string) $event->get($fieldName)->value);
    return $timestamp === FALSE ? 0 : $timestamp;
  }

  /**
   * Extracts the legacy event location string.
   */
  private function eventLocation(NodeInterface $event): ?string {
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      return (string) $event->get('field_location')->value;
    }
    return NULL;
  }

  /**
   * Builds the optional calendar download URL.
   */
  private function calendarUrl(int $eventId): string {
    try {
      return Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => $eventId])->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Builds the Commerce canonical order URL when the route is available.
   */
  private function orderUrl(OrderInterface $order): string {
    try {
      return $order->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

}
