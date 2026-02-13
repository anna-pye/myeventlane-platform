<?php

declare(strict_types=1);

namespace Drupal\myeventlane_support_console\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_escalations_capacity\Service\CapacitySignalEvaluator;
use Drupal\myeventlane_escalations_capacity\Service\EscalationWorkloadQuery;
use Drupal\myeventlane_escalations_capacity\Service\StaffActivityAggregator;
use Drupal\myeventlane_escalations_policy\Service\VendorPolicyEvaluator;
use Drupal\myeventlane_escalations_refunds\Repository\RefundsCorrelationRepository;
use Drupal\myeventlane_escalations_sla\Service\EscalationSlaBadgeResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Support Console dashboard for staff with administer escalations permission.
 *
 * Aggregates global signal, action queue, vendor risk, refund watch,
 * capacity snapshot, and quick links. Fails safely per panel.
 */
final class SupportConsoleController extends ControllerBase {

  /**
   * Action queue limit.
   */
  private const ACTION_QUEUE_LIMIT = 15;

  /**
   * Statuses considered closed (not open).
   */
  private const CLOSED_STATUSES = ['resolved', 'closed'];

  public function __construct(
    private readonly EscalationWorkloadQuery $workloadQuery,
    private readonly StaffActivityAggregator $activityAggregator,
    private readonly CapacitySignalEvaluator $signalEvaluator,
    private readonly VendorPolicyEvaluator $vendorPolicyEvaluator,
    private readonly RefundsCorrelationRepository $refundsRepository,
    private readonly EscalationSlaBadgeResolver $badgeResolver,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_escalations_capacity.workload_query'),
      $container->get('myeventlane_escalations_capacity.staff_activity_aggregator'),
      $container->get('myeventlane_escalations_capacity.signal_evaluator'),
      $container->get('myeventlane_escalations_policy.evaluator'),
      $container->get('myeventlane_escalations_refunds.repository'),
      $container->get('myeventlane_escalations_sla.badge_resolver'),
      $container->get('date.formatter'),
      $container->get('logger.channel.myeventlane_support_console'),
    );
  }

  /**
   * Renders the Support Console dashboard.
   */
  public function dashboard(): array {
    $panels = [
      'global_signal' => $this->buildGlobalSignal(),
      'action_queue' => $this->buildActionQueue(),
      'vendor_risk' => $this->buildVendorRisk(),
      'refund_watch' => $this->buildRefundWatch(),
      'capacity_snapshot' => $this->buildCapacitySnapshot(),
      'quick_links' => $this->buildQuickLinks(),
    ];

    return [
      '#theme' => 'support_console_dashboard',
      '#panels' => $panels,
      '#cache' => ['max-age' => 0],
      '#attached' => [
        'library' => ['myeventlane_support_console/support_console'],
      ],
    ];
  }

  /**
   * Builds the global capacity signal panel.
   */
  private function buildGlobalSignal(): array {
    try {
      $workload = $this->workloadQuery->getWorkloadMetrics();
      $openIds = $this->workloadQuery->getOpenEscalationIds();
      $activity = $this->activityAggregator->aggregate($openIds);
      $signal = $this->signalEvaluator->evaluate($workload, $activity);

      return [
        'signal' => $signal,
        'workload' => $workload,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Support console: global_signal failed. @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Builds the action queue (open escalations waiting on staff, limit 15).
   */
  private function buildActionQueue(): array {
    try {
      $query = $this->entityTypeManager()->getStorage('escalation')->getQuery();
      $query->accessCheck(TRUE);
      $query->condition('status', self::CLOSED_STATUSES, 'NOT IN');
      $query->condition('field_waiting_on', 'staff');
      $query->sort('created', 'ASC');
      $query->range(0, self::ACTION_QUEUE_LIMIT);

      $ids = $query->execute();
      if (empty($ids)) {
        return ['items' => []];
      }

      $escalations = $this->entityTypeManager()->getStorage('escalation')->loadMultiple($ids);
      $items = [];

      foreach ($escalations as $escalation) {
        /** @var \Drupal\myeventlane_escalations\Entity\Escalation $escalation */
        $badge = $this->badgeResolver->resolve($escalation);
        $items[] = [
          'id' => $escalation->id(),
          'subject' => $escalation->get('subject')->value,
          'url' => Url::fromRoute('entity.escalation.canonical', ['escalation' => $escalation->id()])->toString(),
          'priority' => $escalation->get('priority')->value ?? '-',
          'badge' => $badge,
          'created' => $this->dateFormatter->format((int) $escalation->get('created')->value, 'short'),
        ];
      }

      return ['items' => $items];
    }
    catch (\Throwable $e) {
      $this->logger->error('Support console: action_queue failed. @message', [
        '@message' => $e->getMessage(),
      ]);
      return ['items' => []];
    }
  }

  /**
   * Builds the vendor risk panel.
   */
  private function buildVendorRisk(): array {
    try {
      $decisions = $this->vendorPolicyEvaluator->evaluateAll();
      $atRisk = array_filter($decisions, fn(array $d) => ($d['bucket'] ?? '') === 'risk');
      $triggerCount = count(array_filter($decisions, fn(array $d) => !empty($d['trigger'])));

      return [
        'total_vendors' => count($decisions),
        'at_risk_count' => count($atRisk),
        'trigger_count' => $triggerCount,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Support console: vendor_risk failed. @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Builds the refund watch panel.
   *
   * RefundsCorrelationRepository has no platform-wide summary method.
   * Returns minimal placeholder; no raw SQL, reuse only.
   */
  private function buildRefundWatch(): array {
    try {
      return [
        'note' => $this->t('Refund metrics are available per vendor. See vendor refund summaries for details.'),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Support console: refund_watch failed. @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Builds the capacity snapshot panel.
   */
  private function buildCapacitySnapshot(): array {
    try {
      $workload = $this->workloadQuery->getWorkloadMetrics();
      $openIds = $this->workloadQuery->getOpenEscalationIds();
      $activity = $this->activityAggregator->aggregate($openIds);
      $signal = $this->signalEvaluator->evaluate($workload, $activity);

      return [
        'workload' => $workload,
        'activity' => $activity,
        'signal' => $signal,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Support console: capacity_snapshot failed. @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Builds quick links panel.
   */
  private function buildQuickLinks(): array {
    $links = [];

    $routeNames = [
      'entity.escalation.collection' => $this->t('Manage Escalations'),
      'myeventlane_escalations_sla.dashboard' => $this->t('Escalation Dashboard'),
      'myeventlane_escalations_analytics.staff_dashboard' => $this->t('Escalation Analytics'),
      'myeventlane_escalations_capacity.dashboard' => $this->t('Escalation Capacity'),
      'myeventlane_escalations_policy.report' => $this->t('Escalation Policy'),
      'myeventlane_staff_playbooks.governance_dashboard' => $this->t('Governance'),
    ];

    foreach ($routeNames as $route => $title) {
      try {
        $url = Url::fromRoute($route);
        if ($url->access($this->currentUser())) {
          $links[] = [
            'url' => $url->toString(),
            'title' => $title,
          ];
        }
      }
      catch (\Throwable) {
        // Route may not exist; skip.
      }
    }

    return ['links' => $links];
  }

}
