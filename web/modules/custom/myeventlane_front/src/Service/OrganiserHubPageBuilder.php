<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Builds premium Organiser Hub landing page sections for /resources.
 */
final class OrganiserHubPageBuilder {

  use StringTranslationTrait;

  private const CACHE_ID = 'myeventlane_front:organiser_hub_page';

  public function __construct(
    private readonly PlaybookQueryService $playbookQuery,
    private readonly OrganiserHubEditorialService $editorial,
    private readonly BlogResourceDownloadBuilder $resourceDownloads,
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
        'title' => (string) $this->t('Everything you need to run a successful event'),
        'subtitle' => (string) $this->t('Playbooks, templates, guides and practical advice for community organisers.'),
        'primary_cta' => [
          'title' => (string) $this->t('Create event'),
          'url' => Url::fromRoute('myeventlane_vendor.create_event_gateway')->toString(),
        ],
        'secondary_cta' => [
          'title' => (string) $this->t('Browse playbooks'),
          'url' => '#mel-organiser-hub-playbooks',
        ],
      ],
      'featured_playbooks' => $this->buildFeaturedPlaybookCards(),
      'topics' => $this->buildTopicCards(),
      'downloads' => $this->buildDownloadCards(),
      'latest_guides' => $this->buildLatestGuides(),
      'filters' => $this->buildFilters(),
      'footer_cta' => [
        'title' => (string) $this->t('Ready to put your plan into action?'),
        'body' => (string) $this->t('Create your event on MyEventLane and start reaching your community.'),
        'url' => Url::fromRoute('myeventlane_vendor.create_event_gateway')->toString(),
        'button' => (string) $this->t('Create your event'),
      ],
    ];

    $this->cache->set(self::CACHE_ID, $built, CacheBackendInterface::CACHE_PERMANENT, [
      'node_list:blog_post',
      'taxonomy_term_list:organiser_hub_categories',
    ]);

    return $built;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildFeaturedPlaybookCards(): array {
    $cards = [];
    foreach ($this->playbookQuery->getFeaturedPlaybooks() as $node) {
      $cards[] = $this->buildGuideCard($node, TRUE);
    }
    return $cards;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildTopicCards(): array {
    $cards = [];
    foreach ($this->editorial->getCategoryCoverage() as $row) {
      $cards[] = [
        'title' => $row['title'],
        'slug' => $row['slug'],
        'count' => $row['article_count'],
        'count_label' => (string) $this->formatPlural(
          $row['article_count'],
          '1 guide',
          '@count guides',
        ),
        'url' => Url::fromRoute('myeventlane_front.blog_landing', [], [
          'query' => ['category' => (string) $row['term_id']],
        ])->toString(),
      ];
    }
    return $cards;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildDownloadCards(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'blog_post')
      ->condition('status', 1)
      ->condition('field_playbook', 1)
      ->sort('changed', 'DESC')
      ->range(0, 12)
      ->execute();

    $cards = [];
    if (!is_array($nids)) {
      return $cards;
    }

    foreach ($storage->loadMultiple($nids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      foreach ($this->resourceDownloads->build($node) as $download) {
        $cards[] = array_merge($download, [
          'source_title' => $node->label(),
          'source_url' => $node->toUrl()->toString(),
        ]);
        if (count($cards) >= 9) {
          break 2;
        }
      }
    }

    return $cards;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildLatestGuides(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'blog_post')
      ->condition('status', 1)
      ->sort('field_publish_date', 'DESC')
      ->range(0, 6)
      ->execute();

    $cards = [];
    if (!is_array($nids)) {
      return $cards;
    }
    foreach ($storage->loadMultiple($nids) as $node) {
      if ($node instanceof NodeInterface) {
        $cards[] = $this->buildGuideCard($node, (bool) $node->get('field_playbook')->value);
      }
    }
    return $cards;
  }

  /**
   * @return array{categories: list<array{title: string, url: string}>, action: string}
   */
  private function buildFilters(): array {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $termStorage->loadByProperties(['vid' => 'organiser_hub_categories']);
    $categories = [];
    if (is_array($terms)) {
      foreach ($terms as $term) {
        if (!$term instanceof TermInterface) {
          continue;
        }
        $categories[] = [
          'title' => $term->label(),
          'tid' => (string) $term->id(),
          'url' => Url::fromRoute('myeventlane_front.blog_landing', [], [
            'query' => ['category' => (string) $term->id()],
          ])->toString(),
        ];
      }
      usort($categories, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));
    }

    return [
      'categories' => $categories,
      'action' => Url::fromRoute('myeventlane_front.blog_landing')->toString(),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildGuideCard(NodeInterface $node, bool $isPlaybook): array {
    $excerpt = '';
    if ($node->hasField('field_excerpt') && !$node->get('field_excerpt')->isEmpty()) {
      $excerpt = trim((string) $node->get('field_excerpt')->value);
    }
    $readingTime = $this->editorial->estimateReadingTime($node);

    return [
      'title' => $node->label(),
      'url' => $node->toUrl()->toString(),
      'excerpt' => $excerpt,
      'is_playbook' => $isPlaybook,
      'reading_time' => $readingTime['label'] ?? NULL,
      'download_count' => $this->editorial->getDownloadCount($node),
      'download_label' => (string) $this->formatPlural(
        $this->editorial->getDownloadCount($node),
        '1 download',
        '@count downloads',
      ),
    ];
  }

}
