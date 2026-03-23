<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\myeventlane_donations\Service\VendorContributionInvoiceService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies platform_donation payments to MEL vendor contribution invoices.
 */
final class VendorMelInvoicePaymentSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly VendorContributionInvoiceService $vendorContributionInvoiceService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      OrderEvents::ORDER_PAID => ['onOrderPaid', 5],
    ];
  }

  public function onOrderPaid(OrderEvent $event): void {
    $order = $event->getOrder();
    if (!$order instanceof OrderInterface) {
      return;
    }
    if ($order->bundle() !== 'platform_donation') {
      return;
    }
    $this->vendorContributionInvoiceService->applyPaymentFromOrder($order);
  }

}
