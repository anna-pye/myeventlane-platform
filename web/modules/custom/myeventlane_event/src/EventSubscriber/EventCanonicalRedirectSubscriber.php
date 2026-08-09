<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\EventSubscriber;

use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Utility\UnroutedUrlAssemblerInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects raw event node paths to their friendly aliases.
 */
final class EventCanonicalRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AliasManagerInterface $aliasManager,
    private readonly AccountInterface $currentUser,
    private readonly UnroutedUrlAssemblerInterface $unroutedUrlAssembler,
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
   * Redirects an accessible raw event node path to its current alias.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || $event->hasResponse()) {
      return;
    }

    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'entity.node.canonical') {
      return;
    }

    $node = $request->attributes->get('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return;
    }

    $internalPath = '/node/' . $node->id();
    if ($request->getPathInfo() !== $internalPath) {
      return;
    }

    if (!$node->access('view', $this->currentUser)) {
      return;
    }

    $alias = $this->aliasManager->getAliasByPath(
      $internalPath,
      $node->language()->getId(),
    );
    if ($alias === $internalPath || $alias === '') {
      return;
    }

    $destination = $this->unroutedUrlAssembler->assemble(
      'base:' . ltrim($alias, '/'),
      ['query' => $request->query->all()],
    );
    $event->setResponse(new LocalRedirectResponse($destination, 301));
  }

}
