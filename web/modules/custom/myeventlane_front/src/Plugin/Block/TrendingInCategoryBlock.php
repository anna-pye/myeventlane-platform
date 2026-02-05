<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\myeventlane_analytics\Service\TrendingCategoriesService;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a "Trending in this category" block for taxonomy term pages.
 *
 * @Block(
 *   id = "myeventlane_trending_in_category_block",
 *   admin_label = @Translation("Trending in this category (MyEventLane)"),
 *   category = @Translation("MyEventLane")
 * )
 */
final class TrendingInCategoryBlock extends BlockBase implements ContainerFactoryPluginInterface {

  private TrendingCategoriesService $trending;
  private EntityTypeManagerInterface $entityTypeManager;
  private RouteMatchInterface $routeMatch;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    TrendingCategoriesService $trending,
    EntityTypeManagerInterface $entity_type_manager,
    RouteMatchInterface $route_match,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->trending = $trending;
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_analytics.trending_categories'),
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
    );
  }

  public function defaultConfiguration(): array {
    return [
      // @category is replaced at runtime on term pages.
      'title_template' => 'Trending in @category',
      'days' => 7,
      'limit' => 8,
      'view_mode' => 'card',
      'show_going' => TRUE,
    ] + parent::defaultConfiguration();
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);

    $form['title_template'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Block title template'),
      '#description' => $this->t('Use @category as a placeholder (e.g. "Trending in @category").'),
      '#default_value' => (string) ($this->configuration['title_template'] ?? 'Trending in @category'),
      '#required' => TRUE,
    ];

    $form['days'] = [
      '#type' => 'number',
      '#title' => $this->t('Lookback days'),
      '#default_value' => (int) ($this->configuration['days'] ?? 7),
      '#min' => 1,
      '#max' => 60,
      '#required' => TRUE,
    ];

    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Max events'),
      '#default_value' => (int) ($this->configuration['limit'] ?? 8),
      '#min' => 1,
      '#max' => 24,
      '#required' => TRUE,
    ];

    $form['view_mode'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Node view mode to render'),
      '#description' => $this->t('Use an existing event card view mode (e.g. card, teaser, event_card, event_card_poster).'),
      '#default_value' => (string) ($this->configuration['view_mode'] ?? 'card'),
      '#required' => TRUE,
    ];

    $form['show_going'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show “X going” label under each card'),
      '#default_value' => (bool) ($this->configuration['show_going'] ?? TRUE),
    ];

    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);

    $this->configuration['title_template'] = (string) $form_state->getValue('title_template');
    $this->configuration['days'] = (int) $form_state->getValue('days');
    $this->configuration['limit'] = (int) $form_state->getValue('limit');
    $this->configuration['view_mode'] = (string) $form_state->getValue('view_mode');
    $this->configuration['show_going'] = (bool) $form_state->getValue('show_going');
  }

  public function build(): array {
    $term = $this->routeMatch->getParameter('taxonomy_term');
    if (!$term instanceof TermInterface) {
      return [
        '#markup' => '',
        '#cache' => [
          'max-age' => 900,
          'contexts' => ['route', 'languages:language_interface'],
        ],
      ];
    }

    $tid = (int) $term->id();
    $term_label = $term->label();

    $days = (int) ($this->configuration['days'] ?? 7);
    $limit = (int) ($this->configuration['limit'] ?? 8);
    $view_mode = (string) ($this->configuration['view_mode'] ?? 'card');
    $show_going = (bool) ($this->configuration['show_going'] ?? TRUE);
    $title_template = (string) ($this->configuration['title_template'] ?? 'Trending in @category');

    $title = $this->t($title_template, ['@category' => $term_label]);

    $rows = $this->trending->getTrendingEventsForCategory($tid, $days, $limit);
    if (empty($rows)) {
      return [
        '#markup' => '',
        '#cache' => [
          'max-age' => 900,
          'contexts' => ['route', 'languages:language_interface'],
          'tags' => ["taxonomy_term:$tid"],
        ],
      ];
    }

    $nids = array_map(static fn(array $r): int => (int) $r['nid'], $rows);

    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $nodes = $storage->loadMultiple($nids);

    // Keep the original popularity order.
    $ordered_nodes = [];
    foreach ($nids as $nid) {
      if (isset($nodes[$nid])) {
        $ordered_nodes[$nid] = $nodes[$nid];
      }
    }

    $view_builder = $this->entityTypeManager->getViewBuilder('node');

    $items = [];
    foreach ($rows as $row) {
      $nid = (int) $row['nid'];
      if (!isset($ordered_nodes[$nid])) {
        continue;
      }

      $card = $view_builder->view($ordered_nodes[$nid], $view_mode);

      // Render-only wrapper adds "X going" without changing card templates.
      $wrapper = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-popular-event', 'mel-trending-in-category-event'],
          'data-nid' => (string) $nid,
          'data-score' => (string) ((int) ($row['score'] ?? 0)),
        ],
        'card' => $card,
      ];

      if ($show_going) {
        $going = (int) ($row['going'] ?? 0);
        $wrapper['going'] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $this->t('@count going', ['@count' => $going]),
          '#attributes' => [
            'class' => ['mel-popular-event__going'],
          ],
        ];
      }

      $items[] = $wrapper;
    }

    $node_tags = array_map(static fn(int $nid): string => "node:$nid", array_keys($ordered_nodes));

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-trending-in-category-block'],
      ],
      'header' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $title,
        '#attributes' => ['class' => ['mel-trending-in-category-block__title']],
      ],
      'list' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-trending-in-category-block__grid'],
        ],
        'items' => $items,
      ],
      '#cache' => [
        'max-age' => 900,
        'contexts' => ['route', 'languages:language_interface'],
        'tags' => Cache::mergeTags(["taxonomy_term:$tid", 'node_list'], $node_tags),
      ],
    ];
  }

}

