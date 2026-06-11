<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_front\Service\SitemapBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the public HTML sitemap at /sitemap.
 */
final class SitemapController extends ControllerBase {

  public function __construct(
    private readonly SitemapBuilder $sitemapBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_front.sitemap_builder'),
    );
  }

  /**
   * Builds the sitemap page.
   */
  public function page(): array {
    return [
      '#theme' => 'myeventlane_front_sitemap',
      '#sections' => $this->sitemapBuilder->buildSections(),
      '#cache' => [
        'tags' => [
          'config:system.menu.footer-find',
          'config:system.menu.footer-host',
          'config:system.menu.footer-about',
          'config:system.menu.footer-community',
          'config:myeventlane_legal.settings',
        ],
        'contexts' => ['languages:language_interface'],
        'max-age' => 3600,
      ],
    ];
  }

}
