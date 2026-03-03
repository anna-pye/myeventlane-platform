<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\myeventlane_core\Security\SensitiveDataScrubber;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Blocks requests that contain raw credit card field names.
 *
 * Intercepts POST/PATCH/PUT requests to payment-related routes and
 * rejects them if the request body contains keys that look like raw
 * card data (PAN, CVC, expiry). This is a fail-closed defence: even
 * if upstream code accidentally introduces a form that collects raw
 * card fields, this guard prevents the data from reaching any
 * processing logic.
 *
 * The guard intentionally does NOT log the raw payload to avoid
 * persisting card data in Drupal's dblog.
 */
final class RawCardDataRequestGuard implements EventSubscriberInterface {

  /**
   * Route name prefixes that handle payment submissions.
   */
  private const GUARDED_ROUTE_PREFIXES = [
    'commerce_checkout.',
    'commerce_payment.',
    'entity.commerce_payment_method.',
  ];

  /**
   * Constructs a RawCardDataRequestGuard.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run very early, before any form processing.
    return [
      KernelEvents::REQUEST => ['onRequest', 500],
    ];
  }

  /**
   * Inspects incoming requests for raw card data fields.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();

    // Only inspect mutation requests.
    if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], TRUE)) {
      return;
    }

    // Only inspect payment-related routes.
    $routeName = (string) $request->attributes->get('_route', '');
    if (!$this->isGuardedRoute($routeName)) {
      return;
    }

    // Check POST body for sensitive keys.
    $postData = $request->request->all();
    if (!empty($postData) && SensitiveDataScrubber::containsSensitiveKeys($postData)) {
      $this->logger->critical('Blocked request containing raw card data fields on route @route. No payload logged.', [
        '@route' => $routeName,
      ]);
      throw new BadRequestHttpException('Raw card data must not be submitted to the server. Use Stripe.js to tokenise card details client-side.');
    }
  }

  /**
   * Checks if a route name matches a guarded prefix.
   *
   * @param string $routeName
   *   The route name.
   *
   * @return bool
   *   TRUE if the route is guarded.
   */
  private function isGuardedRoute(string $routeName): bool {
    if ($routeName === '') {
      return FALSE;
    }

    foreach (self::GUARDED_ROUTE_PREFIXES as $prefix) {
      if (str_starts_with($routeName, $prefix)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
