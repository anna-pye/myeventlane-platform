<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_refunds\Service\RefundAccessResolver;
use Drupal\myeventlane_refunds\Service\RefundOrderInspector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for vendor order listing.
 */
final class VendorOrdersController extends ControllerBase {

  /**
   * Constructs VendorOrdersController.
   *
   * @param \Drupal\myeventlane_refunds\Service\RefundAccessResolver $accessResolver
   *   The access resolver.
   * @param \Drupal\myeventlane_refunds\Service\RefundOrderInspector $orderInspector
   *   The order inspector.
   */
  public function __construct(
    private readonly RefundAccessResolver $accessResolver,
    private readonly RefundOrderInspector $orderInspector,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_refunds.access_resolver'),
      $container->get('myeventlane_refunds.order_inspector'),
    );
  }

  /**
   * Access callback for order listing.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access(NodeInterface $node): AccessResult {
    return $this->accessResolver->accessManageEvent($node, $this->currentUser());
  }

  /**
   * Lists orders for an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return array
   *   Render array.
   */
  public function list(NodeInterface $node): array {
    $eventId = (int) $node->id();
    $orders = $this->getOrdersForEvent($node);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-vendor-orders']],
    ];

    $build['header'] = [
      '#type' => 'markup',
      '#markup' => '<h1>' . $this->t('Orders for @event', ['@event' => $node->label()]) . '</h1>',
    ];

    if (empty($orders)) {
      $build['empty'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No paid orders found for this event.') . '</p>',
      ];
      return $build;
    }

    $build['orders'] = [
      '#theme' => 'myeventlane_refunds_order_list',
      '#orders' => $orders,
      '#event' => $node,
    ];

    return $build;
  }

  /**
   * Gets orders for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of order data.
   */
  private function getOrdersForEvent(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $orders = [];

    try {
      $orderItemStorage = $this->entityTypeManager()->getStorage('commerce_order_item');
      $orderItemIds = $orderItemStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_target_event', $eventId)
        ->execute();

      if (empty($orderItemIds)) {
        return $orders;
      }

      $orderItems = $orderItemStorage->loadMultiple($orderItemIds);
      $processedOrders = [];

      foreach ($orderItems as $item) {
        try {
          $order = $item->getOrder();
          if (!$order) {
            continue;
          }

          $orderState = $order->getState()->getId();
          if (!in_array($orderState, ['completed', 'placed', 'fulfilled'], TRUE)) {
            continue;
          }

          $orderId = $order->id();
          if (isset($processedOrders[$orderId])) {
            continue;
          }
          $processedOrders[$orderId] = TRUE;

          $customer = $order->getCustomer();
          $customerEmail = $order->getEmail() ?: ($customer ? $customer->getEmail() : '');
          $maskedEmail = $this->orderInspector->maskEmail($customerEmail);
          $customerName = $customer ? $customer->getDisplayName() : $this->t('Guest');

          $totalPrice = $order->getTotalPrice();
          $orderTotal = $totalPrice ? (float) $totalPrice->getNumber() : 0.0;

          // Calculate ticket subtotal for this event.
          $ticketSubtotalCents = $this->orderInspector->calculateTicketSubtotalCents($order, $eventId);
          $ticketSubtotal = $ticketSubtotalCents / 100;

          // Count ticket items for this event.
          $eventItems = $this->orderInspector->extractItemsForEvent($order, $eventId);
          $ticketQty = 0;
          foreach ($eventItems as $eventItem) {
            if ($this->orderInspector->isTicketItem($eventItem)) {
              $ticketQty += (int) $eventItem->getQuantity();
            }
          }

          $refundUrl = Url::fromRoute('myeventlane_refunds.vendor_refund', [
            'commerce_order' => $orderId,
          ], [
            'query' => ['event' => $eventId],
          ]);

          $orders[] = [
            'order_id' => $orderId,
            'order_number' => $order->getOrderNumber() ?: '#' . $orderId,
            'placed_date' => $order->getPlacedTime() ? \Drupal::service('date.formatter')->format($order->getPlacedTime(), 'short') : '',
            'customer_name' => $customerName,
            'customer_email' => $maskedEmail,
            'ticket_qty' => $ticketQty,
            'ticket_subtotal' => '$' . number_format($ticketSubtotal, 2),
            'order_total' => '$' . number_format($orderTotal, 2),
            'status' => $orderState,
            'refund_url' => $refundUrl,
          ];
        }
        catch (\Exception $e) {
          continue;
        }
      }
    }
    catch (\Exception $e) {
      $this->getLogger('myeventlane_refunds')->error('Failed to load orders for event @event_id: @message', [
        '@event_id' => $eventId,
        '@message' => $e->getMessage(),
      ]);
    }

    return $orders;
  }

}

