<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_policy\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\State\StateInterface;
use Drupal\myeventlane_escalations_policy\Service\VendorRiskStreakStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Staff-only report showing vendor risk streaks and policy trigger history.
 */
final class PolicyReportController extends ControllerBase {

  public function __construct(
    private readonly VendorRiskStreakStore $streakStore,
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_escalations_policy.streak_store'),
      $container->get('state'),
    );
  }

  /**
   * Renders the policy report table.
   */
  public function report(): array {
    $last_run_week = $this->state->get('myeventlane_escalations_policy.last_run_week', '');
    $last_run_timestamp = $this->state->get('myeventlane_escalations_policy.last_run_timestamp', 0);

    $all_records = $this->streakStore->getAll();
    $vendor_storage = $this->entityTypeManager()->getStorage('myeventlane_vendor');

    $rows = [];
    foreach ($all_records as $vid => $record) {
      $vendor = $vendor_storage->load($vid);
      $vendor_name = $vendor ? $vendor->label() : $this->t('Unknown (#@id)', ['@id' => $vid]);

      $rows[] = [
        (string) $vendor_name,
        ucfirst($record['last_bucket'] ?: '-'),
        (string) $record['risk_streak'],
        $record['last_run_week'] ?: '-',
        $record['last_triggered_week'] ?: 'Never',
      ];
    }

    $build = [];

    // Last run info.
    if ($last_run_week) {
      $last_run_display = $last_run_week;
      if ($last_run_timestamp > 0) {
        $last_run_display .= ' (' . date('Y-m-d H:i', $last_run_timestamp) . ')';
      }
      $build['last_run'] = [
        '#type' => 'container',
        '#attributes' => ['style' => 'margin-bottom: 16px;'],
        'text' => [
          '#markup' => '<p>' . $this->t('Last policy run: @week', ['@week' => $last_run_display]) . '</p>',
        ],
      ];
    }
    else {
      $build['last_run'] = [
        '#type' => 'container',
        '#attributes' => ['style' => 'margin-bottom: 16px;'],
        'text' => [
          '#markup' => '<p>' . $this->t('Policy has not run yet.') . '</p>',
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Vendor'),
        $this->t('Health bucket'),
        $this->t('Risk streak (weeks)'),
        $this->t('Last evaluated'),
        $this->t('Last triggered'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No vendor policy data recorded yet. The policy evaluator runs weekly via cron.'),
      '#attributes' => ['class' => ['admin-escalation-policy-report']],
    ];

    return $build;
  }

}
