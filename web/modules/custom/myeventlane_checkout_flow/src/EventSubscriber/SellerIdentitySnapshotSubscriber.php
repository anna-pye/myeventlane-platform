<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\myeventlane_checkout_flow\Service\SellerIdentityResolver;
use Drupal\myeventlane_checkout_flow\Service\PlatformFeeTaxSnapshotResolver;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Freezes the seller identity used by receipts and tax invoices.
 */
final class SellerIdentitySnapshotSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly SellerIdentityResolver $sellerIdentity,
    private readonly PlatformFeeTaxSnapshotResolver $platformFeeTax,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.pre_transition' => ['onPlacePreTransition', -50],
    ];
  }

  /**
   * Captures seller and MEL fee tax evidence before placement.
   */
  public function onPlacePreTransition(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($order instanceof OrderInterface) {
      $this->sellerIdentity->capture($order);
      $this->platformFeeTax->capture($order);
    }
  }

}
