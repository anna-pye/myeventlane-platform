<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Cached queries for organiser playbook blog posts.
 */
final class PlaybookQueryService {

  private const CACHE_ID = 'myeventlane_front:featured_playbooks';

  private const MAX_FEATURED = 6;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Returns published playbook nodes for the Organiser Hub landing page.
   *
   * @return list<\Drupal\node\NodeInterface>
   */
  public function getFeaturedPlaybooks(int $limit = self::MAX_FEATURED): array {
    if ($limit < 1) {
      return [];
    }
    if ($limit > self::MAX_FEATURED) {
      $limit = self::MAX_FEATURED;
    }

    $cacheId = self::CACHE_ID . ':' . $limit;
    $cached = $this->cache->get($cacheId);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $this->loadNodesFromIds($cached->data);
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'blog_post')
      ->condition('status', 1)
      ->condition('field_playbook', 1);
    if ($this->entityTypeManager->getStorage('field_storage_config')->load('node.field_featured_playbook') !== NULL) {
      $query->sort('field_featured_playbook', 'DESC');
    }
    $nids = $query
      ->sort('field_publish_date', 'DESC')
      ->range(0, $limit)
      ->execute();

    $ids = is_array($nids) ? array_map('intval', array_values($nids)) : [];
    $this->cache->set($cacheId, $ids, CacheBackendInterface::CACHE_PERMANENT, [
      'node_list:blog_post',
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
