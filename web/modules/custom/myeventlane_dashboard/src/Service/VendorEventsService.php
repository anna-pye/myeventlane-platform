<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;

/**
 * Builds the dashboard "Your events" list.
 *
 * Rules:
 * - Store isolation: node:event.field_event_store == current store
 * - Upcoming: published AND end >= now
 * - Returns Twig-friendly rows (no entities in Twig)
 */
final class VendorEventsService implements VendorEventsServiceInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly VendorMetricsService $metrics,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getDashboardEvents(StoreInterface $store, array $range): array {
    $store_id = (int) $store->id();
    $start = (int) ($range['start'] ?? 0);
    $end = (int) ($range['end'] ?? time());
    $now = time();

    // Load upcoming events for this store.
    // We avoid N+1 by loading node IDs first, then loadMultiple once.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('field_event_store.target_id', $store_id);

    // Upcoming: end >= now. Authoritative field: field_event_end.
    // DateTime fields query on field name directly in EntityQuery.
    $query->condition('field_event_end.value', $now, '>=');

    $query->sort('created', 'DESC');
    $query->range(0, 10);

    $nids = $query->execute();
    if (empty($nids)) {
      return [
        'items' => [],
        'all_url' => Url::fromUserInput('/vendor/events')->toString(),
        'cache_tags' => ['commerce_store:' . $store_id],
      ];
    }

    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    // Per-event aggregates (best-effort: depends on order item → event linkage).
    $rsvps_by_event = $this->metrics->getConfirmedRsvpsByEventForStore($store_id, $start, $end);
    $tickets_by_event = $this->metrics->getPaidTicketsSoldByEventForStore($store_id, $start, $end);
    $revenue_by_event_cents = $this->metrics->getRevenueCentsByEventForStore($store_id, $start, $end);

    $rows = [];
    $cache_tags = ['commerce_store:' . $store_id];

    foreach ($nodes as $nid => $node) {
      $cache_tags[] = 'node:' . (int) $nid;

      $title = (string) $node->label();
      $url = $node->toUrl()->toString();

      $date = $this->formatEventDateForDashboard($node);

      // Basic status pill for dashboard.
      $status_label = $node->isPublished() ? 'Published' : 'Draft';
      $status_class = $node->isPublished() ? 'ok' : 'warn';

      $tickets = (int) ($tickets_by_event[(int) $nid] ?? 0);
      $rsvps = (int) ($rsvps_by_event[(int) $nid] ?? 0);
      $rev_cents = (int) ($revenue_by_event_cents[(int) $nid] ?? 0);

      $rows[] = [
        'title' => $title,
        'url' => $url,
        'date' => $date,
        'status_label' => $status_label,
        'status_class' => $status_class,
        'tickets_sold' => $tickets,
        'rsvps' => $rsvps,
        // Revenue formatting happens in Metrics service; here we format minimally.
        'revenue' => $rev_cents > 0 ? '$' . number_format($rev_cents / 100, 2) : '$0.00',
        'menu' => [
          [
            'label' => 'Edit',
            'url' => $node->toUrl('edit-form')->toString(),
          ],
        ],
      ];
    }

    return [
      'items' => $rows,
      'all_url' => Url::fromUserInput('/vendor/events')->toString(),
      'cache_tags' => array_values(array_unique($cache_tags)),
    ];
  }

  /**
   * Formats an event date for the dashboard.
   *
   * Authoritative field: field_event_start (datetime).
   */
  private function formatEventDateForDashboard($node): string {
    // Authoritative field: field_event_start.
    if ($node->hasField('field_event_start') && !$node->get('field_event_start')->isEmpty()) {
      $value = (string) $node->get('field_event_start')->value;
      $ts = strtotime($value);
      if ($ts) {
        return $this->dateFormatter->format($ts, 'custom', 'D j M, g:ia');
      }
    }

    // Fallback to created date.
    return $this->dateFormatter->format((int) $node->getCreatedTime(), 'custom', 'D j M, g:ia');
  }

}
