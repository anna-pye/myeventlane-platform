<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\myeventlane_donations\Service\VendorMelPctContributionService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Accrues vendor percentage pledges when a ticket order is paid.
 *
 * Uses OrderEvents::ORDER_PAID (authoritative payment lifecycle). Idempotent
 * storage is handled in VendorMelPctContributionService.
 */
final class VendorMelPctContributionOrderPaidSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly VendorMelPctContributionService $vendorMelPctContributionService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      OrderEvents::ORDER_PAID => ['onOrderPaid', 10],
    ];
  }

  public function onOrderPaid(OrderEvent $event): void {
    $order = $event->getOrder();
    if (!$order instanceof OrderInterface) {
      return;
    }

    $this->vendorMelPctContributionService->upsertFromPaidOrder($order);
  }

}
