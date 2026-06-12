<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_front\Service\OrganiserHubPageBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the Organiser Hub landing page at /resources.
 */
final class OrganiserHubController extends ControllerBase {

  public function __construct(
    private readonly OrganiserHubPageBuilder $pageBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_front.organiser_hub_page'),
    );
  }

  /**
   * Builds the Organiser Hub landing page.
   */
  public function page(): array {
    $page = $this->pageBuilder->build();

    return [
      '#theme' => 'myeventlane_organiser_hub',
      '#page' => $page,
      '#attached' => [
        'library' => [
          'myeventlane_theme/organiser-hub-landing',
        ],
      ],
      '#cache' => [
        'tags' => [
          'node_list:blog_post',
          'taxonomy_term_list:organiser_hub_categories',
        ],
        'contexts' => ['languages:language_interface'],
        'max-age' => 3600,
      ],
    ];
  }

}
