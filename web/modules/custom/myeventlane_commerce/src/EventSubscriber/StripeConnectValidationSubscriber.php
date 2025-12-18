<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\commerce_checkout\Event\CheckoutCompletionEvent;
use Drupal\commerce_checkout\Event\CheckoutEvents as CheckoutEventsEnum;
use Drupal\myeventlane_commerce\Service\StripeConnectPaymentService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates orders for Stripe Connect requirements.
 */
final class StripeConnectValidationSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a StripeConnectValidationSubscriber.
   *
   * @param \Drupal\myeventlane_commerce\Service\StripeConnectPaymentService $connectService
   *   The Stripe Connect payment service.
   */
  public function __construct(
    private readonly StripeConnectPaymentService $connectService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      CheckoutEventsEnum::COMPLETION => ['validateStripeConnect', 100],
    ];
  }

  /**
   * Validates that orders with paid tickets have Stripe Connect set up.
   *
   * @param \Drupal\commerce_checkout\Event\CheckoutCompletionEvent $event
   *   The checkout completion event.
   */
  public function validateStripeConnect(CheckoutCompletionEvent $event): void {
    $order = $event->getOrder();
    $validation = $this->connectService->validateOrderForConnect($order);

    if (!$validation['valid'] && !empty($validation['message'])) {
      // This validation should happen earlier in checkout, but as a safety
      // check, we log a warning here.
      \Drupal::logger('myeventlane_commerce')->warning(
        'Order @order_id completed but Stripe Connect validation failed: @message',
        [
          '@order_id' => $order->id(),
          '@message' => $validation['message'],
        ]
      );
    }
  }

}
