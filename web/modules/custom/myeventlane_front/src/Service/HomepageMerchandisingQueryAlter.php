<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\views\Plugin\views\query\QueryPluginBase;
use Drupal\views\ViewExecutable;

/**
 * Applies homepage hero exclusivity and cross-rail deduplication to Views.
 */
final class HomepageMerchandisingQueryAlter {

  public function __construct(
    private readonly HomepageMerchandising $merchandising,
  ) {}

  /**
   * Alters a View query when on the front page.
   */
  public function alter(ViewExecutable $view, QueryPluginBase $query): void {
    $viewId = $view->id();
    $displayId = $view->current_display ?? '';
    if (!is_string($viewId) || !is_string($displayId) || $displayId === '') {
      return;
    }

    if (!$this->merchandising->applies($viewId, $displayId)) {
      return;
    }

    if (!$query->ensureTable('node_field_data')) {
      return;
    }

    $exclude = $this->merchandising->getExclusionNids($viewId, $displayId);
    // Exclude promoted events that fail media quality on every homepage rail.
    $exclude = array_values(array_unique([
      ...$exclude,
      ...$this->merchandising->getIneligiblePromotedNids(),
    ]));
    if ($exclude === []) {
      return;
    }

    $alias = $query->getTableInfo('node_field_data')['alias'] ?? 'node_field_data';
    $query->addWhere(1, $alias . '.nid', $exclude, 'NOT IN');
  }

}
