<?php

declare(strict_types=1);

namespace Drupal\myeventlane_staff_playbooks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_core\Service\MelAdminShellBuilder;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Governance dashboard for internal staff guides and policies.
 *
 * Displays staff-only help articles grouped by category and staff playbooks
 * grouped by playbook category. Fully cacheable.
 */
final class GovernanceDashboardController extends ControllerBase {

  public function __construct(
    private readonly RouteProviderInterface $routeProvider,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly LoggerInterface $logger,
    private readonly MelAdminShellBuilder $adminShellBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('router.route_provider'),
      $container->get('entity_field.manager'),
      $container->get('logger.factory')->get('myeventlane_staff_playbooks'),
      $container->get('myeventlane_core.mel_admin_shell_builder'),
    );
  }

  /**
   * Builds the governance dashboard.
   */
  public function build(): array {
    $groups = $this->loadStaffArticles();
    $playbook_groups = $this->loadPlaybookGroups();
    $quick_links = $this->buildQuickLinks();

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

    $main = [
      '#theme' => 'governance_dashboard',
      '#groups' => $groups,
      '#playbook_groups' => $playbook_groups,
      '#quick_links' => $quick_links,
      '#snippet_guide_url' => $snippet_guide_url,
      '#playbooks_url' => $playbooks_url,
      '#attached' => ['library' => ['myeventlane_staff_playbooks/governance_dashboard']],
      '#cache' => [
        'tags' => ['node_list:help_article', 'node_list:staff_playbook'],
        'contexts' => ['user.permissions'],
      ],
    ];

    return $this->adminShellBuilder->wrapStandard(
      $main,
      $this->t('Internal Governance'),
      $this->t('Staff guides, playbooks, and quick links.'),
    );
  }

  /**
   * Loads staff help articles grouped by help category taxonomy.
   *
   * @return list<array{name: string, articles: list<array{title: string, url: string}>}>
   */
  private function loadStaffArticles(): array {
    if (!$this->moduleHandler()->moduleExists('myeventlane_help_centre')) {
      return [];
    }

    $storageDefinitions = $this->entityFieldManager->getActiveFieldStorageDefinitions('node');
    if (!isset($storageDefinitions['field_audience']) || !isset($storageDefinitions['field_priority'])) {
      $this->logger->error(
        'GovernanceDashboardController: field_audience or field_priority missing from node field storage. Run config:import or verify myeventlane_help_centre is correctly installed.'
      );
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
        if (!$node instanceof NodeInterface) {
          continue;
        }
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

  /**
   * Loads published staff playbooks grouped by playbook category.
   *
   * @return list<array{name: string, playbooks: list<array{title: string, url: string}>}>
   */
  private function loadPlaybookGroups(): array {
    $storageDefinitions = $this->entityFieldManager->getActiveFieldStorageDefinitions('node');
    if (!isset($storageDefinitions['field_playbook_category'])) {
      return [];
    }

    try {
      $nodeStorage = $this->entityTypeManager()->getStorage('node');
      $nids = $nodeStorage->getQuery()
        ->condition('type', 'staff_playbook')
        ->condition('status', 1)
        ->sort('title', 'ASC')
        ->accessCheck(TRUE)
        ->execute();

      if (empty($nids)) {
        return [];
      }

      /** @var array<int, \Drupal\node\NodeInterface> $nodes */
      $nodes = $nodeStorage->loadMultiple($nids);
      $allowed = $this->playbookCategoryLabels();
      $grouped = [];

      foreach ($nodes as $node) {
        if (!$node instanceof NodeInterface) {
          continue;
        }
        $labels = [];
        if (!$node->get('field_playbook_category')->isEmpty()) {
          foreach ($node->get('field_playbook_category') as $item) {
            $val = (string) $item->value;
            if ($val !== '') {
              $labels[] = $allowed[$val] ?? $val;
            }
          }
        }
        if ($labels === []) {
          $labels[] = (string) $this->t('Other');
        }
        $primary = $labels[0];
        if (!isset($grouped[$primary])) {
          $grouped[$primary] = [
            'name' => $primary,
            'playbooks' => [],
          ];
        }
        $grouped[$primary]['playbooks'][] = [
          'title' => $node->label(),
          'url' => $node->toUrl()->toString(),
        ];
      }

      uksort($grouped, 'strnatcasecmp');

      return array_values($grouped);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to load staff playbooks for governance: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Quick links to high-traffic internal guides (node references only).
   *
   * @return array{refund_playbook: ?array{title: string, url: string}, escalation_playbook: ?array{title: string, url: string}, docs_register: ?string, docs_health: ?string}
   */
  private function buildQuickLinks(): array {
    $out = [
      'refund_playbook' => NULL,
      'escalation_playbook' => NULL,
      'docs_register' => NULL,
      'docs_health' => NULL,
    ];

    try {
      $register = Url::fromRoute('view.mel_docs_register.page_register');
      if ($register->access($this->currentUser())) {
        $out['docs_register'] = $register->toString();
      }
    }
    catch (\Throwable) {
    }

    try {
      $health = Url::fromRoute('myeventlane_help_centre.docs_health');
      if ($health->access($this->currentUser())) {
        $out['docs_health'] = $health->toString();
      }
    }
    catch (\Throwable) {
    }

    try {
      $nodeStorage = $this->entityTypeManager()->getStorage('node');
      $refund_nids = $nodeStorage->getQuery()
        ->condition('type', 'staff_playbook')
        ->condition('status', 1)
        ->condition('field_playbook_type', 'refund')
        ->range(0, 1)
        ->accessCheck(TRUE)
        ->execute();
      if ($refund_nids !== []) {
        $nid = (int) reset($refund_nids);
        $node = $nodeStorage->load($nid);
        if ($node instanceof NodeInterface) {
          $out['refund_playbook'] = [
            'title' => $node->label(),
            'url' => $node->toUrl()->toString(),
          ];
        }
      }

      $escalation_nids = $nodeStorage->getQuery()
        ->condition('type', 'staff_playbook')
        ->condition('status', 1)
        ->condition('field_playbook_category', 'escalation')
        ->range(0, 1)
        ->accessCheck(TRUE)
        ->execute();
      if ($escalation_nids !== []) {
        $nid = (int) reset($escalation_nids);
        $node = $nodeStorage->load($nid);
        if ($node instanceof NodeInterface) {
          $out['escalation_playbook'] = [
            'title' => $node->label(),
            'url' => $node->toUrl()->toString(),
          ];
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->notice('Governance quick links: @message', ['@message' => $e->getMessage()]);
    }

    return $out;
  }

  /**
   * @return array<string, string>
   */
  private function playbookCategoryLabels(): array {
    $all = $this->entityFieldManager->getActiveFieldStorageDefinitions('node');
    $storage = $all['field_playbook_category'] ?? NULL;
    if ($storage === NULL || $storage->getSetting('allowed_values') === NULL) {
      return [];
    }
    $map = [];
    foreach ($storage->getSetting('allowed_values') as $item) {
      if (!empty($item['value'])) {
        $map[(string) $item['value']] = (string) ($item['label'] ?? $item['value']);
      }
    }
    return $map;
  }

}
