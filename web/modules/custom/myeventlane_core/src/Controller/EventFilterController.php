<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTML fragments for MEL category discovery chips (uses existing view displays; no new tables).
 */
final class EventFilterController extends ControllerBase {

  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns rendered rows + pager for the category context (AJAX list swap).
   */
  public function filter(Request $request): Response {
    $type = (string) $request->query->get('type', 'all');
    $category = $request->query->get('category');
    if ($category === NULL || $category === '') {
      $this->logger->warning('MEL chip filter request missing category query param.');
      return new Response('Bad Request', 400);
    }
    $tid = (string) $category;
    if (!is_numeric($tid)) {
      $this->logger->warning('MEL chip filter invalid category: @v', ['@v' => $tid]);
      return new Response('Bad Request', 400);
    }

    $request->attributes->set('mel_chip_listing_fragment', TRUE);
    if ($type === 'trending') {
      $request->attributes->set('mel_chip_trending', TRUE);
    }

    try {
      $view = Views::getView('upcoming_events');
      if ($view === NULL) {
        $this->logger->error('MEL chip filter: view upcoming_events is missing.');
        return new Response('Not Found', 404);
      }

      $build = $this->buildView($view, $type, $tid);
      $html = (string) $this->renderer->renderRoot($build);
    }
    catch (\Throwable $e) {
      $this->logger->error('MEL chip filter failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new Response('Error', 500);
    }
    finally {
      $request->attributes->remove('mel_chip_listing_fragment');
      $request->attributes->remove('mel_chip_trending');
    }

    return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
  }

  /**
   * @return array<string, mixed>
   *   A render array with a #type view element.
   */
  private function buildView(ViewExecutable $view, string $type, string $tid): array {
    $allowed = [
      'all' => 'all',
      'now' => 'now',
      'weekend' => 'weekend',
      'free' => 'free',
      'trending' => 'trending',
    ];
    if (!isset($allowed[$type])) {
      $type = 'all';
    }

    switch ($type) {
      case 'now':
        $view->setDisplay('page_today');
        $view->setExposedInput(['category' => [$tid]]);

        return $this->viewElement($view, 'page_today', []);

      case 'weekend':
        $view->setDisplay('page_this_weekend');
        $view->setExposedInput(['category' => [$tid]]);

        return $this->viewElement($view, 'page_this_weekend', []);

      case 'free':
        $view->setDisplay('page_free');
        $view->setExposedInput(['category' => [$tid]]);

        return $this->viewElement($view, 'page_free', []);

      case 'trending':
        $view->setDisplay('page_category');

        return $this->viewElement($view, 'page_category', [$tid]);

      case 'all':
      default:
        $view->setDisplay('page_category');

        return $this->viewElement($view, 'page_category', [$tid]);
    }
  }

  /**
   * @param array<int|string> $args
   *   Arguments passed to ViewExecutable::preview().
   *
   * @return array<string, mixed>
   */
  private function viewElement(ViewExecutable $view, string $display_id, array $args): array {
    return [
      '#type' => 'view',
      '#name' => 'upcoming_events',
      '#view' => $view,
      '#display_id' => $display_id,
      '#arguments' => $args,
      '#embed' => TRUE,
    ];
  }

}
