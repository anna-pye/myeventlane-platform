<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_checkout_flow\Service\TaxInvoicePresentationBuilder;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Queues a tax invoice email after payment is confirmed.
 *
 * Does not send mail directly; uses MessagingManager only. Runs after ticket
 * issuance (lower priority than default ORDER_PAID handlers).
 */
final class OrderPaidInvoiceSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly MessagingManager $messagingManager,
    private readonly LoggerInterface $logger,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TaxInvoicePresentationBuilder $taxInvoicePresentation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      OrderEvents::ORDER_PAID => ['onOrderPaid', -5],
    ];
  }

  /**
   * Queues the order_invoice template when an order is paid and total &gt; 0.
   */
  public function onOrderPaid(OrderEvent $event): void {
    $order = $event->getOrder();
    if (!$order instanceof OrderInterface) {
      return;
    }

    $total = $order->getTotalPrice();
    if (!$total || (float) $total->getNumber() <= 0.0) {
      return;
    }

    $orderId = (int) $order->id();

    $mail = $order->getEmail();
    if (!$mail) {
      $customer = $order->getCustomer();
      $mail = $customer ? $customer->getEmail() : NULL;
    }

    if (!$mail) {
      $this->logger->warning('ORDER_PAID: no customer email for invoice email; skipping. order_id=@id', [
        '@id' => (string) $orderId,
        'order_id' => $orderId,
      ]);
      return;
    }

    $context = $this->buildInvoiceContext($order, $mail);

    try {
      $this->messagingManager->queue('order_invoice', $mail, $context, [
        'langcode' => $order->language()->getId(),
      ]);
      $ticketCount = $this->countTicketsForOrder($orderId);
      $expectsTickets = $this->orderHasVariationLineItemsEligibleForTickets($order);
      $this->logger->info('Invoice email queued for paid order @order_id to @email (myeventlane_ticket count=@ticket_count)', [
        '@order_id' => (string) $orderId,
        '@email' => $mail,
        '@ticket_count' => (string) $ticketCount,
        'order_id' => $orderId,
        'message_type' => 'order_invoice',
        'ticket_count' => $ticketCount,
      ]);
      if ($expectsTickets && $ticketCount < 1) {
        $this->logger->warning('ORDER_PAID: invoice queued but no myeventlane_ticket rows for order @order_id (expected ticket line items present).', [
          '@order_id' => (string) $orderId,
          'order_id' => $orderId,
        ]);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to queue invoice email for order @order_id: @message', [
        '@order_id' => (string) $orderId,
        '@message' => $e->getMessage(),
        'order_id' => $orderId,
      ]);
    }
  }

  /**
   * Builds serializable template context for the invoice email.
   *
   * @return array<string, mixed>
   */
  private function buildInvoiceContext(OrderInterface $order, string $recipientEmail): array {
    $orderId = (int) $order->id();
    $customer = $order->getCustomer();
    $first_name = $customer ? $customer->getDisplayName() : 'there';

    $store = $order->getStore();
    $storeId = $store ? (int) $store->id() : NULL;

    $events = $this->extractEventNodes($order);
    $primaryEventId = !empty($events) ? (int) reset($events)->id() : NULL;

    $invoice = $this->taxInvoicePresentation->build($order);

    $placed = $order->getPlacedTime();
    $invoice_timestamp = $placed ?: $this->time->getRequestTime();

    $line_items = $invoice['invoice_lines'];

    $context = [
      'first_name' => $first_name,
      'order_number' => $order->label(),
      'order_id' => $orderId,
      'order_email' => $recipientEmail,
      'total_paid' => $invoice['order_total'],
      'order_total' => $invoice['order_total'],
      'order_total_gst' => $invoice['order_total_gst'],
      'vendor_name' => $invoice['vendor_name'],
      'vendor_abn' => $invoice['vendor_abn'],
      'invoice_date' => $this->dateFormatter->format((int) $invoice_timestamp, 'long'),
      'invoice_date_short' => $invoice['invoice_date_display'],
      'line_items' => $line_items,
      'invoice_lines' => $line_items,
      'fee_lines' => $invoice['fee_lines'],
      'invoice_fee_lines' => $invoice['fee_lines'],
      'tax_lines' => $invoice['tax_lines'],
      'invoice_tax_lines' => $invoice['tax_lines'],
      'invoice_lines_include_gst_column' => $invoice['invoice_lines_include_gst_column'] ?? FALSE,
      'events' => $this->formatEventsBrief($events),
      'event_name' => !empty($events) ? reset($events)->label() : 'your event',
    ];

    if ($primaryEventId !== NULL) {
      $context['event_id'] = $primaryEventId;
    }
    if ($storeId !== NULL) {
      $context['store_id'] = $storeId;
    }

    return $context;
  }

  /**
   * @return array<\Drupal\node\NodeInterface>
   */
  private function extractEventNodes(OrderInterface $order): array {
    $events = [];
    $eventIds = [];

    foreach ($order->getItems() as $item) {
      if ($item->bundle() === 'boost') {
        continue;
      }
      if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        $event = $item->get('field_target_event')->entity;
        if ($event instanceof NodeInterface && $event->bundle() === 'event') {
          $eventId = (int) $event->id();
          if (!in_array($eventId, $eventIds, TRUE)) {
            $events[] = $event;
            $eventIds[] = $eventId;
          }
        }
      }
    }

    return $events;
  }

  /**
   * @param array<\Drupal\node\NodeInterface> $events
   *
   * @return array<int, array<string, string>>
   */
  private function formatEventsBrief(array $events): array {
    $out = [];
    foreach ($events as $event) {
      $out[] = ['title' => $event->label()];
    }
    return $out;
  }

  /**
   * Counts issued ticket entities for this order (after TicketIssuer runs).
   */
  private function countTicketsForOrder(int $orderId): int {
    if ($orderId < 1 || !$this->entityTypeManager->hasDefinition('myeventlane_ticket')) {
      return 0;
    }
    $query = $this->entityTypeManager->getStorage('myeventlane_ticket')->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $orderId);
    return (int) $query->count()->execute();
  }

  /**
   * True when the order has commerce line items that TicketIssuer attempts.
   *
   * Mirrors TicketIssuer: variation purchase, skips boost bundle.
   */
  private function orderHasVariationLineItemsEligibleForTickets(OrderInterface $order): bool {
    foreach ($order->getItems() as $item) {
      if ($item->bundle() === 'boost') {
        continue;
      }
      $purchased = $item->getPurchasedEntity();
      if ($purchased && $purchased->bundle() === 'mel_pro_subscription_variation') {
        continue;
      }
      if ($purchased && $purchased->getEntityTypeId() === 'commerce_product_variation') {
        return TRUE;
      }
    }
    return FALSE;
  }

}
