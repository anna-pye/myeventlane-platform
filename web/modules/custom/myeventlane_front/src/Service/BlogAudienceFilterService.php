<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\views\Plugin\views\query\QueryPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\ViewExecutable;

/**
 * Shared audience matching for blog landing counts and filtered listings.
 */
final class BlogAudienceFilterService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Applies audience conditions to an entity query for published blog posts.
   */
  public function applyToEntityQuery(QueryInterface $query, string $audience): void {
    if ($audience === 'both') {
      $query->condition('field_blog_audience', 'both');
      return;
    }

    if ($audience === 'attendee') {
      $or = $query->orConditionGroup()
        ->condition('field_blog_audience', 'attendee')
        ->condition('field_blog_audience', 'both');
      if ($this->audienceFieldExists()) {
        $or->notExists('field_blog_audience.value');
      }
      $query->condition($or);
      return;
    }

    $or = $query->orConditionGroup()
      ->condition('field_blog_audience', 'organiser')
      ->condition('field_blog_audience', 'both');
    $query->condition($or);
  }

  /**
   * Aligns mel_blog exposed audience filters with landing card count rules.
   */
  public function alterMelBlogView(ViewExecutable $view, QueryPluginBase $query): void {
    if ($view->id() !== 'mel_blog' || !$query instanceof Sql) {
      return;
    }

    $exposed = $view->getExposedInput();
    $audience = is_array($exposed) && isset($exposed['audience']) && is_string($exposed['audience'])
      ? trim($exposed['audience'])
      : '';
    if ($audience === '' || !in_array($audience, ['attendee', 'organiser', 'both'], TRUE)) {
      return;
    }

    $this->removeAudienceWhereConditions($query);

    $alias = $query->ensureTable('node__field_blog_audience');
    if (!$alias) {
      return;
    }

    $field = $alias . '.field_blog_audience_value';
    $group = 1;

    if ($audience === 'both') {
      $query->addWhere($group, $field, 'both');
      return;
    }

    if ($audience === 'attendee') {
      $query->addWhereExpression(
        $group,
        "($field IN (:audience_attendee, :audience_both) OR $field IS NULL OR $field = '')",
        [
          ':audience_attendee' => 'attendee',
          ':audience_both' => 'both',
        ],
      );
      return;
    }

    $query->addWhere($group, $field, ['organiser', 'both'], 'IN');
  }

  private function removeAudienceWhereConditions(Sql $query): void {
    foreach ($query->where as $group => &$condition_group) {
      if (!isset($condition_group['conditions']) || !is_array($condition_group['conditions'])) {
        continue;
      }
      foreach ($condition_group['conditions'] as $key => $condition) {
        $field = $condition['field'] ?? '';
        if (is_string($field) && str_contains($field, 'field_blog_audience')) {
          unset($condition_group['conditions'][$key]);
        }
      }
      if ($condition_group['conditions'] === []) {
        unset($query->where[$group]);
      }
    }
  }

  private function audienceFieldExists(): bool {
    return $this->entityTypeManager->getStorage('field_storage_config')->load('node.field_blog_audience') !== NULL;
  }

}
