<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\myeventlane_analytics\Service\PopularEventsService;
use Drupal\views\Plugin\views\query\QueryPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\ViewExecutable;

/**
 * Applies homepage hero exclusivity and cross-rail deduplication to Views.
 *
 * Also constrains upcoming_events:page_popular to PopularEventsService ranking.
 */
final class HomepageMerchandisingQueryAlter {

  public function __construct(
    private readonly HomepageMerchandising $merchandising,
    private readonly PopularEventsService $popularEvents,
  ) {}

  /**
   * Alters a View query for homepage merchandising and media quality gate.
   */
  public function alter(ViewExecutable $view, QueryPluginBase $query): void {
    $this->applyCommunityFavouritesBrowseRanking($view, $query);

    $viewId = $view->id();
    $displayId = $view->current_display ?? '';
    if (!is_string($viewId) || !is_string($displayId) || $displayId === '') {
      return;
    }

    $applyMerchandising = $this->merchandising->applies($viewId, $displayId);
    $applyQualityGate = $this->merchandising->appliesQualityGate($viewId, $displayId);
    if (!$applyMerchandising && !$applyQualityGate) {
      return;
    }

    if (!$query->ensureTable('node_field_data')) {
      return;
    }

    $exclude = $applyMerchandising
      ? $this->merchandising->getExclusionNids($viewId, $displayId)
      : [];
    if ($applyMerchandising || $applyQualityGate) {
      $exclude = array_values(array_unique([
        ...$exclude,
        ...$this->merchandising->getIneligiblePromotedNids(),
      ]));
    }
    if ($exclude === []) {
      return;
    }

    $alias = $query->getTableInfo('node_field_data')['alias'] ?? 'node_field_data';
    $query->addWhere(1, $alias . '.nid', $exclude, 'NOT IN');
  }

  /**
   * Restricts Community Favourites browse to engagement-ranked upcoming events.
   */
  private function applyCommunityFavouritesBrowseRanking(ViewExecutable $view, QueryPluginBase $query): void {
    if ($view->id() !== 'upcoming_events' || ($view->current_display ?? '') !== 'page_popular') {
      return;
    }

    if (!$query->ensureTable('node_field_data')) {
      return;
    }

    $alias = $query->getTableInfo('node_field_data')['alias'] ?? 'node_field_data';
    // Default 7-day lookback (same as homepage Community Favourites rail).
    $rows = $this->popularEvents->getPopularEventRows(7);

    $nids = [];
    foreach ($rows as $row) {
      $nid = (int) ($row['nid'] ?? 0);
      if ($nid > 0) {
        $nids[] = $nid;
      }
    }

    if ($nids === []) {
      $query->addWhere(1, $alias . '.nid', 0);
      return;
    }

    $query->addWhere(1, $alias . '.nid', $nids, 'IN');

    // Preserve engagement rank across pager pages (Views-owned pagination).
    if ($query instanceof Sql) {
      $query->orderby = [];
      $nid_list = implode(', ', array_map('intval', $nids));
      $query->addOrderBy(NULL, "FIELD($alias.nid, $nid_list)", 'ASC', 'mel_community_favourites_rank');
    }

    // Fallback for pre_render reordering when SQL ordering is unavailable.
    $rank = [];
    foreach ($nids as $index => $nid) {
      $rank[$nid] = $index;
    }
    $view->mel_community_favourites_rank = $rank;
  }

}
