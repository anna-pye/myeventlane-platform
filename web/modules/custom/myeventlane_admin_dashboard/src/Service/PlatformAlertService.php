<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Psr\Log\LoggerInterface;

/**
 * Returns operational alerts for the Platform Control Centre.
 *
 * Uses lightweight count queries only. No entity loading.
 * SLA at risk: urgent escalation older than 4 hours, status not resolved.
 *
 * @internal
 */
final class PlatformAlertService {

  private const CACHE_KEY = 'platform_control_centre:alerts';

  private const CACHE_MAX_AGE = 60;

  private const SLA_HOURS = 4;

  /**
   * Resolved escalation statuses.
   */
  private const RESOLVED_STATUSES = ['resolved', 'closed'];

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {}

  /**
   * Returns up to 4 operational alerts.
   *
   * @return array<int, array{title: string, count: int, severity: string, url: string}>
   */
  public function getAlerts(): array {
    $cached = $this->cache->get(self::CACHE_KEY);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $data = $this->buildAlerts();
    $this->cache->set(self::CACHE_KEY, $data, time() + self::CACHE_MAX_AGE, ['escalation_list']);

    return $data;
  }

  /**
   * Returns count of SLA-at-risk escalations.
   *
   * Urgent + older than 4 hours + not resolved.
   */
  public function getSlaAtRiskCount(): int {
    if (!$this->entityTypeManager->hasDefinition('escalation')) {
      return 0;
    }

    $cutoff = $this->time->getRequestTime() - (self::SLA_HOURS * 3600);

    $query = $this->entityTypeManager->getStorage('escalation')->getQuery();
    $query->accessCheck(FALSE);
    $query->condition('priority', 'urgent');
    $query->condition('created', $cutoff, '<');
    $query->condition('status', self::RESOLVED_STATUSES, 'NOT IN');
    return (int) $query->count()->execute();
  }

  /**
   * Builds alert list from lightweight queries.
   */
  private function buildAlerts(): array {
    $alerts = [];
    $escalation_url = $this->urlGenerator->generateFromRoute('entity.escalation.collection');

    if (!$this->entityTypeManager->hasDefinition('escalation')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('escalation');

    $urgent_count = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('priority', 'urgent')
      ->condition('status', self::RESOLVED_STATUSES, 'NOT IN')
      ->count()
      ->execute();

    if ($urgent_count > 0) {
      $alerts[] = [
        'title' => 'Urgent escalations',
        'count' => $urgent_count,
        'severity' => 'red',
        'url' => $escalation_url,
      ];
    }

    $sla_count = $this->getSlaAtRiskCount();
    if ($sla_count > 0) {
      $alerts[] = [
        'title' => 'SLA breach risk',
        'count' => $sla_count,
        'severity' => 'red',
        'url' => $escalation_url,
      ];
    }

    $open_count = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', self::RESOLVED_STATUSES, 'NOT IN')
      ->count()
      ->execute();

    if ($open_count > 0 && count($alerts) < 4) {
      $alerts[] = [
        'title' => 'Open escalations',
        'count' => $open_count,
        'severity' => 'amber',
        'url' => $escalation_url,
      ];
    }

    return array_slice($alerts, 0, 4);
  }

  /**
   * Returns cache metadata.
   *
   * @return array{tags: string[], max-age: int}
   */
  public function getCacheMetadata(): array {
    return [
      'tags' => ['escalation_list'],
      'max-age' => self::CACHE_MAX_AGE,
    ];
  }

}
