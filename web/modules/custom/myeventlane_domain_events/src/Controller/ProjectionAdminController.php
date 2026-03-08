<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_domain_events\Service\ProjectionHealthService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin UI for domain event projection observability.
 */
final class ProjectionAdminController extends ControllerBase {

  public function __construct(
    private readonly ProjectionHealthService $healthService,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_domain_events.projection_health'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds projection observability dashboard.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function page(): array {
    $report = $this->healthService->getReport();
    $projectorRows = [];
    foreach (($report['projectors'] ?? []) as $projector) {
      $oldestUnprocessed = $projector['oldest_unprocessed_event_id'] ?? NULL;
      $projectorRows[] = [
        (string) ($projector['projector_id'] ?? ''),
        number_format((int) ($projector['checkpoint_event_id'] ?? 0)),
        $oldestUnprocessed === NULL ? (string) $this->t('N/A') : number_format((int) $oldestUnprocessed),
      ];
    }

    return [
      'metrics' => [
        '#type' => 'table',
        '#header' => [$this->t('Metric'), $this->t('Value')],
        '#rows' => [
          [$this->t('Total events stored'), number_format((int) $report['total_events'])],
          [$this->t('Newest event ID'), number_format((int) ($report['newest_event_id'] ?? 0))],
          [$this->t('Max checkpoint event ID'), number_format((int) ($report['max_checkpoint_event_id'] ?? 0))],
          [$this->t('Lag events'), number_format((int) ($report['lag_events'] ?? 0))],
          [$this->t('Lag seconds'), $report['lag_seconds'] === NULL ? (string) $this->t('N/A') : number_format((int) $report['lag_seconds'])],
          [$this->t('Last event timestamp'), $this->formatTimestamp($report['last_event_timestamp'])],
          [$this->t('Projection status'), (string) $report['status']],
          [$this->t('Replay status summary'), (string) ($report['replay_status_summary'] ?? '')],
          [$this->t('Queue backlog'), number_format((int) $report['queue_backlog'])],
        ],
        '#empty' => $this->t('No projection data available.'),
      ],
      'projector_metrics' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Projector'),
          $this->t('Checkpoint event ID'),
          $this->t('Oldest unprocessed event ID'),
        ],
        '#rows' => $projectorRows,
        '#empty' => $this->t('No projector telemetry available.'),
      ],
      'replay_action' => [
        '#type' => 'link',
        '#title' => $this->t('Replay Projections'),
        '#url' => Url::fromRoute('myeventlane_domain_events.admin.events_replay_confirm'),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ],
    ];
  }

  /**
   * Formats UNIX timestamp for admin display.
   */
  private function formatTimestamp(mixed $timestamp): string {
    $value = is_numeric($timestamp) ? (int) $timestamp : 0;
    if ($value <= 0) {
      return (string) $this->t('N/A');
    }

    return sprintf(
      '%s (%d)',
      $this->dateFormatter->format($value, 'custom', 'Y-m-d H:i:s'),
      $value
    );
  }

}
