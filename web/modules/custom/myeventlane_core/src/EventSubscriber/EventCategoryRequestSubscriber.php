<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\myeventlane_core\Service\EventCategoryUrlService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves category slug route arguments and exposes the active term.
 */
final class EventCategoryRequestSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly EventCategoryUrlService $categoryUrl,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 33],
    ];
  }

  /**
   * Validates slug arguments and stores the resolved category term.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || PHP_SAPI === 'cli') {
      return;
    }

    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'view.upcoming_events.page_category') {
      return;
    }

    $argument = $request->attributes->get('arg_0');
    if ($argument === NULL || $argument === '') {
      throw new NotFoundHttpException();
    }

    $term = $this->categoryUrl->resolveTerm($argument);
    if ($term === NULL) {
      throw new NotFoundHttpException();
    }

    $request->attributes->set('mel_category_term', $term);
    $request->attributes->set('mel_active_category_tid', (string) $term->id());
  }

}
