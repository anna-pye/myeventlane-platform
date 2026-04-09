<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
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
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly TimeInterface $time,
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
      $this->logger->info('Invoice email queued for paid order @order_id to @email', [
        '@order_id' => (string) $orderId,
        '@email' => $mail,
        'order_id' => $orderId,
        'message_type' => 'order_invoice',
      ]);
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

    $lineItems = $this->buildLineItems($order);
    $feeLines = $this->buildAdjustmentLines($order, 'fee', 'Fees');
    $taxLines = $this->buildAdjustmentLines($order, 'tax', 'Tax');

    $totalPrice = $order->getTotalPrice();
    $totalFormatted = $totalPrice ? $this->currencyFormatter->format($totalPrice) : '';

    $context = [
      'first_name' => $first_name,
      'order_number' => $order->label(),
      'order_id' => $orderId,
      'order_email' => $recipientEmail,
      'total_paid' => $totalFormatted,
      'invoice_date' => $this->dateFormatter->format($this->time->getCurrentTime(), 'long'),
      'line_items' => $lineItems,
      'fee_lines' => $feeLines,
      'tax_lines' => $taxLines,
      'events' => $this->formatEventsBrief($events),
      'event_name' => !empty($events) ? reset($events)->label() : 'your event',
      'order_total' => $totalFormatted,
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
   * @return array<int, array<string, mixed>>
   */
  private function buildLineItems(OrderInterface $order): array {
    $out = [];
    foreach ($order->getItems() as $item) {
      $total = $item->getTotalPrice();
      $unit = $item->getUnitPrice();
      $out[] = [
        'title' => $item->label(),
        'quantity' => (int) $item->getQuantity(),
        'unit_price' => $unit ? $this->currencyFormatter->format($unit) : '',
        'line_total' => $total ? $this->currencyFormatter->format($total) : '',
      ];
    }
    return $out;
  }

  /**
   * @return array<int, array<string, string>>
   */
  private function buildAdjustmentLines(OrderInterface $order, string $type, string $fallbackLabel): array {
    $out = [];
    foreach ($order->getAdjustments() as $adjustment) {
      if ($adjustment->getType() !== $type) {
        continue;
      }
      $amount = $adjustment->getAmount();
      if (!$amount || (float) $amount->getNumber() == 0.0) {
        continue;
      }
      $label = trim((string) $adjustment->getLabel());
      if ($label === '') {
        $label = $fallbackLabel;
      }
      $out[] = [
        'label' => $label,
        'amount' => $this->currencyFormatter->format($amount),
      ];
    }
    return $out;
  }

}
