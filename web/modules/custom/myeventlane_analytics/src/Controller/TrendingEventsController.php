<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Datetime\TimeInterface;
use Drupal\myeventlane_analytics\Service\TrendingScoreService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for trending events on the front page.
 */
final class TrendingEventsController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TrendingScoreService $trendingScore,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_analytics.trending_score'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Renders trending events for the front page.
   *
   * @return array
   *   A render array for trending events.
   */
  public function trending(): array {
    $now = $this->time->getRequestTime();
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Load future events.
    $query = $nodeStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('field_event_start', date('Y-m-d\TH:i:s', $now), '>=')
      ->sort('field_event_start', 'ASC')
      ->range(0, 50);

    $nids = $query->execute();

    if (empty($nids)) {
      return [
        '#markup' => '',
        '#cache' => [
          'tags' => ['node_list:event'],
          'max-age' => 300,
        ],
      ];
    }

    // Calculate scores and build array.
    $events = [];
    foreach ($nids as $nid) {
      $score = $this->trendingScore->score((int) $nid);
      if ($score > 0) {
        $events[] = [
          'nid' => (int) $nid,
          'score' => $score,
        ];
      }
    }

    // Sort by score descending.
    usort($events, fn($a, $b) => $b['score'] <=> $a['score']);

    // Take top 10.
    $events = array_slice($events, 0, 10);

    // Load event nodes.
    $eventNids = array_column($events, 'nid');
    $nodes = $nodeStorage->loadMultiple($eventNids);

    // Build event data for Twig.
    $eventData = [];
    foreach ($events as $event) {
      $node = $nodes[$event['nid']] ?? NULL;
      if (!$node) {
        continue;
      }

      $eventData[] = [
        'nid' => $event['nid'],
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'is_trending' => TRUE,
      ];
    }

    return [
      '#theme' => 'myeventlane_trending_events',
      '#events' => $eventData,
      '#cache' => [
        'tags' => ['node_list:event'],
        'max-age' => 300,
      ],
    ];
  }

}
