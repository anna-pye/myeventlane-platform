<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Drupal\myeventlane_admin_dashboard\Service\PlatformMetricsService;
use Drupal\myeventlane_core\Service\MelAdminShellBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Payouts tab – payout liability, ledger table, and management actions.
 */
final class PayoutController extends ControllerBase {

  public function __construct(
    protected PlatformMetricsService $metricsService,
    protected Connection $database,
    protected CsrfTokenGenerator $csrfToken,
    protected MelAdminShellBuilder $adminShellBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('myeventlane_admin_dashboard.metrics'),
      $container->get('database'),
      $container->get('csrf_token'),
      $container->get('myeventlane_core.mel_admin_shell_builder'),
    );
  }

  /**
   * Returns the Payouts overview page with ledger management table.
   */
  public function overview(Request $request): array {
    $days = $this->sanitizeDays($request);
    $payoutSummary = $this->metricsService->getPayoutLiabilitySummary($days);
    $ledgerRows = $this->getLedgerRows($days);
    $currentRoute = 'myeventlane_admin_dashboard.payouts';
    $account = $this->currentUser();

    $main = [
      '#theme' => 'platform_control_centre_payouts',
      '#payout_summary' => $payoutSummary,
      '#ledger_rows' => $ledgerRows,
      '#csrf_token' => $this->csrfToken->get('payout_action'),
      '#can_manage' => $account->hasPermission('manage myeventlane payouts'),
      '#can_reverse' => $account->hasPermission('reverse myeventlane payouts'),
      '#can_execute' => $account->hasPermission('execute myeventlane payouts'),
      '#can_batch' => $account->hasPermission('execute myeventlane payout batches'),
      '#can_request' => $account->hasPermission('request myeventlane payouts'),
      '#days' => $days,
      '#filter_url_30' => Url::fromRoute($currentRoute, [], ['query' => ['days' => 30]])->toString(),
      '#filter_url_90' => Url::fromRoute($currentRoute, [], ['query' => ['days' => 90]])->toString(),
      '#attached' => [
        'library' => [
          'myeventlane_admin_dashboard/platform_control_centre',
        ],
      ],
      '#cache' => [
        'tags' => ['myeventlane_payout_ledger', 'myeventlane_payout_batch', 'commerce_order_list'],
        'contexts' => ['user.roles', 'user.permissions', 'url.query_args:days'],
        'max-age' => 300,
      ],
    ];

    return $this->adminShellBuilder->wrapStandard(
      $main,
      $this->t('Payouts'),
      $this->t('Ledger, liability, and batch workflow.'),
    );
  }

  /**
   * Fetches ledger rows for the given time window with store labels.
   *
   * @return array<int, array<string, mixed>>
   */
  private function getLedgerRows(int $days): array {
    if (!$this->database->schema()->tableExists('myeventlane_payout_ledger')) {
      return [];
    }

    $cutoff = strtotime("-{$days} days midnight");
    $rows = $this->database->select('myeventlane_payout_ledger', 'l')
      ->fields('l')
      ->condition('l.created', $cutoff, '>=')
      ->orderBy('l.status', 'ASC')
      ->orderBy('l.created', 'DESC')
      ->range(0, 200)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($rows)) {
      return [];
    }

    $storeIds = array_unique(array_filter(array_column($rows, 'store_id')));
    $stores = [];
    if (!empty($storeIds)) {
      $stores = $this->entityTypeManager()->getStorage('commerce_store')->loadMultiple($storeIds);
    }

    foreach ($rows as &$row) {
      $storeId = (int) $row['store_id'];
      $row['store_label'] = isset($stores[$storeId]) ? (string) $stores[$storeId]->label() : "Store #{$storeId}";
    }

    return $rows;
  }

  private function sanitizeDays(Request $request): int {
    $days = (int) $request->query->get('days', 30);
    return in_array($days, [7, 30, 90], TRUE) ? $days : 30;
  }

}
