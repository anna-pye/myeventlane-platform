<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Editorial completeness, coverage, and linking audit helpers for organiser hub.
 */
final class OrganiserHubEditorialService {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BlogRelatedContentService $relatedContent,
    private readonly BlogResourceDownloadBuilder $resourceDownloads,
  ) {}

  /**
   * @return list<string>
   */
  public function getCompletenessWarnings(NodeInterface $node): array {
    if ($node->bundle() !== 'blog_post') {
      return [];
    }

    $warnings = [];
    if ($node->hasField('field_blog_hero_image') && $node->get('field_blog_hero_image')->isEmpty()) {
      $warnings[] = (string) $this->t('Hero image missing');
    }
    if ($node->hasField('field_excerpt') && $node->get('field_excerpt')->isEmpty()) {
      $warnings[] = (string) $this->t('Excerpt missing');
    }
    if ($node->hasField('field_seo_description') && $node->get('field_seo_description')->isEmpty()) {
      $warnings[] = (string) $this->t('SEO description missing');
    }
    if ($node->hasField('field_organiser_category') && $node->get('field_organiser_category')->isEmpty()) {
      $warnings[] = (string) $this->t('Category missing');
    }
    if ($node->hasField('field_playbook') && (bool) $node->get('field_playbook')->value) {
      if ($this->getDownloadCount($node) === 0) {
        $warnings[] = (string) $this->t('Download missing');
      }
    }

    return $warnings;
  }

  public function getDownloadCount(NodeInterface $node): int {
    return count($this->resourceDownloads->build($node));
  }

  /**
   * @return list<array{
   *   term_id: int,
   *   title: string,
   *   slug: string,
   *   playbook_count: int,
   *   article_count: int,
   *   download_count: int,
   *   gap: bool
   * }>
   */
  public function getCategoryCoverage(): array {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $termStorage->loadByProperties(['vid' => 'organiser_hub_categories']);
    if (!is_array($terms) || $terms === []) {
      return [];
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $coverage = [];
    foreach ($terms as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }
      $tid = (int) $term->id();
      $baseQuery = $nodeStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'blog_post')
        ->condition('status', 1)
        ->condition('field_organiser_category', $tid);

      $articleCount = (int) $baseQuery->count()->execute();
      $playbookCount = (int) $nodeStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'blog_post')
        ->condition('status', 1)
        ->condition('field_organiser_category', $tid)
        ->condition('field_playbook', 1)
        ->count()
        ->execute();

      $downloadCount = 0;
      $nids = $nodeStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'blog_post')
        ->condition('status', 1)
        ->condition('field_organiser_category', $tid)
        ->execute();
      if (is_array($nids)) {
        $nodes = $nodeStorage->loadMultiple($nids);
        foreach ($nodes as $node) {
          if ($node instanceof NodeInterface) {
            $downloadCount += $this->getDownloadCount($node);
          }
        }
      }

      $coverage[] = [
        'term_id' => $tid,
        'title' => $term->label(),
        'slug' => $this->slugify((string) $term->label()),
        'playbook_count' => $playbookCount,
        'article_count' => $articleCount,
        'download_count' => $downloadCount,
        'gap' => $playbookCount === 0 || $downloadCount === 0,
      ];
    }

    usort($coverage, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));
    return $coverage;
  }

  /**
   * @return list<string>
   */
  public function getLinkingIssues(NodeInterface $node): array {
    if ($node->bundle() !== 'blog_post') {
      return [];
    }

    $issues = [];
    if ($this->relatedContent->getRelatedBlogPosts($node, 1) === []) {
      $issues[] = (string) $this->t('No related articles');
    }
    if ($node->hasField('field_playbook') && (bool) $node->get('field_playbook')->value && $this->getDownloadCount($node) === 0) {
      $issues[] = (string) $this->t('Playbook has no downloads');
    }

    return $issues;
  }

  /**
   * @return list<array{tid: int, title: string, slug: string, count: int, url: string}>
   */
  public function getLowVolumeCategories(int $threshold = 2): array {
    $low = [];
    foreach ($this->getCategoryCoverage() as $row) {
      if ($row['article_count'] <= $threshold) {
        $low[] = [
          'tid' => $row['term_id'],
          'title' => $row['title'],
          'slug' => $row['slug'],
          'count' => $row['article_count'],
          'url' => '/blog?category=' . $row['term_id'],
        ];
      }
    }
    return $low;
  }

  /**
   * Builds a short badge label for blog card teasers from existing node metadata.
   */
  public function buildBlogCardBadge(NodeInterface $node): ?string {
    if ($node->bundle() !== 'blog_post') {
      return NULL;
    }
    if ($node->hasField('field_playbook') && (bool) $node->get('field_playbook')->value) {
      return (string) $this->t('Playbook');
    }
    if ($node->hasField('field_organiser_category') && !$node->get('field_organiser_category')->isEmpty()) {
      $term = $node->get('field_organiser_category')->entity;
      if ($term instanceof TermInterface) {
        $title = trim((string) $term->label());
        if ($title !== '') {
          return $title;
        }
      }
    }
    if ($node->hasField('field_blog_audience') && !$node->get('field_blog_audience')->isEmpty()) {
      return match ((string) $node->get('field_blog_audience')->value) {
        'organiser' => (string) $this->t('Organiser'),
        'both' => (string) $this->t('For everyone'),
        default => (string) $this->t('Attendee'),
      };
    }
    return NULL;
  }

  /**
   * @return array{minutes: int, label: string}|null
   */
  public function estimateReadingTime(NodeInterface $node): ?array {
    $text = '';
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $text = (string) $node->get('body')->value;
    }
    $plain = Html::decodeEntities(strip_tags($text));
    $words = preg_split('/\s+/u', trim($plain), -1, PREG_SPLIT_NO_EMPTY);
    $count = is_array($words) ? count($words) : 0;
    if ($count === 0) {
      return NULL;
    }
    $minutes = max(1, (int) ceil($count / 200));
    return [
      'minutes' => $minutes,
      'label' => (string) $this->formatPlural($minutes, '1 min read', '@count min read'),
    ];
  }

  private function slugify(string $label): string {
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label) ?? '');
    return trim($slug, '_');
  }

}
