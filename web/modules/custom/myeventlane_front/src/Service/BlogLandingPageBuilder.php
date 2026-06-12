<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Builds the public blog landing page sections for /blog.
 */
final class BlogLandingPageBuilder {

  use StringTranslationTrait;

  private const CACHE_ID = 'myeventlane_front:blog_landing_page';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BlogAudienceFilterService $audienceFilter,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function build(): array {
    $cached = $this->cache->get(self::CACHE_ID);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $built = [
      'hero' => [
        'title' => (string) $this->t('Stories, guides and ideas from MyEventLane'),
        'subtitle' => (string) $this->t('Discover events, meet organisers, and learn how communities come together.'),
        'primary_cta' => [
          'title' => (string) $this->t('Browse events'),
          'url' => Url::fromRoute('view.upcoming_events.page_events')->toString(),
        ],
        'secondary_cta' => [
          'title' => (string) $this->t('Organiser Hub'),
          'url' => Url::fromRoute('myeventlane_front.organiser_hub')->toString(),
        ],
      ],
      'featured' => $this->buildArticleCards(3),
      'audiences' => $this->buildAudienceCards(),
      'topics' => $this->buildTopicCards(),
      'latest' => $this->buildArticleCards(9),
      'filters' => $this->buildFilters(),
    ];

    $this->cache->set(self::CACHE_ID, $built, CacheBackendInterface::CACHE_PERMANENT, [
      'node_list:blog_post',
      'taxonomy_term_list:tags',
      'taxonomy_term_list:organiser_hub_categories',
    ]);

    return $built;
  }

  /**
   * @return array{categories: list<array{title: string, tid: string}>, tags: list<array{title: string, tid: string}>, audiences: list<array{title: string, value: string}>, action: string}
   */
  public function buildFilters(): array {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $categories = [];
    $organiserTerms = $termStorage->loadByProperties(['vid' => 'organiser_hub_categories']);
    if (is_array($organiserTerms)) {
      foreach ($organiserTerms as $term) {
        if (!$term instanceof TermInterface) {
          continue;
        }
        $categories[] = [
          'title' => $term->label(),
          'tid' => (string) $term->id(),
        ];
      }
      usort($categories, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));
    }

    $tags = [];
    $tagTerms = $termStorage->loadByProperties(['vid' => 'tags']);
    if (is_array($tagTerms)) {
      foreach ($tagTerms as $term) {
        if (!$term instanceof TermInterface) {
          continue;
        }
        $tags[] = [
          'title' => $term->label(),
          'tid' => (string) $term->id(),
        ];
      }
      usort($tags, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));
    }

    return [
      'categories' => $categories,
      'tags' => $tags,
      'audiences' => [
        ['title' => (string) $this->t('Attendee'), 'value' => 'attendee'],
        ['title' => (string) $this->t('Organiser'), 'value' => 'organiser'],
        ['title' => (string) $this->t('Both'), 'value' => 'both'],
      ],
      'action' => Url::fromRoute('myeventlane_front.blog_landing')->toString(),
    ];
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildAudienceCards(): array {
    $cards = [];
    foreach ([
      'attendee' => (string) $this->t('Attendee stories'),
      'organiser' => (string) $this->t('Organiser stories'),
      'both' => (string) $this->t('For everyone'),
    ] as $value => $title) {
      $count = $this->countByAudience($value);
      $cards[] = [
        'title' => $title,
        'slug' => $value,
        'count' => $count,
        'count_label' => (string) $this->formatPlural($count, '1 article', '@count articles'),
        'url' => Url::fromRoute('myeventlane_front.blog_landing', [], [
          'query' => ['audience' => $value],
        ])->toString(),
      ];
    }
    return $cards;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildTopicCards(): array {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $cards = [];

    $terms = $termStorage->loadByProperties(['vid' => 'tags']);
    if (!is_array($terms)) {
      return $cards;
    }

    foreach ($terms as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }
      $count = (int) $nodeStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'blog_post')
        ->condition('status', 1)
        ->condition('field_tags', $term->id())
        ->count()
        ->execute();
      if ($count === 0) {
        continue;
      }
      $cards[] = [
        'title' => $term->label(),
        'slug' => Html::getClass($term->label()),
        'count' => $count,
        'count_label' => (string) $this->formatPlural($count, '1 article', '@count articles'),
        'url' => Url::fromRoute('myeventlane_front.blog_landing', [], [
          'query' => ['tags' => (string) $term->id()],
        ])->toString(),
      ];
    }

    usort($cards, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));
    return $cards;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildArticleCards(int $limit): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'blog_post')
      ->condition('status', 1)
      ->sort('field_publish_date', 'DESC')
      ->range(0, $limit)
      ->execute();

    $cards = [];
    if (!is_array($nids)) {
      return $cards;
    }
    $viewBuilder = $this->entityTypeManager->getViewBuilder('node');
    foreach ($storage->loadMultiple($nids) as $node) {
      if ($node instanceof NodeInterface) {
        $cards[] = $viewBuilder->view($node, 'teaser');
      }
    }
    return $cards;
  }

  private function countByAudience(string $audience): int {
    if (!$this->entityTypeManager->getStorage('node')->getEntityType()->hasKey('bundle')) {
      return 0;
    }
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'blog_post')
      ->condition('status', 1);
    $this->audienceFilter->applyToEntityQuery($query, $audience);
    return (int) $query->count()->execute();
  }

}
