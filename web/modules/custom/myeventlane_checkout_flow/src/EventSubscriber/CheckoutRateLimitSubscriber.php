<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\myeventlane_api\Service\RateLimiterService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rate limits checkout requests to protect against traffic spikes.
 *
 * Applies RateLimiterService to commerce_checkout.form routes.
 * Identifier: checkout:{ip}. Limit from myeventlane_core.settings.
 */
final class CheckoutRateLimitSubscriber implements EventSubscriberInterface {

  /**
   * Constructs CheckoutRateLimitSubscriber.
   *
   * @param \Drupal\myeventlane_api\Service\RateLimiterService $rateLimiter
   *   The rate limiter service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly RateLimiterService $rateLimiter,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RouteMatchInterface $routeMatch,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Run after RouterListener (32) so route is available.
      KernelEvents::REQUEST => ['onRequest', 35],
    ];
  }

  /**
   * Handles the request event.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $route_name = $this->routeMatch->getRouteName();
    if ($route_name !== 'commerce_checkout.form') {
      return;
    }

    $config = $this->configFactory->get('myeventlane_core.settings');
    $limit = (int) $config->get('checkout_rate_limit_per_minute');
    if ($limit <= 0) {
      return;
    }

    $request = $event->getRequest();
    $ip = $this->rateLimiter->getClientIp($request);
    $identifier = 'checkout:' . $ip;

    $result = $this->rateLimiter->checkLimit(
      $request,
      $identifier,
      $limit,
      RateLimiterService::PERIOD_MINUTE
    );

    if (!$result['allowed']) {
      $this->logger->warning(
        'Checkout rate limit exceeded for IP @ip',
        ['@ip' => $ip]
      );

      $response = new Response(
        'Too many checkout attempts. Please try again in a moment.',
        429,
        [
          'Retry-After' => (string) max(1, $result['reset'] - time()),
          'X-RateLimit-Remaining' => (string) $result['remaining'],
          'X-RateLimit-Reset' => (string) $result['reset'],
        ]
      );
      $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
      $event->setResponse($response);
      $event->stopPropagation();
    }
  }

}
