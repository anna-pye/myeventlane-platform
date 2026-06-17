<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\myeventlane_analytics\Service\PopularEventsService;
use Drupal\myeventlane_core\Service\DiscoveryAttributionSources;
use Drupal\myeventlane_event\Service\PublicEventVisibility;
use Drupal\myeventlane_front\Service\HomepageMerchandising;
use Drupal\myeventlane_front\Service\HomepageRailDiversityFilter;
use Drupal\node\NodeInterface;
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

  /**
   * @var \Drupal\myeventlane_front\Service\HomepageMerchandising
   */
  private HomepageMerchandising $merchandising;

  /**
   * @var \Drupal\myeventlane_front\Service\HomepageRailDiversityFilter
   */
  private HomepageRailDiversityFilter $diversityFilter;

  /**
   * @var \Drupal\Core\Path\PathMatcherInterface
   */
  private PathMatcherInterface $pathMatcher;

  /**
   * @var \Drupal\myeventlane_event\Service\PublicEventVisibility
   */
  private PublicEventVisibility $publicVisibility;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    PopularEventsService $popular,
    EntityTypeManagerInterface $entity_type_manager,
    HomepageMerchandising $merchandising,
    HomepageRailDiversityFilter $diversity_filter,
    PathMatcherInterface $path_matcher,
    PublicEventVisibility $public_visibility,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->popular = $popular;
    $this->entityTypeManager = $entity_type_manager;
    $this->merchandising = $merchandising;
    $this->diversityFilter = $diversity_filter;
    $this->pathMatcher = $path_matcher;
    $this->publicVisibility = $public_visibility;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_analytics.popular_events'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_front.homepage_merchandising'),
      $container->get('myeventlane_front.homepage_rail_diversity'),
      $container->get('path.matcher'),
      $container->get('myeventlane_event.public_visibility'),
    );
  }

  public function defaultConfiguration(): array {
    return [
      'days' => 7,
      'limit' => 8,
      // Default is intentionally a best-guess; can be changed in block UI.
      // If this view mode doesn't exist, we fall back to 'teaser' at runtime.
      'view_mode' => 'compact_commerce',
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
      '#default_value' => (string) ($this->configuration['view_mode'] ?? 'compact_commerce'),
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
    $view_mode = (string) ($this->configuration['view_mode'] ?? 'compact_commerce');
    $title = (string) ($this->configuration['title'] ?? 'Popular this week');
    $show_going = (bool) ($this->configuration['show_going'] ?? TRUE);
    $onHomepage = $this->pathMatcher->isFrontPage();

    // Over-fetch on homepage so merchandising/diversity filters can still fill the rail.
    $fetchLimit = $onHomepage ? min(max($limit * 3, $limit), 24) : $limit;

    $rows = $this->popular->getPopularEventIds($days, $fetchLimit);
    if (empty($rows)) {
      return [
        '#markup' => '',
        '#cache' => [
          'max-age' => 900,
          'contexts' => ['languages:language_interface'],
        ],
      ];
    }

    $nids = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $raw = $row['nid'] ?? NULL;
      if (!is_numeric($raw)) {
        continue;
      }
      $nid = (int) $raw;
      if ($nid > 0) {
        $nids[] = $nid;
      }
    }
    $nids = array_values(array_unique($nids));
    if ($nids === []) {
      return [
        '#markup' => '',
        '#cache' => [
          'max-age' => 900,
          'contexts' => ['languages:language_interface'],
        ],
      ];
    }

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

    // CF-7D: Lifecycle/visibility eligibility. The rail must not surface past,
    // ended, cancelled, archived, unlisted, or otherwise non-public events.
    // Reuse the canonical owner (PublicEventVisibility::isPubliclyListable, which
    // composes EventStateResolver lifecycle + public-visibility checks) rather
    // than introducing a new eligibility system. Applied to the over-fetched set
    // before merchandising/diversity/limit, so ranking order and fetch limits are
    // preserved and the rail still fills from eligible events.
    $ordered_nodes = array_filter(
      $ordered_nodes,
      fn (NodeInterface $node): bool => $this->publicVisibility->isPubliclyListable($node)
    );
    $rows = array_values(array_filter(
      $rows,
      fn ($row): bool => is_array($row) && isset($ordered_nodes[(int) ($row['nid'] ?? 0)])
    ));

    if ($onHomepage) {
      $rows = $this->applyHomepageMerchandising($rows, $ordered_nodes);
      $rows = $this->applyHomepageDiversity($rows, $ordered_nodes, $limit);
    }
    else {
      $rows = array_slice($rows, 0, $limit);
    }

    if ($rows === []) {
      return [
        '#markup' => '',
        '#cache' => [
          'max-age' => 900,
          'contexts' => ['languages:language_interface'],
        ],
      ];
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
      $card['#mel_discovery_source'] = DiscoveryAttributionSources::SOURCE_HOMEPAGE_COMMUNITY_FAVOURITES;

      // Render-only wrapper adds "X going" without changing card templates.
      $wrapper = [
        '#type' => 'container',
        '#attributes' => [
          'class' => $onHomepage
            ? ['mel-popular-event', 'mel-popular-event--compact-row']
            : ['mel-popular-event'],
          'data-nid' => (string) $nid,
          'data-score' => (string) ((int) $row['score']),
        ],
        'card' => $card,
      ];

      // Homepage compact rows surface going inside the card; skip duplicate sibling label.
      if ($show_going && !$onHomepage) {
        $going = (int) ($row['going'] ?? 0);
        $event_node = $ordered_nodes[$nid] ?? NULL;
        if ($event_node instanceof NodeInterface
          && myeventlane_event_should_show_block_going($event_node, $going)) {
          $wrapper['going'] = [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => $this->t('@count going', ['@count' => $going]),
            '#attributes' => [
              'class' => ['mel-popular-event__going'],
            ],
          ];
        }
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
      '#cache' => [
        // Short TTL ensures RSVP changes are reflected even if RSVP entities/tags
        // are not perfectly tagged everywhere yet.
        'max-age' => 900,
        'contexts' => ['languages:language_interface'],
        'tags' => Cache::mergeTags(['node_list'], $node_tags),
      ],
    ];

    if ($title !== '') {
      $build['header'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $title,
        '#attributes' => ['class' => ['mel-popular-events-block__title']],
      ];
    }

    $build['list'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => $onHomepage
          ? ['mel-popular-events-block__grid', 'mel-popular-events-block__grid--compact', 'mel-event-grid']
          : ['mel-popular-events-block__grid', 'mel-event-grid'],
      ],
      'items' => $items,
    ];

    return $build;
  }

  /**
   * Removes events already shown in higher-priority homepage rails.
   *
   * @param array<int, array<string, mixed>> $rows
   * @param array<int, \Drupal\node\NodeInterface> $ordered_nodes
   *
   * @return array<int, array<string, mixed>>
   */
  private function applyHomepageMerchandising(array $rows, array $ordered_nodes): array {
    $exclude = array_flip($this->merchandising->getCommunityFavouritesExclusionNids());
    if ($exclude === []) {
      return $rows;
    }

    $filtered = [];
    foreach ($rows as $row) {
      $nid = (int) ($row['nid'] ?? 0);
      if ($nid < 1 || !isset($ordered_nodes[$nid]) || isset($exclude[$nid])) {
        continue;
      }
      $filtered[] = $row;
    }

    return $filtered;
  }

  /**
   * Applies homepage rail diversity to the popularity-ordered rows.
   *
   * @param array<int, array<string, mixed>> $rows
   * @param array<int, \Drupal\node\NodeInterface> $ordered_nodes
   *
   * @return array<int, array<string, mixed>>
   */
  private function applyHomepageDiversity(array $rows, array $ordered_nodes, int $limit): array {
    $rowsByNid = [];
    $nodesForDiversity = [];

    foreach ($rows as $row) {
      $nid = (int) ($row['nid'] ?? 0);
      if ($nid < 1 || !isset($ordered_nodes[$nid])) {
        continue;
      }
      $rowsByNid[$nid] = $row;
      $nodesForDiversity[] = $ordered_nodes[$nid];
    }

    if ($nodesForDiversity === []) {
      return [];
    }

    $diverseNodes = $this->diversityFilter->filterEventNodes($nodesForDiversity, $limit);
    $filteredRows = [];
    foreach ($diverseNodes as $node) {
      $nid = (int) $node->id();
      if (isset($rowsByNid[$nid])) {
        $filteredRows[] = $rowsByNid[$nid];
      }
    }

    return $filteredRows;
  }

}
