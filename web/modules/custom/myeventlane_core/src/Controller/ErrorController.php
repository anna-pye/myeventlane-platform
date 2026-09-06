<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders standalone recovery pages, including exception subrequests.
 */
final class ErrorController extends ControllerBase {

  public function __construct(
    private readonly BareHtmlPageRendererInterface $bareRenderer,
    private readonly ThemeManagerInterface $themeManager,
    private readonly ThemeInitializationInterface $themeInitialization,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bare_html_page_renderer'),
      $container->get('theme.manager'),
      $container->get('theme.initialization'),
    );
  }

  public function accessDenied(): HtmlResponse {
    return $this->renderError(403, $this->t('Access denied'));
  }

  public function pageNotFound(): HtmlResponse {
    return $this->renderError(404, $this->t('Page not found'));
  }

  private function renderError(int $status, $title): HtmlResponse {
    // Exception subrequests can inherit an already initialised public/vendor
    // theme. A route negotiator alone cannot replace that active theme.
    $previous = $this->themeManager->getActiveTheme();
    try {
      $this->themeManager->setActiveTheme($this->themeInitialization->initTheme('mel_maintenance'));
      $response = $this->bareRenderer->renderBarePage([], $title, 'page__' . $status, ['#show_messages' => FALSE]);
      $response->setStatusCode($status);
      $response->headers->set('Cache-Control', 'no-store, private');
      $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
      return $response;
    }
    finally {
      $this->themeManager->setActiveTheme($previous);
    }
  }

}
