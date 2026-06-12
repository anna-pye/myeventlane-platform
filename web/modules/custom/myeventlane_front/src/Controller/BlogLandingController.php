<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_front\Service\BlogLandingPageBuilder;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Renders the public blog landing page and filtered listings at /blog.
 */
final class BlogLandingController extends ControllerBase {

  /**
   * @var list<string>
   */
  private const FILTER_QUERY_KEYS = ['category', 'tags', 'search', 'audience'];

  public function __construct(
    private readonly BlogLandingPageBuilder $pageBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_front.blog_landing_page'),
    );
  }

  /**
   * Builds the blog landing page or a filtered listing.
   */
  public function page(Request $request): array {
    if ($this->hasActiveFilters($request)) {
      return $this->buildFilteredListing();
    }

    $page = $this->pageBuilder->build();

    return [
      '#theme' => 'myeventlane_blog_landing',
      '#page' => $page,
      '#attached' => [
        'library' => [
          'myeventlane_theme/blog-landing',
        ],
      ],
      '#cache' => [
        'tags' => [
          'node_list:blog_post',
          'taxonomy_term_list:tags',
          'taxonomy_term_list:organiser_hub_categories',
        ],
        'contexts' => ['languages:language_interface'],
        'max-age' => 3600,
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildFilteredListing(): array {
    $view = Views::getView('mel_blog');
    $viewBuild = [];
    if ($view !== NULL) {
      $view->setDisplay('page_blog');
      $view->initHandlers();
      $viewBuild = $view->buildRenderable('page_blog');
    }

    return [
      '#theme' => 'myeventlane_blog_filtered',
      '#view' => $viewBuild,
      '#filters' => $this->pageBuilder->buildFilters(),
      '#attached' => [
        'library' => [
          'myeventlane_theme/blog-landing',
        ],
      ],
      '#cache' => [
        'tags' => ['node_list:blog_post'],
        'contexts' => ['languages:language_interface', 'url.query_args'],
        'max-age' => 3600,
      ],
    ];
  }

  private function hasActiveFilters(Request $request): bool {
    foreach (self::FILTER_QUERY_KEYS as $key) {
      $value = $request->query->get($key);
      if (is_string($value) && trim($value) !== '') {
        return TRUE;
      }
      if (is_array($value) && $value !== []) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
