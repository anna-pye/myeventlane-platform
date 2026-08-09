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
      '#attached' => [
        'html_head' => [
          [
            [
              '#tag' => 'meta',
              '#attributes' => [
                'name' => 'description',
                'content' => 'Explore MyEventLane events, organiser resources, support, trust information and legal pages.',
              ],
            ],
            'myeventlane_front_sitemap_description',
          ],
        ],
      ],
      '#cache' => [
        'tags' => [
          'config:myeventlane_legal.settings',
          'taxonomy_term_list:categories',
        ],
        'contexts' => ['languages:language_interface'],
        'max-age' => 3600,
      ],
    ];
  }

}
