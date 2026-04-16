<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\EventSubscriber;

use Drupal\myeventlane_auth\Service\AuthRedirectValidator;
use Drupal\myeventlane_core\Http\MelKernelAuthRouteSilencer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS for browser clients calling /auth/* across trusted origins.
 */
final class MelAuthCorsSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AuthRedirectValidator $redirectValidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 128],
      KernelEvents::RESPONSE => ['onResponse', -128],
    ];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
      return;
    }
    if (!self::isAuthApiPath($request)) {
      return;
    }
    if ($request->getMethod() !== Request::METHOD_OPTIONS) {
      return;
    }
    $origin = $request->headers->get('Origin');
    if (!$this->redirectValidator->isAllowedOrigin($origin)) {
      return;
    }
    $response = new Response('', Response::HTTP_NO_CONTENT);
    $response->headers->set('Access-Control-Allow-Origin', $origin);
    $response->headers->set('Access-Control-Allow-Credentials', 'true');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    $requestHeaders = $request->headers->get('Access-Control-Request-Headers', 'Authorization, Content-Type, X-CSRF-Token');
    $response->headers->set('Access-Control-Allow-Headers', $requestHeaders);
    $response->headers->set('Access-Control-Max-Age', '86400');
    $response->headers->set('Vary', 'Origin');
    $event->setResponse($response);
  }

  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if (!self::isAuthApiPath($request)) {
      return;
    }
    $origin = $request->headers->get('Origin');
    if (!$this->redirectValidator->isAllowedOrigin($origin)) {
      return;
    }
    $response = $event->getResponse();
    $response->headers->set('Access-Control-Allow-Origin', $origin);
    $response->headers->set('Access-Control-Allow-Credentials', 'true');
    $response->headers->set('Vary', 'Origin');
  }

  private static function isAuthApiPath(Request $request): bool {
    $path = $request->getPathInfo();
    return str_starts_with($path, '/auth/token')
      || str_starts_with($path, '/auth/refresh')
      || str_starts_with($path, '/auth/me')
      || str_starts_with($path, '/auth/revoke');
  }

}
