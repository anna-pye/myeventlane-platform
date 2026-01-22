<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_state\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for event refunds.
 */
final class EventRefundsController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('renderer'),
    );
  }

  /**
   * Lists orders for refund requests.
   */
  public function list(NodeInterface $node): array {
    // Check ownership.
    if ((int) $node->getOwnerId() !== (int) $this->currentUser()->id()) {
      return ['#markup' => $this->t('Access denied.')];
    }

    // Get orders for this event.
    $orders = $this->getOrdersForEvent($node);

    $build = [
      '#type' => 'container',
    ];

    $build['header'] = [
      '#type' => 'markup',
      '#markup' => '<h2>' . $this->t('Refund Requests for @event', ['@event' => $node->label()]) . '</h2>',
    ];

    $build['request_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Request Refunds'),
      '#url' => Url::fromRoute('myeventlane_event_state.refund_request', ['node' => $node->id()]),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    if (empty($orders)) {
      $build['empty'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No paid orders found for this event.') . '</p>',
      ];
      return $build;
    }

    $build['orders'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Order ID'),
        $this->t('Customer'),
        $this->t('Amount'),
        $this->t('Tickets'),
        $this->t('Status'),
        $this->t('Actions'),
      ],
      '#rows' => [],
    ];

    foreach ($orders as $order) {
      // Create link markup.
      $link = [
        '#type' => 'link',
        '#title' => $order['actions_title'],
        '#url' => $order['actions_url'],
        '#attributes' => ['class' => ['button', 'button--small']],
      ];

      $build['orders']['#rows'][] = [
        $order['order_id'],
        $order['customer'],
        $order['amount'],
        $order['tickets'],
        $order['status'],
        ['data' => $link],
      ];
    }

    return $build;
  }

  /**
   * Gets orders for an event.
   */
  private function getOrdersForEvent(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $orders = [];

    try {
      $orderItemStorage = \Drupal::entityTypeManager()->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventId,
      ]);

      $processedOrders = [];
      foreach ($orderItems as $item) {
        // Safely load the order entity to avoid getOrder() warnings.
        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }
        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $this->entityTypeManager()
            ->getStorage('commerce_order')
            ->load($order_id);
          if (!$order || $order->getState()->getId() !== 'completed') {
            continue;
          }

          $orderId = $order->id();
          if (isset($processedOrders[$orderId])) {
            continue;
          }
          $processedOrders[$orderId] = TRUE;

          $customer = $order->getCustomer();
          $customerName = $customer ? $customer->getDisplayName() : $this->t('Guest');
          $totalPrice = $order->getTotalPrice();
          $amount = $totalPrice ? $totalPrice->getNumber() : '0.00';

          // @todo Check refund status from refund request table.
          $refundStatus = 'Not requested';

          $refundUrl = Url::fromRoute('myeventlane_event_state.refund_request', [
            'node' => $eventId,
            'order' => $orderId,
          ]);

          $orders[] = [
            'order_id' => $orderId,
            'customer' => $customerName,
            'amount' => '$' . number_format((float) $amount, 2),
            'tickets' => (int) $item->getQuantity(),
            'status' => $refundStatus,
            'actions_url' => $refundUrl,
            'actions_title' => $this->t('Request refund'),
          ];
        }
        catch (\Exception $e) {
          continue;
        }
      }
    }
    catch (\Exception $e) {
      // Error loading orders.
    }

    return $orders;
  }

}
