<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

final class FrontCategoryStatsService {

  private EntityTypeManagerInterface $entityTypeManager;
  private CacheBackendInterface $cache;
  private TimeInterface $time;
  private CategoryColorService $colors;
  private ConfigFactoryInterface $configFactory;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    CacheBackendInterface $cache,
    TimeInterface $time,
    CategoryColorService $colors,
    ConfigFactoryInterface $configFactory
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->cache = $cache;
    $this->time = $time;
    $this->colors = $colors;
    $this->configFactory = $configFactory;
  }

  public function buildFrontStats(): array {
    $settings = $this->configFactory->get('myeventlane_core.settings');

    $event_type = (string) $settings->get('front_page.event_type') ?: 'event';
    $vocab = (string) $settings->get('front_page.category_vocab') ?: 'categories';
    $field = (string) $settings->get('front_page.category_field') ?: 'field_event_category';

    $cid = "myeventlane:front:category_stats:v3:$event_type:$vocab:$field";
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }

    $terms = $this->loadTerms($vocab);

    $items = [];
    $total = 0;

    foreach (array_values($terms) as $i => $term) {
      $count = $this->countEventsForTerm($event_type, $field, (int) $term->id());
      $total += $count;

      $items[] = [
        'tid' => (int) $term->id(),
        'label' => $term->label(),
        'count' => $count,
        'color' => $this->colors->getColorForLabel($term->label(), $i),
      ];
    }

    usort($items, static function (array $a, array $b): int {
      if ($a['count'] === $b['count']) {
        return strnatcasecmp($a['label'], $b['label']);
      }
      return $b['count'] <=> $a['count'];
    });

    $pie = $this->buildPieGeometry($items, $total);

    $data = [
      'items' => $items,
      'total' => $total,
      'pie' => $pie,
      'meta' => [
        'event_type' => $event_type,
        'vocab' => $vocab,
        'field' => $field,
        'generated' => $this->time->getRequestTime(),
      ],
      'cache' => [
        'cid' => $cid,
        'tags' => [
          "node_list:$event_type",
          "taxonomy_term_list:$vocab",
          'config:myeventlane_core.settings',
        ],
        'max_age' => 3600,
      ],
    ];

    $this->cache->set($cid, $data, $this->time->getRequestTime() + 3600, $data['cache']['tags']);
    return $data;
  }

  private function loadTerms(string $vocab): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', $vocab)
      ->sort('weight', 'ASC')
      ->sort('name', 'ASC')
      ->execute();

    if (!$ids) {
      return [];
    }

    $terms = $storage->loadMultiple($ids);
    return array_filter($terms, static fn($t) => $t instanceof TermInterface);
  }

  private function countEventsForTerm(string $type, string $field, int $tid): int {
    try {
      $query = $this->entityTypeManager->getStorage('node')->getQuery();
      $query->accessCheck(TRUE);
      $query->condition('type', $type);
      $query->condition('status', 1);
      $query->condition("$field.target_id", $tid);
      $query->count();
      return (int) $query->execute();
    }
    catch (\Throwable $e) {
      return 0;
    }
  }

  private function buildPieGeometry(array $items, int $total): array {
    $size = 220;
    $cx = 110.0;
    $cy = 110.0;
    $r = 100.0;

    $start = -90.0;
    $slices = [];

    foreach ($items as $index => $item) {
      $count = (int) $item['count'];
      $angle = $total > 0 ? ($count / $total) * 360.0 : 0;
      $end = $start + $angle;

      $slices[] = [
        'index' => $index,
        'tid' => (int) $item['tid'],
        'label' => (string) $item['label'],
        'count' => $count,
        'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
        'color' => (string) $item['color'],
        'd' => $this->pieSlicePath($cx, $cy, $r, $start, $end),
      ];

      $start = $end;
    }

    return [
      'size' => $size,
      'cx' => $cx,
      'cy' => $cy,
      'r' => $r,
      'slices' => $slices,
    ];
  }

  private function pieSlicePath(float $cx, float $cy, float $r, float $startDeg, float $endDeg): string {
    $start = $this->polarToCartesian($cx, $cy, $r, $endDeg);
    $end = $this->polarToCartesian($cx, $cy, $r, $startDeg);
    $largeArc = ($endDeg - $startDeg) > 180 ? 1 : 0;

    return implode(' ', [
      'M', $cx, $cy,
      'L', $start['x'], $start['y'],
      'A', $r, $r, 0, $largeArc, 0, $end['x'], $end['y'],
      'Z',
    ]);
  }

  private function donutSlicePath(float $cx, float $cy, float $rOuter, float $rInner, float $startDeg, float $endDeg): string {
    $start = $this->polarToCartesian($cx, $cy, $rOuter, $endDeg);
    $end = $this->polarToCartesian($cx, $cy, $rOuter, $startDeg);

    $startInner = $this->polarToCartesian($cx, $cy, $rInner, $endDeg);
    $endInner = $this->polarToCartesian($cx, $cy, $rInner, $startDeg);

    $largeArc = (($endDeg - $startDeg) % 360.0) > 180.0 ? 1 : 0;

    return implode(' ', [
      'M', $start['x'], $start['y'],
      'A', $rOuter, $rOuter, 0, $largeArc, 0, $end['x'], $end['y'],
      'L', $endInner['x'], $endInner['y'],
      'A', $rInner, $rInner, 0, $largeArc, 1, $startInner['x'], $startInner['y'],
      'Z',
    ]);
  }

  private function polarToCartesian(float $cx, float $cy, float $r, float $deg): array {
    $rad = deg2rad($deg);
    return [
      'x' => round($cx + ($r * cos($rad)), 3),
      'y' => round($cy + ($r * sin($rad)), 3),
    ];
  }

}
