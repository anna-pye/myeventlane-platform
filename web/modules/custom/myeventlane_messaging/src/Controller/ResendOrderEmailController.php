<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_messaging\Service\OrderConfirmationQueueBuilder;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Queues a duplicate order_confirmation (resend) for staff or event vendors.
 */
final class ResendOrderEmailController extends ControllerBase {

  public function __construct(
    private readonly OrderConfirmationQueueBuilder $orderConfirmationQueue,
    private readonly RequestStack $requestStack,
    private readonly AccessManagerInterface $accessManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_messaging.order_confirmation_queue'),
      $container->get('request_stack'),
      $container->get('access_manager'),
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
      return $this->redirectAfterResend($commerce_order);
    }

    $messageId = $this->orderConfirmationQueue->queue($commerce_order, trim($mail), TRUE);
    if ($messageId) {
      $this->messenger()->addStatus($this->t('Confirmation email resent.'));
    }
    else {
      $this->messenger()->addError($this->t('The confirmation email could not be queued. Please try again or contact support if the problem continues.'));
    }

    return $this->redirectAfterResend($commerce_order);
  }

  private function redirectAfterResend(OrderInterface $order): RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    $destination = $request?->query->get('destination');
    if (is_string($destination) && $destination !== '' && !UrlHelper::isExternal($destination)) {
      try {
        $url = Url::fromUserInput($destination);
        return new RedirectResponse($url->setAbsolute()->toString());
      }
      catch (\InvalidArgumentException $e) {
        $this->getLogger('myeventlane_messaging')->warning('Invalid destination query on resend confirmation: @msg', [
          '@msg' => $e->getMessage(),
        ]);
      }
    }

    if ($this->accessManager->checkNamedRoute('entity.commerce_order.canonical', ['commerce_order' => $order->id()], $this->currentUser())) {
      $url = Url::fromRoute('entity.commerce_order.canonical', [
        'commerce_order' => $order->id(),
      ]);
      return new RedirectResponse($url->setAbsolute()->toString());
    }

    $eventId = $this->getFirstEventIdFromOrder($order);
    if ($eventId !== NULL) {
      $url = Url::fromRoute('myeventlane_vendor.console.event_orders', [
        'event' => $eventId,
      ]);
      return new RedirectResponse($url->setAbsolute()->toString());
    }

    if ($this->currentUser()->hasPermission('access myeventlane platform control centre')) {
      $url = Url::fromRoute('myeventlane_admin_dashboard.orders');
      return new RedirectResponse($url->setAbsolute()->toString());
    }

    $this->getLogger('myeventlane_messaging')->warning('Resend confirmation: no safe redirect target for order @id.', [
      '@id' => (string) $order->id(),
    ]);

    return new RedirectResponse(Url::fromRoute('<front>')->setAbsolute()->toString());
  }

  /**
   * @return int|null
   *   First event node ID from ticket lines, or NULL if none.
   */
  private function getFirstEventIdFromOrder(OrderInterface $order): ?int {
    foreach ($order->getItems() as $item) {
      $bundle = $item->bundle();
      if (in_array($bundle, ['checkout_donation', 'platform_donation', 'rsvp_donation', 'boost'], TRUE)) {
        continue;
      }
      if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        $event = $item->get('field_target_event')->entity;
        if ($event instanceof NodeInterface && $event->bundle() === 'event') {
          return (int) $event->id();
        }
      }
    }

    return NULL;
  }

}
