<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\myeventlane_analytics\Service\PopularEventsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a "Popular this week" events block.
 *
 * @Block(
 *   id = "myeventlane_popular_events_block",
 *   admin_label = @Translation("Popular this week (MyEventLane)"),
 *   category = @Translation("MyEventLane")
 * )
 */
final class PopularEventsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * @var \Drupal\myeventlane_analytics\Service\PopularEventsService
   */
  private PopularEventsService $popular;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    PopularEventsService $popular,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->popular = $popular;
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_analytics.popular_events'),
      $container->get('entity_type.manager')
    );
  }

  public function defaultConfiguration(): array {
    return [
      'days' => 7,
      'limit' => 8,
      // Default is intentionally a best-guess; can be changed in block UI.
      // If this view mode doesn't exist, we fall back to 'teaser' at runtime.
      'view_mode' => 'event_card',
      'title' => 'Popular this week',
      'show_going' => TRUE,
    ] + parent::defaultConfiguration();
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Block title'),
      '#default_value' => (string) ($this->configuration['title'] ?? 'Popular this week'),
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
      '#description' => $this->t('Use an existing event card view mode (e.g. event_card, teaser, event_card_poster).'),
      '#default_value' => (string) ($this->configuration['view_mode'] ?? 'event_card'),
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

    $this->configuration['title'] = (string) $form_state->getValue('title');
    $this->configuration['days'] = (int) $form_state->getValue('days');
    $this->configuration['limit'] = (int) $form_state->getValue('limit');
    $this->configuration['view_mode'] = (string) $form_state->getValue('view_mode');
    $this->configuration['show_going'] = (bool) $form_state->getValue('show_going');
  }

  public function build(): array {
    $days = (int) ($this->configuration['days'] ?? 7);
    $limit = (int) ($this->configuration['limit'] ?? 8);
    $view_mode = (string) ($this->configuration['view_mode'] ?? 'event_card');
    $title = (string) ($this->configuration['title'] ?? 'Popular this week');
    $show_going = (bool) ($this->configuration['show_going'] ?? TRUE);

    $rows = $this->popular->getPopularEventIds($days, $limit);
    if (empty($rows)) {
      return [
        '#markup' => '',
        '#cache' => [
          'max-age' => 900,
          'contexts' => ['languages:language_interface'],
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

    // Fallback if the provided view mode doesn't exist.
    $view_builder = $this->entityTypeManager->getViewBuilder('node');
    $view_mode_to_use = $view_mode;

    // Build cards.
    $items = [];
    foreach ($rows as $row) {
      $nid = (int) $row['nid'];
      if (!isset($ordered_nodes[$nid])) {
        continue;
      }

      $card = $view_builder->view($ordered_nodes[$nid], $view_mode_to_use);

      // Render-only wrapper adds "X going" without changing card templates.
      $wrapper = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-popular-event'],
          'data-nid' => (string) $nid,
          'data-score' => (string) ((int) $row['score']),
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

    // Cache tags: include each node so edits invalidate immediately.
    $node_tags = array_map(static fn(int $nid): string => "node:$nid", array_keys($ordered_nodes));

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-popular-events-block'],
      ],
      'header' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $title,
        '#attributes' => ['class' => ['mel-popular-events-block__title']],
      ],
      'list' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-popular-events-block__grid'],
        ],
        'items' => $items,
      ],
      '#cache' => [
        // Short TTL ensures RSVP changes are reflected even if RSVP entities/tags
        // are not perfectly tagged everywhere yet.
        'max-age' => 900,
        'contexts' => ['languages:language_interface'],
        'tags' => Cache::mergeTags(['node_list'], $node_tags),
      ],
    ];

    return $build;
  }

}

