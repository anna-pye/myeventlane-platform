<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_tickets\Service\EventAccess;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides manifest and service worker for scanner offline shell.
 */
final class TicketCheckinPwaController extends ControllerBase {

  public function __construct(
    private readonly EventAccess $eventAccess,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_tickets.event_access'),
    );
  }

  /**
   * Scanner web app manifest.
   */
  public function manifest(NodeInterface $event): JsonResponse {
    $this->assertEventAccess($event);
    $event_id = (int) $event->id();

    return new JsonResponse([
      'name' => 'MyEventLane Ticket Scanner',
      'short_name' => 'MEL Scanner',
      'display' => 'standalone',
      'start_url' => Url::fromRoute('myeventlane_tickets.ticket_scan', ['event' => $event_id])->toString(),
      'scope' => '/event/' . $event_id . '/tickets/',
      'background_color' => '#0a0a0a',
      'theme_color' => '#0b5fff',
      'icons' => [],
    ]);
  }

  /**
   * Dynamic service worker for event-specific scanner shell cache.
   */
  public function serviceWorker(NodeInterface $event): Response {
    $this->assertEventAccess($event);
    $event_id = (int) $event->id();
    $cache_name = 'mel-checkin-scanner-v1-event-' . $event_id;

    $scan_url = Url::fromRoute('myeventlane_tickets.ticket_scan', ['event' => $event_id])->toString();
    $manifest_url = Url::fromRoute('myeventlane_tickets.ticket_checkin_manifest', ['event' => $event_id])->toString();
    $assets = [
      '/modules/custom/myeventlane_tickets/js/checkin-scanner.js',
      '/modules/custom/myeventlane_tickets/js/vendor/html5-qrcode.min.js',
      '/modules/custom/myeventlane_tickets/css/checkin-scanner.css',
      $scan_url,
      $manifest_url,
    ];
    $asset_json = json_encode($assets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $script = <<<JS
const CACHE_NAME = '{$cache_name}';
const URLS_TO_CACHE = {$asset_json};

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(URLS_TO_CACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.map((key) => key !== CACHE_NAME ? caches.delete(key) : null))).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') {
    return;
  }
  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached) {
        return cached;
      }
      return fetch(event.request).then((response) => {
        if (!response || response.status !== 200 || response.type !== 'basic') {
          return response;
        }
        const responseToCache = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, responseToCache));
        return response;
      });
    })
  );
});
JS;

    $response = new Response($script, 200, [
      'Content-Type' => 'application/javascript; charset=UTF-8',
      'Cache-Control' => 'no-cache, no-store, must-revalidate',
      'Service-Worker-Allowed' => '/event/' . $event_id . '/tickets/',
    ]);
    return $response;
  }

  /**
   * Enforces event scope access.
   */
  private function assertEventAccess(NodeInterface $event): void {
    if ($event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    if (!$this->eventAccess->canManageEventTickets($event)) {
      throw new AccessDeniedHttpException();
    }
  }

}
