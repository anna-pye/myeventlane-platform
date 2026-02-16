<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Psr\Log\LoggerInterface;

/**
 * Returns recent escalations and recent orders for the Platform Control Centre.
 *
 * Escalations: single DB query, range(0, 5). No per-row lookups.
 * Orders: from cache populated by summary aggregator cron.
 *
 * @internal
 */
final class PlatformRecentActivityService {

  private const CACHE_KEY_ESCALATIONS = 'platform_control_centre:recent_escalations';

  private const CACHE_MAX_AGE = 120;

  private const ROW_LIMIT = 5;

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {}

  /**
   * Returns recent escalations (5 rows) and recent orders (5 rows).
   *
   * @return array{
   *   escalations: array<int, array{id: int, subject: string, status: string, priority: string, created: int, url: string}>,
   *   orders: array<int, array{order_id: int, order_number: string, amount: float, placed: int, url: string}>,
   *   escalations_empty: bool,
   *   orders_empty: bool
   * }
   */
  public function getRecentActivity(): array {
    $escalations = $this->getRecentEscalations();
    $orders = $this->getRecentOrders();
    return [
      'escalations' => $escalations,
      'orders' => $orders,
      'escalations_empty' => empty($escalations),
      'orders_empty' => empty($orders),
    ];
  }

  /**
   * Returns 5 most recent escalations via single query.
   *
   * No user data fetched (N+1 eliminated, PII removed).
   */
  private function getRecentEscalations(): array {
    $cached = $this->cache->get(self::CACHE_KEY_ESCALATIONS);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    if (!$this->database->schema()->tableExists('escalation')) {
      return [];
    }

    $q = $this->database->select('escalation', 'e');
    $q->fields('e', ['id', 'subject', 'status', 'priority', 'created']);
    $q->orderBy('e.created', 'DESC');
    $q->range(0, self::ROW_LIMIT);

    $rows = $q->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $result = [];

    foreach ($rows as $r) {
      $result[] = [
        'id' => (int) $r['id'],
        'subject' => $r['subject'] ?? '',
        'status' => $r['status'] ?? '',
        'priority' => $r['priority'] ?? '',
        'created' => (int) $r['created'],
        'url' => $this->urlGenerator->generateFromRoute('entity.escalation.canonical', ['escalation' => $r['id']]),
      ];
    }

    $this->cache->set(self::CACHE_KEY_ESCALATIONS, $result, time() + self::CACHE_MAX_AGE, ['escalation_list']);

    return $result;
  }

  /**
   * Returns 5 most recent orders from cache (populated by cron).
   */
  private function getRecentOrders(): array {
    $cached = $this->cache->get('platform_recent_orders');
    if ($cached === FALSE || !is_array($cached->data)) {
      return [];
    }

    $rows = [];
    foreach ($cached->data as $r) {
      $rows[] = $r + [
        'url' => $this->urlGenerator->generateFromRoute('entity.commerce_order.canonical', ['commerce_order' => $r['order_id']]),
      ];
    }
    return $rows;
  }

  /**
   * Returns cache metadata.
   *
   * @return array{tags: string[], max-age: int}
   */
  public function getCacheMetadata(): array {
    return [
      'tags' => ['escalation_list', 'commerce_order_list'],
      'max-age' => self::CACHE_MAX_AGE,
    ];
  }

}
