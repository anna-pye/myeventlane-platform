<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\myeventlane_core\Service\EventCategoryUrlService;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects legacy category URLs to /events/category/{slug}.
 */
final class EventCategoryCanonicalRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly EventCategoryUrlService $categoryUrl,
    private readonly ?AliasManagerInterface $aliasManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 28],
    ];
  }

  /**
   * Redirects taxonomy canonical and legacy tid category URLs.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || PHP_SAPI === 'cli') {
      return;
    }

    $request = $event->getRequest();
    $route = $request->attributes->get('_route');
    if (!is_string($route) || $route === '') {
      return;
    }

    if ($this->redirectLegacyFlatCategoryPath($event, $request->getPathInfo())) {
      return;
    }

    if ($route === 'entity.taxonomy_term.canonical') {
      $term = $request->attributes->get('taxonomy_term');
      if (!$term instanceof TermInterface || $term->bundle() !== 'categories') {
        return;
      }
      $this->setRedirect($event, $this->categoryUrl->getCategoryUrlString($term), 'taxonomy_term_canonical');
      return;
    }

    if ($route !== 'view.upcoming_events.page_category') {
      return;
    }

    $argument = $request->attributes->get('arg_0');
    if (!$this->categoryUrl->isLegacyTidArgument($argument)) {
      return;
    }

    $term = $this->categoryUrl->resolveTerm($argument);
    if (!$term instanceof TermInterface) {
      return;
    }

    $this->setRedirect($event, $this->categoryUrl->getCategoryUrlString($term), 'legacy_tid');
  }

  /**
   * Redirects /events/{slug} when it matches a category and has no entity alias.
   *
   * Avoids stealing /events/{title} paths that belong to event nodes.
   */
  private function redirectLegacyFlatCategoryPath(RequestEvent $event, string $pathInfo): bool {
    if ($this->aliasManager === NULL) {
      return FALSE;
    }

    if (!preg_match('#^/events/([^/]+)$#', $pathInfo, $matches)) {
      return FALSE;
    }

    $segment = strtolower($matches[1]);
    if ($this->categoryUrl->isReservedSlug($segment)) {
      return FALSE;
    }

    // Do not redirect when another entity owns this alias (e.g. event node).
    $internal = $this->aliasManager->getPathByAlias($pathInfo);
    if ($internal !== $pathInfo) {
      return FALSE;
    }

    $term = $this->categoryUrl->resolveTerm($segment);
    if ($term === NULL) {
      return FALSE;
    }

    $this->setRedirect($event, $this->categoryUrl->getCategoryUrlString($term), 'legacy_flat_events_slug');
    return TRUE;
  }

  /**
   * Issues a 301 redirect to the canonical category URL.
   */
  private function setRedirect(RequestEvent $event, string $destination, string $reason): void {
    $this->logger->info('Category canonical redirect (@reason) to @url', [
      '@reason' => $reason,
      '@url' => $destination,
    ]);
    $event->setResponse(new TrustedRedirectResponse($destination, 301));
  }

}
