<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Blocks standard node add/edit routes for event bundle (administer nodes only).
 *
 * Organisers use Event Studio; the node form remains for site administrators only.
 */
final class EventNodeFormAccessSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 31]];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    if ($this->currentUser->hasPermission('administer nodes')) {
      return;
    }

    $request = $event->getRequest();
    $route = (string) ($request->attributes->get('_route') ?? '');

    if ($route === 'entity.node.add_form') {
      $node_type = $request->attributes->get('node_type');
      $bundle = is_object($node_type) ? $node_type->id() : (string) $node_type;
      if ($bundle === 'event') {
        throw new AccessDeniedHttpException('Event authoring uses Event Studio.');
      }
      return;
    }

    if ($route === 'entity.node.edit_form') {
      $node = $request->attributes->get('node');
      if (is_numeric($node)) {
        $node = $this->entityTypeManager->getStorage('node')->load((int) $node);
      }
      if ($node instanceof NodeInterface && $node->bundle() === 'event') {
        throw new AccessDeniedHttpException('Event editing uses Event Studio.');
      }
    }
  }

}
