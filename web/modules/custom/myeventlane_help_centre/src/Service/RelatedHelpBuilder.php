<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Builds a short, audience-matched list rather than a generic article feed.
 */
final class RelatedHelpBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly HelpArticleBrowsePolicy $policy,
    private readonly AliasManagerInterface $aliasManager,
  ) {}

  /**
   * Builds the access-aware help content.
   */
  public function build(NodeInterface $source): array {
    $build = [];
    $cache = CacheableMetadata::createFromObject($source)
      ->addCacheTags(['node_list:help_article', 'path_alias_list'])
      ->addCacheContexts(['user.permissions', 'user.node_grants:view']);
    $audiences = array_column($source->get('field_audience')->getValue(), 'value');
    $audiences = array_intersect($audiences, $this->policy->allowedAudienceValues($this->currentUser));
    if (!$audiences) {
      $cache->applyTo($build);
      return $build;
    }
    $sourceAlias = $this->aliasManager->getAliasByPath('/node/' . $source->id());
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'help_article')->condition('status', 1)
      ->condition('nid', $source->id(), '<>')
      ->condition('field_audience', array_values($audiences), 'IN')
      ->condition('field_help_status', $this->policy->allowedHelpStatusesForBrowse($this->currentUser), 'IN');
    $explicit = $source->hasField('field_related_help_articles')
      ? array_column($source->get('field_related_help_articles')->getValue(), 'target_id') : [];
    if ($explicit) {
      $query->condition('nid', $explicit, 'IN');
    }
    else {
      $field = !$source->get('field_help_topic')->isEmpty() ? 'field_help_topic' : 'field_help_category';
      $terms = array_column($source->get($field)->getValue(), 'target_id');
      if (!$terms) {
        $cache->applyTo($build);
        return $build;
      }
      $query->condition($field, $terms, 'IN');
    }
    // Check entity access as well as query grants before rendering anything.
    foreach ($storage->loadMultiple($query->sort('title')->range(0, 12)->execute()) as $node) {
      $access = $node->access('view', $this->currentUser, TRUE);
      $cache->addCacheableDependency($node)->addCacheableDependency($access);
      $alias = $this->aliasManager->getAliasByPath('/node/' . $node->id());
      $otherAudience = (str_starts_with($sourceAlias, '/help/attendees/') && str_starts_with($alias, '/help/organisers/'))
        || (str_starts_with($sourceAlias, '/help/organisers/') && str_starts_with($alias, '/help/attendees/'));
      if ($access->isAllowed() && !$otherAudience) {
        $build[] = $this->entityTypeManager->getViewBuilder('node')->view($node, 'teaser');
        if (count($build) === 3) {
          break;
        }
      }
    }
    $cache->applyTo($build);
    return $build;
  }

}
