<?php

declare(strict_types=1);

namespace Drupal\myeventlane_debug\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Optional HTTP response trace for deep debugging only.
 *
 * Enable with: `drush state:set mel.debug_http_response_trace 1 --input-format=integer`
 * Disable with: `drush state:del mel.debug_http_response_trace`
 *
 * Logs at **debug** severity on channel `mel_debug` — not during normal browsing
 * when this state flag is off.
 */
final class ResponseDebugSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly StateInterface $state,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => ['onResponse', -1000],
    ];
  }

  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    if (!(bool) $this->state->get('mel.debug_http_response_trace', FALSE)) {
      return;
    }
    $response = $event->getResponse();
    $this->loggerFactory->get('mel_debug')->debug('FINAL RESPONSE: status=@status location=@location', [
      '@status' => (string) $response->getStatusCode(),
      '@location' => (string) $response->headers->get('Location', ''),
    ]);
  }

}
