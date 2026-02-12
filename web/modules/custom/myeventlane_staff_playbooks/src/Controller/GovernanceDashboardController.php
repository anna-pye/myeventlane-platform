<?php

declare(strict_types=1);

namespace Drupal\myeventlane_staff_playbooks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Governance dashboard for internal staff guides and policies.
 *
 * Displays staff-only help articles grouped by category. Fully cacheable.
 */
final class GovernanceDashboardController extends ControllerBase {

  public function __construct(
    private readonly RouteProviderInterface $routeProvider,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('router.route_provider'),
      $container->get('logger.factory')->get('myeventlane_staff_playbooks'),
    );
  }

  /**
   * Builds the governance dashboard.
   *
   * @return array
   *   A render array.
   */
  public function build(): array {
    $groups = $this->loadStaffArticles();

    $snippet_guide_url = NULL;
    if ($this->moduleHandler()->moduleExists('myeventlane_help_centre')) {
      try {
        $route = $this->routeProvider->getRouteByName('myeventlane_help_centre.staff_snippet_authoring');
        if ($route) {
          $snippet_guide_url = Url::fromRoute('myeventlane_help_centre.staff_snippet_authoring')->toString();
        }
      }
      catch (\Exception $e) {
        // Fail silently.
      }
    }

    $playbooks_url = NULL;
    try {
      $route = $this->routeProvider->getRouteByName('entity.node.add_form');
      if ($route) {
        $playbooks_url = Url::fromRoute('entity.node.add_form', ['node_type' => 'staff_playbook'])->toString();
      }
    }
    catch (\Exception $e) {
      // Fail silently.
    }

    return [
      '#theme' => 'governance_dashboard',
      '#groups' => $groups,
      '#snippet_guide_url' => $snippet_guide_url,
      '#playbooks_url' => $playbooks_url,
      '#attached' => ['library' => ['myeventlane_staff_playbooks/governance_dashboard']],
      '#cache' => [
        'tags' => ['node_list:help_article', 'node_list:staff_playbook'],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Loads staff help articles grouped by category.
   *
   * @return array
   *   Groups keyed by category name.
   */
  private function loadStaffArticles(): array {
    if (!$this->moduleHandler()->moduleExists('myeventlane_help_centre')) {
      return [];
    }

    try {
      $nodeStorage = $this->entityTypeManager()->getStorage('node');

      $query = $nodeStorage->getQuery()
        ->condition('type', 'help_article')
        ->condition('status', 1)
        ->condition('field_audience', 'staff')
        ->sort('field_priority', 'ASC')
        ->sort('title', 'ASC')
        ->accessCheck(TRUE);

      $nids = $query->execute();

      if (empty($nids)) {
        return [];
      }

      $nodes = $nodeStorage->loadMultiple($nids);
      $grouped = [];

      foreach ($nodes as $node) {
        if ($node->get('field_help_category')->isEmpty()) {
          $category = $this->t('Other');
        }
        else {
          $term = $node->get('field_help_category')->entity;
          $category = $term !== NULL ? $term->label() : $this->t('Other');
        }

        if (!isset($grouped[$category])) {
          $grouped[$category] = [
            'name' => $category,
            'articles' => [],
          ];
        }

        $grouped[$category]['articles'][] = [
          'title' => $node->label(),
          'url' => $node->toUrl()->toString(),
        ];
      }

      return array_values($grouped);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to load staff help articles: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

}
