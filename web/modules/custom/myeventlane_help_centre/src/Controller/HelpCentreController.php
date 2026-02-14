<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the help centre pages.
 *
 * Loads help_article nodes filtered by audience and grouped by
 * taxonomy term (field_help_category). No analytics, no tracking,
 * no behavioural scoring. Content is fully cacheable.
 */
final class HelpCentreController extends ControllerBase {

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('logger.factory')->get('myeventlane_help_centre'),
    );
  }

  /**
   * Renders the public help centre index.
   *
   * Shows published help articles with audience "public", grouped by
   * category and ordered by priority then title.
   *
   * @return array
   *   A render array.
   */
  public function publicIndex(): array {
    $groups = $this->loadGroupedArticles('public');

    return [
      '#theme' => 'help_centre_index',
      '#heading' => $this->t('Help Centre'),
      '#groups' => $groups,
      '#is_vendor' => FALSE,
      '#cache' => [
        'tags' => ['node_list:help_article'],
        'contexts' => ['url'],
      ],
    ];
  }

  /**
   * Renders the vendor help centre index.
   *
   * Shows published help articles with audience "vendor", grouped by
   * category and ordered by priority then title.
   *
   * @return array
   *   A render array.
   */
  public function vendorIndex(): array {
    $groups = $this->loadGroupedArticles('vendor');

    return [
      '#theme' => 'help_centre_index',
      '#heading' => $this->t('Help Centre'),
      '#groups' => $groups,
      '#is_vendor' => TRUE,
      '#cache' => [
        'tags' => ['node_list:help_article'],
        'contexts' => ['url', 'user.permissions'],
      ],
    ];
  }

  /**
   * Renders a vendor help centre topic page.
   *
   * Shows published help articles with audience "vendor" filtered to
   * a specific help category taxonomy term.
   *
   * @param \Drupal\taxonomy\TermInterface $category
   *   The help category taxonomy term.
   *
   * @return array
   *   A render array.
   */
  public function vendorTopic(TermInterface $category): array {
    $groups = $this->loadGroupedArticles('vendor', $category);

    return [
      '#theme' => 'help_centre_index',
      '#heading' => $category->label(),
      '#groups' => $groups,
      '#is_vendor' => TRUE,
      '#cache' => [
        'tags' => array_merge(
          ['node_list:help_article'],
          $category->getCacheTags(),
        ),
        'contexts' => ['url', 'user.permissions'],
      ],
    ];
  }

  /**
   * Title callback for vendor topic pages.
   *
   * @param \Drupal\taxonomy\TermInterface $category
   *   The help category taxonomy term.
   *
   * @return string
   *   The page title.
   */
  public function vendorTopicTitle(TermInterface $category): string {
    return (string) $category->label();
  }

  /**
   * Renders the Staff Snippet Authoring Guide.
   *
   * Admin-only. Loads the help_article "Staff Snippet Authoring Guide"
   * (audience: staff) and displays it.
   *
   * @return array
   *   A render array.
   */
  public function staffSnippetAuthoring(): array {
    try {
      $nodes = $this->entityTypeManager()->getStorage('node')
        ->loadByProperties([
          'type' => 'help_article',
          'title' => 'Staff Snippet Authoring Guide',
          'status' => 1,
        ]);
      $node = $nodes ? reset($nodes) : NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to load Staff Snippet Authoring Guide: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        '#markup' => '<p>' . $this->t('The guide is not yet available. Please contact your administrator.') . '</p>',
        '#cache' => ['max-age' => 0],
      ];
    }

    if (!$node) {
      return [
        '#markup' => '<p>' . $this->t('The guide has not been created yet. Run update.php to create it.') . '</p>',
        '#cache' => ['max-age' => 0],
      ];
    }

    $view_builder = $this->entityTypeManager()->getViewBuilder('node');
    $build = $view_builder->view($node, 'full');

    $build['#cache']['tags'] = array_merge(
      $build['#cache']['tags'] ?? [],
      ['node:' . $node->id(), 'node_list:help_article'],
    );
    $build['#cache']['contexts'] = ['user.permissions'];

    return $build;
  }

  /**
   * Loads help articles grouped by category.
   *
   * @param string $audience
   *   The audience filter ('public' or 'vendor').
   * @param \Drupal\taxonomy\TermInterface|null $category
   *   Optional category to filter by.
   *
   * @return array
   *   An array of groups, each containing:
   *   - 'term_id': The taxonomy term ID.
   *   - 'term_name': The taxonomy term label.
   *   - 'term_url': URL to the vendor topic page (vendor only).
   *   - 'articles': Array of article data.
   */
  private function loadGroupedArticles(string $audience, ?TermInterface $category = NULL): array {
    $storageDefinitions = $this->entityFieldManager->getActiveFieldStorageDefinitions('node');
    if (!isset($storageDefinitions['field_audience']) || !isset($storageDefinitions['field_priority'])) {
      $this->logger->error(
        'HelpCentreController: field_audience or field_priority missing from node field storage. Run config:import or verify myeventlane_help_centre is correctly installed.'
      );
      return [];
    }

    try {
      $nodeStorage = $this->entityTypeManager()->getStorage('node');

      $query = $nodeStorage->getQuery()
        ->condition('type', 'help_article')
        ->condition('status', 1)
        ->condition('field_audience', $audience)
        ->sort('field_priority', 'ASC')
        ->sort('title', 'ASC')
        ->accessCheck(TRUE);

      if ($category !== NULL) {
        $query->condition('field_help_category', $category->id());
      }

      $nids = $query->execute();

      if (empty($nids)) {
        return [];
      }

      $nodes = $nodeStorage->loadMultiple($nids);
      $grouped = [];

      foreach ($nodes as $node) {
        if ($node->get('field_help_category')->isEmpty()) {
          continue;
        }

        /** @var \Drupal\taxonomy\TermInterface $term */
        $term = $node->get('field_help_category')->entity;
        if ($term === NULL) {
          continue;
        }

        $termId = (int) $term->id();
        if (!isset($grouped[$termId])) {
          $termUrl = NULL;
          if ($audience === 'vendor') {
            $termUrl = Url::fromRoute('myeventlane_help_centre.vendor_topic', [
              'category' => $termId,
            ])->toString();
          }

          $grouped[$termId] = [
            'term_id' => $termId,
            'term_name' => $term->label(),
            'term_url' => $termUrl,
            'articles' => [],
          ];
        }

        $grouped[$termId]['articles'][] = [
          'nid' => (int) $node->id(),
          'title' => $node->label(),
          'url' => $node->toUrl()->toString(),
          'summary' => $node->hasField('body') && !$node->get('body')->isEmpty()
            ? $node->get('body')->summary
            : NULL,
        ];
      }

      // Re-index to ensure sequential keys.
      return array_values($grouped);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to load help articles: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

}
