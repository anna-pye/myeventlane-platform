<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\EventSubscriber;

use Drupal\commerce_payment\Event\PaymentEvents;
use Drupal\commerce_payment\Event\RequirePaymentMethodEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Requires a reusable payment method for zero-balance MEL Pro trial orders.
 *
 * Commerce normally skips payment method collection when an order total is
 * zero. A Pro trial must still collect one so the first off-session renewal
 * can be charged. This is deliberately scoped to Pro inventory and does not
 * alter free ticket, RSVP, donation, or other zero-balance checkout flows.
 */
final class ProTrialPaymentMethodSubscriber implements EventSubscriberInterface {

  private const PRO_VARIATION_TYPE = 'mel_pro_subscription_variation';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PaymentEvents::REQUIRE_PAYMENT_METHOD => 'onRequirePaymentMethod',
    ];
  }

  /**
   * Requires card collection when an order contains MEL Pro inventory.
   */
  public function onRequirePaymentMethod(RequirePaymentMethodEvent $event): void {
    foreach ($event->getOrder()->getItems() as $item) {
      $purchasedEntity = $item->getPurchasedEntity();
      if ($purchasedEntity
        && $purchasedEntity->getEntityTypeId() === 'commerce_product_variation'
        && $purchasedEntity->bundle() === self::PRO_VARIATION_TYPE) {
        $event->setRequired(TRUE);
        return;
      }
    }
  }

}
