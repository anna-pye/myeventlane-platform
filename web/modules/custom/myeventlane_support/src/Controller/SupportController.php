<?php

declare(strict_types=1);

namespace Drupal\myeventlane_support\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_support\Service\SupportSearchRequestGuard;
use Drupal\node\NodeInterface;
use Drupal\views\Views;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Legacy /support entry (redirects to Help Centre) and JSON search helper.
 */
final class SupportController extends ControllerBase {

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
    private readonly SupportSearchRequestGuard $searchRequestGuard,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('request_stack'),
      $container->get('logger.channel.myeventlane_support'),
      $container->get('myeventlane_support.search_request_guard'),
    );
  }

  /**
   * Redirects the legacy public /support path to the canonical Help Centre hub.
   *
   * Preserves ?event=, ?q=, and ?context= for assistant and search continuity.
   */
  public function page(): RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    $query = $request ? $request->query->all() : [];
    $allowed = [];
    foreach (['event', 'q', 'context'] as $key) {
      if (!array_key_exists($key, $query)) {
        continue;
      }
      $value = $query[$key];
      if (is_string($value) || is_int($value) || is_float($value)) {
        $allowed[$key] = $value;
      }
    }

    $options = [];
    if ($allowed !== []) {
      $options['query'] = $allowed;
    }
    if ($this->moduleHandler()->moduleExists('myeventlane_help_assistant')) {
      $settings = $this->config('myeventlane_help_assistant.settings');
      if ((bool) $settings->get('enabled')) {
        $options['fragment'] = 'mel-help-assistant';
      }
    }

    return $this->redirect('myeventlane_help_centre.home', [], $options, 301);
  }

  /**
   * Returns help article search hits for integrations using this JSON route.
   */
  public function searchApi(Request $request): JsonResponse {
    $raw = (string) ($request->query->get('q') ?? '');
    $query = trim($raw);
    if (!$this->searchRequestGuard->isAllowed($request)) {
      $response = new JsonResponse([
        'results' => [],
        'error' => 'rate_limited',
      ], 429);
      $response->headers->set('Retry-After', '60');
      $response->setMaxAge(0);
      $response->headers->addCacheControlDirective('no-store');
      return $response;
    }
    if ($query === '') {
      $this->searchRequestGuard->record('empty_query');
      $response = new JsonResponse(['results' => []]);
      $response->setMaxAge(0);
      $response->headers->addCacheControlDirective('no-store');
      return $response;
    }
    if (mb_strlen($query) > 500) {
      $query = mb_substr($query, 0, 500);
    }

    $view = Views::getView('mel_help_search');
    if ($view === NULL) {
      $this->logger->error('Support search API: mel_help_search view is not available.');
      $this->searchRequestGuard->record('search_unavailable');
      $response = new JsonResponse([
        'results' => [],
        'error' => 'search_unavailable',
      ], 503);
      $response->setMaxAge(0);
      $response->headers->addCacheControlDirective('no-store');
      return $response;
    }

    $view->setDisplay('block_search');
    $view->setExposedInput(['q' => $query]);
    $view->execute();

    $results = [];
    foreach ($view->result as $row) {
      $node = $row->_entity ?? NULL;
      if (!$node instanceof NodeInterface) {
        continue;
      }
      if (!$node->access('view')) {
        continue;
      }
      $results[] = [
        'title' => $node->label(),
        'url' => $node->toUrl()->setAbsolute(FALSE)->toString(),
      ];
      if (count($results) >= 12) {
        break;
      }
    }

    $response = new JsonResponse(['results' => $results]);
    $this->searchRequestGuard->record('success', count($results));
    $response->setMaxAge(0);
    $response->headers->addCacheControlDirective('no-store');
    return $response;
  }

}
