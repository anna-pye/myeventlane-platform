<?php

namespace Drupal\myeventlane_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_shared\Service\ColorService;

/**
 * Provides a 'CategoryPieChartBlock' block.
 *
 * @Block(
 *   id = "category_pie_chart_block",
 *   admin_label = @Translation("Category Pie Chart"),
 *   category = @Translation("MyEventLane")
 * )
 */
class CategoryPieChartBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The color service.
   *
   * @var \Drupal\myeventlane_shared\Service\ColorService
   */
  protected $colorService;

  /**
   * Constructs a new CategoryPieChartBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\myeventlane_shared\Service\ColorService $color_service
   *   The color service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, ColorService $color_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->colorService = $color_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->has('myeventlane_shared.color_service') ? $container->get('myeventlane_shared.color_service') : new ColorService()
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    // Load all terms from the categories vocabulary.
    $terms = $term_storage->loadTree('categories', 0, NULL, TRUE);

    $counts = [];
    $labels = [];
    $colors = [];

    $node_storage = $this->entityTypeManager->getStorage('node');

    foreach ($terms as $term) {
      $term_name = $term->getName();
      $label = $term_name;

      // Query for published events with this category.
      $query = $node_storage->getQuery()
        ->condition('type', 'event')
        ->condition('status', 1)
        ->condition('field_category', $term->id())
        ->accessCheck(TRUE);

      $count = $query->count()->execute();

      if ($count > 0) {
        $counts[] = $count;
        $labels[] = $label;
        // Get color from ColorService, with fallback.
        $color = $this->colorService->getColorForTerm($term_name);
        $colors[] = $color ?? $this->colorService->getFallbackColor();
      }
    }

    // Only render if we have data.
    if (empty($counts)) {
      return [
        '#markup' => '<p>' . $this->t('No event categories available.') . '</p>',
      ];
    }

    return [
      '#theme' => 'category_pie_chart',
      '#labels' => $labels,
      '#counts' => $counts,
      '#colors' => $colors,
      '#attached' => [
        'library' => ['myeventlane_blocks/chartjs'],
      ],
      '#cache' => [
        'tags' => ['node_list:event', 'taxonomy_term_list:categories'],
        'contexts' => ['url'],
      ],
    ];
  }

}
