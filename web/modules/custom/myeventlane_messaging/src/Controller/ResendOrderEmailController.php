<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_messaging\Access\ResendOrderConfirmationAccess;
use Drupal\myeventlane_messaging\Service\OrderConfirmationQueueBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Queues a duplicate order_confirmation (resend) for staff or event vendors.
 */
final class ResendOrderEmailController extends ControllerBase {

  public function __construct(
    private readonly OrderConfirmationQueueBuilder $orderConfirmationQueue,
    private readonly ResendOrderConfirmationAccess $resendAccess,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_messaging.order_confirmation_queue'),
      $container->get('myeventlane_messaging.resend_order_access'),
    );
  }

  /**
   * Queues order_confirmation with a fresh idempotency key.
   */
  public function resend(OrderInterface $commerce_order): RedirectResponse {
    $result = $this->resendAccess->check($commerce_order, $this->currentUser());
    if (!$result->isAllowed()) {
      throw new AccessDeniedHttpException();
    }

    $mail = $commerce_order->getEmail();
    if (!$mail) {
      $customer = $commerce_order->getCustomer();
      $mail = $customer ? $customer->getEmail() : NULL;
    }
    if (!$mail || trim($mail) === '') {
      $this->messenger()->addError($this->t('This order has no recipient email address.'));
      return $this->redirectToOrder($commerce_order);
    }

    $this->orderConfirmationQueue->queue($commerce_order, $mail, TRUE);
    $this->messenger()->addStatus($this->t('Order confirmation email has been queued. Run cron or process the messaging queue to send it.'));

    return $this->redirectToOrder($commerce_order);
  }

  private function redirectToOrder(OrderInterface $order): RedirectResponse {
    return $this->redirect('entity.commerce_order.canonical', [
      'commerce_order' => $order->id(),
    ]);
  }

}
