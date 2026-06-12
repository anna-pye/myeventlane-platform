<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves related organiser hub content for blog articles.
 */
final class BlogRelatedContentService {

  private const MAX_RELATED_BLOG = 3;

  private const MAX_RELATED_HELP = 3;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * @return list<\Drupal\node\NodeInterface>
   */
  public function getRelatedBlogPosts(NodeInterface $node, int $limit = self::MAX_RELATED_BLOG): array {
    if ($node->bundle() !== 'blog_post' || !$node->isPublished() || $limit < 1) {
      return [];
    }
    if (!$node->hasField('field_organiser_category') || $node->get('field_organiser_category')->isEmpty()) {
      return [];
    }

    $categoryId = (int) $node->get('field_organiser_category')->target_id;
    if ($categoryId <= 0) {
      return [];
    }

    $cacheId = 'myeventlane_front:blog_related:' . $node->id() . ':' . $limit;
    $cached = $this->cache->get($cacheId);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $this->loadNodesFromIds($cached->data);
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'blog_post')
      ->condition('status', 1)
      ->condition('nid', (int) $node->id(), '<>')
      ->condition('field_organiser_category', $categoryId)
      ->sort('field_publish_date', 'DESC')
      ->range(0, $limit)
      ->execute();

    $ids = is_array($nids) ? array_map('intval', array_values($nids)) : [];
    $this->cache->set($cacheId, $ids, CacheBackendInterface::CACHE_PERMANENT, [
      'node:' . $node->id(),
      'node_list:blog_post',
      'taxonomy_term:' . $categoryId,
    ]);

    return $this->loadNodesFromIds($ids);
  }

  /**
   * @return list<\Drupal\node\NodeInterface>
   */
  public function getRelatedHelpArticles(NodeInterface $node, int $limit = self::MAX_RELATED_HELP): array {
    if ($node->bundle() !== 'blog_post' || !$node->isPublished() || $limit < 1) {
      return [];
    }

    $keyword = '';
    if ($node->hasField('field_organiser_category') && !$node->get('field_organiser_category')->isEmpty()) {
      $term = $node->get('field_organiser_category')->entity;
      if ($term !== NULL) {
        $keyword = trim((string) $term->label());
      }
    }

    $cacheId = 'myeventlane_front:blog_help_related:' . $node->id() . ':' . md5($keyword) . ':' . $limit;
    $cached = $this->cache->get($cacheId);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $this->loadNodesFromIds($cached->data);
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'help_article')
      ->condition('status', 1)
      ->condition('field_audience', ['public', 'vendor'], 'IN');

    if ($keyword !== '' && $storage->getEntityType()->getKey('bundle') !== NULL) {
      $or = $query->orConditionGroup()
        ->condition('field_help_keywords', '%' . $keyword . '%', 'LIKE')
        ->condition('title', '%' . $keyword . '%', 'LIKE');
      $query->condition($or);
    }

    $nids = $query
      ->sort('changed', 'DESC')
      ->range(0, $limit)
      ->execute();

    $ids = is_array($nids) ? array_map('intval', array_values($nids)) : [];
    $this->cache->set($cacheId, $ids, CacheBackendInterface::CACHE_PERMANENT, [
      'node:' . $node->id(),
      'node_list:help_article',
    ]);

    return $this->loadNodesFromIds($ids);
  }

  /**
   * @param list<int> $ids
   * @return list<\Drupal\node\NodeInterface>
   */
  private function loadNodesFromIds(array $ids): array {
    if ($ids === []) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $nodes = $storage->loadMultiple($ids);
    if (!is_array($nodes)) {
      return [];
    }

    $out = [];
    foreach ($ids as $nid) {
      if (isset($nodes[$nid]) && $nodes[$nid] instanceof NodeInterface) {
        $out[] = $nodes[$nid];
      }
    }
    return $out;
  }

}
