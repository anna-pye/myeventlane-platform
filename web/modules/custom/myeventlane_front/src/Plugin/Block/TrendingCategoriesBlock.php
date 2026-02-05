<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_analytics\Service\TrendingCategoriesService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a "Trending categories" block.
 *
 * @Block(
 *   id = "myeventlane_trending_categories_block",
 *   admin_label = @Translation("Trending categories (MyEventLane)"),
 *   category = @Translation("MyEventLane")
 * )
 */
final class TrendingCategoriesBlock extends BlockBase implements ContainerFactoryPluginInterface {

  private TrendingCategoriesService $trending;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    TrendingCategoriesService $trending,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->trending = $trending;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_analytics.trending_categories'),
    );
  }

  public function defaultConfiguration(): array {
    return [
      'title' => 'Trending categories',
      'days' => 7,
      'limit' => 8,
    ] + parent::defaultConfiguration();
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Block title'),
      '#default_value' => (string) ($this->configuration['title'] ?? 'Trending categories'),
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
      '#title' => $this->t('Max categories'),
      '#default_value' => (int) ($this->configuration['limit'] ?? 8),
      '#min' => 1,
      '#max' => 24,
      '#required' => TRUE,
    ];

    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);

    $this->configuration['title'] = (string) $form_state->getValue('title');
    $this->configuration['days'] = (int) $form_state->getValue('days');
    $this->configuration['limit'] = (int) $form_state->getValue('limit');
  }

  public function build(): array {
    $days = (int) ($this->configuration['days'] ?? 7);
    $limit = (int) ($this->configuration['limit'] ?? 8);
    $title = (string) ($this->configuration['title'] ?? 'Trending categories');

    $items = $this->trending->getTrendingCategories($days, $limit);
    if (empty($items)) {
      return [
        '#markup' => '',
        '#cache' => [
          'max-age' => 900,
          'contexts' => ['languages:language_interface'],
        ],
      ];
    }

    $term_tags = [];
    $node_tags = [];
    foreach ($items as $item) {
      $tid = (int) ($item['tid'] ?? 0);
      if ($tid > 0) {
        $term_tags[] = "taxonomy_term:$tid";
      }
      foreach ((array) ($item['event_ids'] ?? []) as $nid) {
        $nid = (int) $nid;
        if ($nid > 0) {
          $node_tags[] = "node:$nid";
        }
      }
    }

    $pill_items = [];
    foreach ($items as $item) {
      $tid = (int) ($item['tid'] ?? 0);
      if ($tid <= 0) {
        continue;
      }

      $label = (string) ($item['label'] ?? '');
      if ($label === '') {
        $label = (string) $this->t('Category @tid', ['@tid' => $tid]);
      }

      $going = (int) ($item['going'] ?? 0);
      $score = (int) ($item['score'] ?? 0);

      $pill_items[] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-trending-category-pill'],
          'data-tid' => (string) $tid,
          'data-score' => (string) $score,
          'data-going' => (string) $going,
        ],
        'link' => [
          '#type' => 'link',
          '#title' => $label,
          '#url' => Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid]),
          '#attributes' => [
            'class' => ['mel-trending-category-pill__link'],
          ],
        ],
        'meta' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-trending-category-pill__meta']],
          'going' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => $this->t('@count going', ['@count' => $going]),
            '#attributes' => ['class' => ['mel-trending-category-pill__going']],
          ],
          'score' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => $this->t('@score score', ['@score' => $score]),
            '#attributes' => ['class' => ['mel-trending-category-pill__score']],
          ],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-trending-categories-block'],
      ],
      'header' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $title,
        '#attributes' => ['class' => ['mel-trending-categories-block__title']],
      ],
      'list' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-trending-categories-block__grid'],
        ],
        'items' => $pill_items,
      ],
      '#cache' => [
        'max-age' => 900,
        'contexts' => ['languages:language_interface'],
        'tags' => Cache::mergeTags(['node_list'], array_values(array_unique(array_merge($term_tags, $node_tags)))),
      ],
    ];
  }

}

