<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_admin_dashboard\Service\PayoutBatchWorkflowService;
use Drupal\myeventlane_core\Service\MelAdminShellBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * UI controller for the payout batch approval workflow.
 */
final class PayoutBatchUiController extends ControllerBase {

  public function __construct(
    protected PayoutBatchWorkflowService $workflow,
    protected CsrfTokenGenerator $csrfToken,
    protected MelAdminShellBuilder $adminShellBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_admin_dashboard.payout_batch_workflow'),
      $container->get('csrf_token'),
      $container->get('myeventlane_core.mel_admin_shell_builder'),
    );
  }

  /**
   * Lists payout batches with optional status filter.
   */
  public function list(Request $request): array {
    $statusFilter = $request->query->get('status');
    $validStatuses = ['draft', 'pending', 'approved', 'executed', 'cancelled'];
    if ($statusFilter !== NULL && !in_array($statusFilter, $validStatuses, TRUE)) {
      $statusFilter = NULL;
    }

    $batches = $this->workflow->listBatches($statusFilter);

    $userIds = [];
    foreach ($batches as $b) {
      if (!empty($b->created_uid)) {
        $userIds[] = (int) $b->created_uid;
      }
      if (!empty($b->approved_uid)) {
        $userIds[] = (int) $b->approved_uid;
      }
      if (!empty($b->executed_uid)) {
        $userIds[] = (int) $b->executed_uid;
      }
    }

    $userLabels = [];
    $userIds = array_unique(array_filter($userIds));
    if (!empty($userIds)) {
      $users = $this->entityTypeManager()->getStorage('user')->loadMultiple($userIds);
      foreach ($users as $u) {
        $userLabels[(int) $u->id()] = $u->getDisplayName();
      }
    }

    $batchSummaries = [];
    foreach ($batches as $b) {
      $items = $this->workflow->loadBatchItems((int) $b->id);
      $totalNet = 0.0;
      foreach ($items as $item) {
        $totalNet += (float) $item->total_net;
      }
      $batchSummaries[(int) $b->id] = [
        'vendor_count' => count($items),
        'total_net' => $totalNet,
      ];
    }

    $main = [
      '#theme' => 'payout_batch_list',
      '#batches' => $batches,
      '#user_labels' => $userLabels,
      '#batch_summaries' => $batchSummaries,
      '#status_filter' => $statusFilter,
      '#attached' => [
        'library' => ['myeventlane_admin_dashboard/platform_control_centre'],
      ],
      '#cache' => [
        'tags' => ['myeventlane_payout_batch'],
        'contexts' => ['user.permissions', 'url.query_args:status'],
        'max-age' => 300,
      ],
    ];

    return $this->adminShellBuilder->wrapStandard(
      $main,
      $this->t('Payout batches'),
      $this->t('Create, submit, approve, and execute payout batches.'),
    );
  }

  /**
   * Batch detail page.
   */
  public function view(int $batch_id): array {
    try {
      $batch = $this->workflow->loadBatch($batch_id);
    }
    catch (\InvalidArgumentException $e) {
      throw new NotFoundHttpException($e->getMessage());
    }

    $items = $this->workflow->loadBatchItems($batch_id);

    $storeIds = array_unique(array_map(fn($i) => (int) $i->store_id, $items));
    $storeLabels = $this->workflow->resolveStoreLabels($storeIds);

    $userIds = array_filter([
      (int) ($batch->created_uid ?? 0),
      (int) ($batch->approved_uid ?? 0),
      (int) ($batch->executed_uid ?? 0),
    ]);
    $userLabels = [];
    $userIds = array_unique(array_filter($userIds));
    if (!empty($userIds)) {
      $users = $this->entityTypeManager()->getStorage('user')->loadMultiple($userIds);
      foreach ($users as $u) {
        $userLabels[(int) $u->id()] = $u->getDisplayName();
      }
    }

    $account = $this->currentUser();
    $csrfToken = $this->csrfToken->get('payout_batch_' . $batch_id);

    $main = [
      '#theme' => 'payout_batch_view',
      '#batch' => $batch,
      '#items' => $items,
      '#store_labels' => $storeLabels,
      '#user_labels' => $userLabels,
      '#csrf_token' => $csrfToken,
      '#can_request' => $account->hasPermission('request myeventlane payouts'),
      '#can_approve' => $account->hasPermission('approve myeventlane payouts'),
      '#can_execute' => $account->hasPermission('execute myeventlane payouts'),
      '#can_cancel' => $account->hasPermission('cancel myeventlane payouts'),
      '#attached' => [
        'library' => ['myeventlane_admin_dashboard/platform_control_centre'],
      ],
      '#cache' => [
        'tags' => ['myeventlane_payout_batch'],
        'contexts' => ['user.permissions'],
        'max-age' => 0,
      ],
    ];

    return $this->adminShellBuilder->wrapStandard(
      $main,
      $this->viewTitle($batch_id),
      '',
    );
  }

  /**
   * POST: Creates a batch from selected ledger IDs.
   */
  public function createFromLedgerSelection(Request $request): RedirectResponse {
    $this->validateCsrf($request, 'payout_action');
    $days = $this->extractDays($request);

    $ids = $request->request->all('ledger_ids');
    if (!is_array($ids) || empty($ids)) {
      $this->messenger()->addWarning($this->t('No payouts selected.'));
      return $this->redirectToPayouts($days);
    }

    try {
      $batchId = $this->workflow->createBatchFromLedger($ids);
      $this->messenger()->addStatus($this->t('Payout batch #@id created (draft). Review and submit for approval.', [
        '@id' => $batchId,
      ]));
      return $this->redirectToBatch($batchId);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addWarning($e->getMessage());
      return $this->redirectToPayouts($days);
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($e->getMessage());
      return $this->redirectToPayouts($days);
    }
  }

  /**
   * POST: Submits a batch for approval (draft → pending).
   */
  public function submit(int $batch_id, Request $request): RedirectResponse {
    $this->validateCsrf($request, 'payout_batch_' . $batch_id);

    try {
      $this->workflow->submitBatch($batch_id);
      $this->messenger()->addStatus($this->t('Batch #@id submitted for approval.', ['@id' => $batch_id]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
    }

    return $this->redirectToBatch($batch_id);
  }

  /**
   * POST: Approves a pending batch.
   */
  public function approve(int $batch_id, Request $request): RedirectResponse {
    $this->validateCsrf($request, 'payout_batch_' . $batch_id);

    try {
      $this->workflow->approveBatch($batch_id);
      $this->messenger()->addStatus($this->t('Batch #@id approved.', ['@id' => $batch_id]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
    }

    return $this->redirectToBatch($batch_id);
  }

  /**
   * POST: Executes an approved batch (creates Stripe transfers).
   */
  public function execute(int $batch_id, Request $request): RedirectResponse {
    $this->validateCsrf($request, 'payout_batch_' . $batch_id);

    try {
      $result = $this->workflow->executeBatch($batch_id);

      if ($result['vendors_paid'] > 0) {
        $this->messenger()->addStatus($this->t(
          'Batch #@id executed. @paid vendors paid (@rows rows). @skipped skipped.',
          [
            '@id' => $batch_id,
            '@paid' => $result['vendors_paid'],
            '@rows' => $result['rows_paid'],
            '@skipped' => $result['vendors_skipped'],
          ]
        ));
      }

      if ($result['vendors_skipped'] > 0) {
        $this->messenger()->addWarning($this->t(
          '@skipped vendors skipped during batch #@id. See logs for details.',
          ['@skipped' => $result['vendors_skipped'], '@id' => $batch_id]
        ));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
    }

    return $this->redirectToBatch($batch_id);
  }

  /**
   * POST: Cancels a batch.
   */
  public function cancel(int $batch_id, Request $request): RedirectResponse {
    $this->validateCsrf($request, 'payout_batch_' . $batch_id);

    try {
      $this->workflow->cancelBatch($batch_id);
      $this->messenger()->addStatus($this->t('Batch #@id cancelled. Ledger rows reverted to unpaid.', ['@id' => $batch_id]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
    }

    return $this->redirectToBatchList();
  }

  /**
   * Title callback for the batch view page.
   */
  public function viewTitle(int $batch_id): string {
    try {
      $batch = $this->workflow->loadBatch($batch_id);
      return (string) $this->t('Payout batch @uuid', ['@uuid' => $batch->batch_uuid]);
    }
    catch (\Exception $e) {
      return (string) $this->t('Payout batch');
    }
  }

  private function validateCsrf(Request $request, string $tokenId): void {
    $token = $request->request->get('token', '');
    if (!$this->csrfToken->validate((string) $token, $tokenId)) {
      throw new AccessDeniedHttpException('Invalid or missing CSRF token.');
    }
  }

  private function extractDays(Request $request): int {
    $days = (int) $request->request->get('days', 30);
    return in_array($days, [7, 30, 90], TRUE) ? $days : 30;
  }

  private function redirectToPayouts(int $days): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('myeventlane_admin_dashboard.payouts', [], ['query' => ['days' => $days]])->toString()
    );
  }

  private function redirectToBatch(int $batchId): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('myeventlane_admin_dashboard.payout_batch_view', ['batch_id' => $batchId])->toString()
    );
  }

  private function redirectToBatchList(): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('myeventlane_admin_dashboard.payout_batches')->toString()
    );
  }

}
