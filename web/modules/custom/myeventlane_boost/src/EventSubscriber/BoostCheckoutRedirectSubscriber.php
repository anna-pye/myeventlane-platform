<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_boost\Service\BoostPurchaseSuccessResolver;
use Drupal\myeventlane_core\Http\MelKernelAuthRouteSilencer;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects boost-only checkout completion to the vendor Boost success page.
 *
 * Commerce checkout does not honour destination query params; this subscriber
 * mirrors the RSVP donation completion redirect pattern.
 */
final class BoostCheckoutRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BoostPurchaseSuccessResolver $purchaseSuccessResolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 29],
    ];
  }

  /**
   * Redirects boost-only complete step to the vendor success route.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
      return;
    }

    if ($this->routeMatch->getRouteName() !== 'commerce_checkout.form') {
      return;
    }

    if ($this->routeMatch->getParameter('step') !== 'complete') {
      return;
    }

    $order = $this->getOrder();
    if (!$order || $order->getState()->getId() === 'draft') {
      return;
    }

    if (!$this->purchaseSuccessResolver->isBoostOnlyOrder($order)) {
      return;
    }

    $purchase = $this->purchaseSuccessResolver->resolvePrimaryBoostPurchase($order);
    if ($purchase === NULL || $purchase['event_id'] <= 0) {
      $this->logger->warning('Boost-only order @order_id completed without a resolvable target event; keeping default checkout completion.', [
        '@order_id' => $order->id(),
      ]);
      return;
    }

    try {
      $url = Url::fromRoute('myeventlane_boost.vendor_event_boost_success', [
        'event' => $purchase['event_id'],
      ], [
        'query' => ['order' => $order->id()],
      ]);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Boost success redirect failed for order @order_id: @message', [
        '@order_id' => $order->id(),
        '@message' => $exception->getMessage(),
      ]);
      return;
    }

    $event->setResponse(new RedirectResponse($url->toString()));
  }

  private function getOrder(): ?OrderInterface {
    $param = $this->routeMatch->getParameter('commerce_order');
    if ($param instanceof OrderInterface) {
      return $param;
    }
    $order_id = (int) $param;
    if ($order_id <= 0) {
      return NULL;
    }
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
    return $order instanceof OrderInterface ? $order : NULL;
  }

}
