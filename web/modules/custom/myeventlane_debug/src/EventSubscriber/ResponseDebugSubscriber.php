<?php

declare(strict_types=1);

namespace Drupal\myeventlane_debug\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Temporary global trace: logs the final response status and Location (if any).
 */
final class ResponseDebugSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [
      // Late pass so the logged response matches what is actually sent.
      KernelEvents::RESPONSE => ['onResponse', -1000],
    ];
  }

  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $response = $event->getResponse();
    \Drupal::logger('mel_debug')->notice('FINAL RESPONSE: status=@status location=@location', [
      '@status' => (string) $response->getStatusCode(),
      '@location' => (string) $response->headers->get('Location', ''),
    ]);
  }

}
