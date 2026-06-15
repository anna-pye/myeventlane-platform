<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\node\NodeInterface;
use Drupal\views\ViewExecutable;

/**
 * Optional post-query diversity for homepage discovery rails.
 *
 * Limits repeated category, venue, and organiser dominance without SQL changes.
 */
final class HomepageRailDiversityFilter {

  /**
   * View displays that receive diversity filtering (not hero/spotlight).
   *
   * @var array<string, list<string>>
   */
  private const DIVERSITY_DISPLAYS = [
    'mel_home_events' => ['embed_discover', 'under_20'],
    'upcoming_events' => ['homepage_tonight', 'homepage_hidden_gems', 'homepage_latest'],
    'front_recommended_events' => ['block_1'],
  ];

  public function __construct(
    private readonly HomepageMerchandising $merchandising,
  ) {}

  /**
   * Whether diversity filtering applies to this View display.
   */
  public function applies(ViewExecutable $view): bool {
    if (!$this->merchandising->applies($view->id(), $view->current_display)) {
      return FALSE;
    }

    return in_array(
      $view->current_display,
      self::DIVERSITY_DISPLAYS[$view->id()] ?? [],
      TRUE,
    );
  }

  /**
   * Applies diversity filtering to an ordered event node list (non-Views rails).
   *
   * @param list<\Drupal\node\NodeInterface> $nodes
   *   Events in rank order.
   * @param int $targetCount
   *   Desired result count; backfills from deferred rows when diversity is thin.
   *
   * @return list<\Drupal\node\NodeInterface>
   */
  public function filterEventNodes(array $nodes, int $targetCount): array {
    $targetCount = max(1, $targetCount);
    if ($nodes === []) {
      return [];
    }
    if (count($nodes) <= 1) {
      return array_slice($nodes, 0, $targetCount);
    }

    $preferred = [];
    $deferred = [];

    foreach ($nodes as $node) {
      if ($this->passesDiversityForNode($node, $preferred)) {
        $preferred[] = $node;
      }
      else {
        $deferred[] = $node;
      }
    }

    foreach ($deferred as $node) {
      if (count($preferred) >= $targetCount) {
        break;
      }
      $preferred[] = $node;
    }

    return array_slice($preferred, 0, $targetCount);
  }

  /**
   * Filters view results to improve category/venue/organiser spread.
   *
   * Prefers diverse rows first but backfills so rails keep their query count.
   */
  public function filter(ViewExecutable $view): void {
    if (!$this->applies($view) || empty($view->result)) {
      return;
    }

    $original = $view->result;
    $targetCount = count($original);
    if ($targetCount <= 1) {
      return;
    }

    $preferred = [];
    $deferred = [];

    foreach ($original as $row) {
      if ($this->passesDiversity($row, $preferred)) {
        $preferred[] = $row;
      }
      else {
        $deferred[] = $row;
      }
    }

    foreach ($deferred as $row) {
      if (count($preferred) >= $targetCount) {
        break;
      }
      $preferred[] = $row;
    }

    $view->result = $preferred;
  }

  /**
   * Whether a row can join the preferred set without repeating organiser/category/venue.
   */
  private function passesDiversity(mixed $row, array $preferred): bool {
    $entity = $row->_entity ?? NULL;
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'event') {
      return TRUE;
    }

    $preferredNodes = [];
    foreach ($preferred as $preferredRow) {
      $preferredEntity = $preferredRow->_entity ?? NULL;
      if ($preferredEntity instanceof NodeInterface) {
        $preferredNodes[] = $preferredEntity;
      }
    }

    return $this->passesDiversityForNode($entity, $preferredNodes);
  }

  /**
   * Whether an event can join the preferred set without repeating organiser/category/venue.
   *
   * @param list<\Drupal\node\NodeInterface> $preferred
   */
  private function passesDiversityForNode(NodeInterface $entity, array $preferred): bool {
    if ($entity->bundle() !== 'event') {
      return TRUE;
    }

    $organiserKey = (string) (int) $entity->getOwnerId();
    $categoryKey = $this->categoryKey($entity);
    $venueKey = $this->venueKey($entity);

    $seenOrganisers = [];
    $seenCategories = [];
    $seenVenues = [];

    foreach ($preferred as $preferredEntity) {
      if (!$preferredEntity instanceof NodeInterface || $preferredEntity->bundle() !== 'event') {
        continue;
      }

      $preferredOrganiser = (string) (int) $preferredEntity->getOwnerId();
      if ($preferredOrganiser !== '0') {
        $seenOrganisers[$preferredOrganiser] = TRUE;
      }

      $preferredCategory = $this->categoryKey($preferredEntity);
      if ($preferredCategory !== '') {
        $seenCategories[$preferredCategory] = TRUE;
      }

      $preferredVenue = $this->venueKey($preferredEntity);
      if ($preferredVenue !== '') {
        $seenVenues[$preferredVenue] = TRUE;
      }
    }

    if ($organiserKey !== '0' && isset($seenOrganisers[$organiserKey])) {
      return FALSE;
    }
    if ($categoryKey !== '' && isset($seenCategories[$categoryKey])) {
      return FALSE;
    }
    if ($venueKey !== '' && isset($seenVenues[$venueKey])) {
      return FALSE;
    }

    return TRUE;
  }

  private function categoryKey(NodeInterface $event): string {
    if (!$event->hasField('field_category') || $event->get('field_category')->isEmpty()) {
      return '';
    }

    $term = $event->get('field_category')->entity;
    return $term !== NULL ? (string) $term->id() : '';
  }

  private function venueKey(NodeInterface $event): string {
    if ($event->hasField('field_venue') && !$event->get('field_venue')->isEmpty()) {
      return 'venue:' . (string) $event->get('field_venue')->target_id;
    }
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $item = $event->get('field_location')->first();
      if ($item !== NULL) {
        $locality = trim((string) $item->get('locality')->getValue());
        if ($locality !== '') {
          return 'loc:' . strtolower($locality);
        }
      }
    }
    if ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      $name = trim((string) $event->get('field_venue_name')->value);
      if ($name !== '') {
        return 'name:' . strtolower($name);
      }
    }

    return '';
  }

}
