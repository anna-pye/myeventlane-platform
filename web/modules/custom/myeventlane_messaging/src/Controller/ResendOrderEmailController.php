<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_messaging\Service\OrderConfirmationQueueBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Queues a duplicate order_confirmation (resend) for staff or event vendors.
 */
final class ResendOrderEmailController extends ControllerBase {

  public function __construct(
    private readonly OrderConfirmationQueueBuilder $orderConfirmationQueue,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_messaging.order_confirmation_queue'),
    );
  }

  /**
   * Queues order_confirmation with a fresh idempotency key.
   */
  public function resend(OrderInterface $commerce_order): RedirectResponse {
    $mail = $commerce_order->getEmail();
    if (!$mail) {
      $customer = $commerce_order->getCustomer();
      $mail = $customer ? $customer->getEmail() : NULL;
    }
    if (!$mail || trim($mail) === '') {
      $this->messenger()->addError($this->t('This order has no recipient email address.'));
      return $this->redirectToOrder($commerce_order);
    }

    $messageId = $this->orderConfirmationQueue->queue($commerce_order, trim($mail), TRUE);
    if ($messageId) {
      $this->messenger()->addStatus($this->t('Confirmation email resent.'));
    }
    else {
      $this->messenger()->addError($this->t('The confirmation email could not be queued. Please try again or contact support if the problem continues.'));
    }

    return $this->redirectToOrder($commerce_order);
  }

  private function redirectToOrder(OrderInterface $order): RedirectResponse {
    $url = Url::fromRoute('entity.commerce_order.canonical', [
      'commerce_order' => $order->id(),
    ]);
    return new RedirectResponse($url->setAbsolute()->toString());
  }

}
