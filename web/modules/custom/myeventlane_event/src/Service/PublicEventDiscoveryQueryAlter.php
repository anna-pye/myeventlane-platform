<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\myeventlane_event_state\Service\EventStateResolver;
use Drupal\views\Plugin\views\query\QueryPluginBase;
use Drupal\views\ViewExecutable;

/**
 * Applies canonical public discovery filters to whitelisted Views queries.
 *
 * Keeps admin/vendor dashboard Views unchanged by display ID allow-listing.
 */
final class PublicEventDiscoveryQueryAlter {

  /**
   * Public discovery Views and display IDs that receive hygiene filters.
   *
   * @var array<string, list<string>>
   */
  private const PUBLIC_DISCOVERY_DISPLAYS = [
    'upcoming_events' => [
      'page_events',
      'page_free',
      'page_today',
      'page_this_weekend',
      'page_popular',
      'page_category',
      'homepage_latest',
      'homepage_tonight',
    ],
    'front_featured_events' => [
      'block_featured',
    ],
    'mel_home_events' => [
      'discover',
      'tonight',
      'under_20',
      'near_you',
    ],
    'featured_events' => [
      'block_spotlight',
      'block_featured',
    ],
    'featured_events_carousel' => [
      'featured_events_carousel',
    ],
    'front_discover_events' => [
      'block_discover',
    ],
    'front_recommended_events' => [
      'block_1',
    ],
    'featured_discover_recommended' => [
      'default',
      'block_1',
    ],
    'new_events' => [
      'block_1',
      'page_1',
    ],
    'events_calendar' => [
      'page_1',
      'block_1',
    ],
  ];

  /**
   * Displays that must only list RSVP/free events (no paid ticket commerce).
   *
   * @var array<string, list<string>>
   */
  private const FREE_RSVP_DISPLAYS = [
    'upcoming_events' => [
      'page_free',
    ],
    'mel_home_events' => [
      'under_20',
    ],
  ];

  public function __construct(
    private readonly PublicEventVisibility $publicVisibility,
  ) {}

  /**
   * Whether hygiene filters should apply to this View display.
   */
  public function applies(ViewExecutable $view): bool {
    $view_id = $view->id();
    $display_id = $view->current_display ?? '';

    if (!is_string($view_id) || !is_string($display_id) || $display_id === '') {
      return FALSE;
    }

    return in_array($display_id, self::PUBLIC_DISCOVERY_DISPLAYS[$view_id] ?? [], TRUE);
  }

  /**
   * Alters the View SQL query for public discovery hygiene.
   */
  public function alter(ViewExecutable $view, QueryPluginBase $query): void {
    if (!$this->applies($view)) {
      return;
    }

    if (!$query->ensureTable('node_field_data')) {
      return;
    }

    $base_alias = $query->getTableInfo('node_field_data')['alias'] ?? 'node_field_data';

    $this->applyEndedStateExclusion($query);
    $this->applyInternalTitleExclusion($query, $base_alias);

    if ($this->isFreeRsvpDisplay($view)) {
      $this->applyFreeRsvpExclusion($query);
    }
  }

  /**
   * Whether this display is the Free & RSVP surface.
   */
  private function isFreeRsvpDisplay(ViewExecutable $view): bool {
    $view_id = $view->id();
    $display_id = $view->current_display ?? '';
    if (!is_string($view_id) || !is_string($display_id)) {
      return FALSE;
    }

    return in_array($display_id, self::FREE_RSVP_DISPLAYS[$view_id] ?? [], TRUE);
  }

  /**
   * Excludes lifecycle state "ended" (Views config often omits this value).
   */
  private function applyEndedStateExclusion(QueryPluginBase $query): void {
    $state_alias = $query->ensureTable('node__field_event_state');
    if (!$state_alias) {
      return;
    }

    $group = 1;
    $field = $state_alias . '.field_event_state_value';
    $query->addWhereExpression(
      $group,
      "($field IS NULL OR $field = '' OR $field <> '" . EventStateResolver::STATE_ENDED . "')"
    );
  }

  /**
   * Excludes obvious internal staging titles when no better field exists.
   */
  private function applyInternalTitleExclusion(QueryPluginBase $query, string $base_alias): void {
    $title_field = $base_alias . '.title';
    $patterns = $this->publicVisibility->getInternalTitleSqlLikePatterns();
    if ($patterns === []) {
      return;
    }

    $group = 1;
    foreach ($patterns as $idx => $pattern) {
      $placeholder = ':mel_title_pattern_' . $idx;
      $query->addWhereExpression(
        $group,
        "LOWER($title_field) NOT LIKE $placeholder",
        [$placeholder => mb_strtolower($pattern)]
      );
    }
  }

  /**
   * Ensures paid ticket events do not appear on Free & RSVP listings.
   */
  private function applyFreeRsvpExclusion(QueryPluginBase $query): void {
    $type_alias = $query->ensureTable('node__field_event_type');
    if (!$type_alias) {
      return;
    }

    $group = 1;
    $type_field = $type_alias . '.field_event_type_value';
    $query->addWhere($group, $type_field, 'paid', '<>');

    $product_alias = $query->ensureTable('node__field_product_target');
    if (!$product_alias) {
      return;
    }

    $product_field = $product_alias . '.field_product_target_target_id';
    $query->addWhereExpression(
      $group,
      "($type_field <> 'both' OR $product_field IS NULL OR $product_field = 0)"
    );
  }

}
