<?php

declare(strict_types=1);

namespace Drupal\myeventlane_reporting\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_automation\Service\AutomationAuditLogger;
use Drupal\myeventlane_automation\Service\AutomationDispatchService;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for export centre UI.
 */
final class ExportCentreController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly AutomationAuditLogger $auditLogger,
    private readonly AutomationDispatchService $dispatchService,
  ) {
    parent::__construct($domainDetector, $currentUser, $messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('myeventlane_automation.audit_logger'),
      $container->get('myeventlane_automation.dispatch'),
    );
  }

  /**
   * Access callback for export centre.
   */
  public function access(NodeInterface $event, AccountInterface $account): AccessResultInterface {
    $this->assertEventOwnership($event);
    return AccessResult::allowed()
      ->cachePerUser()
      ->addCacheableDependency($event);
  }

  /**
   * Lists export requests for the vendor.
   */
  public function list(): array {
    $this->assertVendorAccess();
    $userId = (int) $this->currentUser->id();

    // Get vendor's events.
    $eventIds = $this->getUserEvents($userId);

    // Query dispatch table for export requests.
    $exports = [];
    if (!empty($eventIds)) {
      $query = $this->database->select('myeventlane_automation_dispatch', 'd')
        ->fields('d', [
          'id',
          'event_id',
          'notification_type',
          'status',
          'scheduled_for',
          'sent_at',
          'metadata',
        ])
        ->condition('d.event_id', $eventIds, 'IN')
        ->condition('d.notification_type', [
          AutomationDispatchService::TYPE_EXPORT_READY_CSV,
          AutomationDispatchService::TYPE_EXPORT_READY_ICS,
        ], 'IN')
        ->orderBy('d.scheduled_for', 'DESC')
        ->range(0, 50);

      $results = $query->execute()->fetchAll();

      foreach ($results as $row) {
        $eventId = (int) $row->event_id;
        $event = $this->entityTypeManager->getStorage('node')->load($eventId);

        if (!$event) {
          continue;
        }

        $metadata = $row->metadata ? json_decode($row->metadata, TRUE) : [];
        $downloadUrl = $metadata['download_url'] ?? NULL;

        $exports[] = [
          'id' => (int) $row->id,
          'event' => $event,
          'event_name' => $event->label(),
          'type' => $row->notification_type === AutomationDispatchService::TYPE_EXPORT_READY_CSV ? 'CSV' : 'ICS',
          'status' => $row->status,
          'requested_at' => (int) $row->scheduled_for,
          'ready_at' => $row->sent_at ? (int) $row->sent_at : NULL,
          'download_url' => $downloadUrl,
        ];
      }
    }

    // Get vendor's events for request buttons.
    $events = [];
    if (!empty($eventIds)) {
      $events = $this->entityTypeManager->getStorage('node')->loadMultiple($eventIds);
    }

    return $this->buildVendorPage('myeventlane_reporting_export_centre', [
      'exports' => $exports,
      'events' => $events,
      '#attached' => [
        'library' => [
          'myeventlane_reporting/reporting',
          'myeventlane_vendor_theme/global-styling',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list', 'user:' . $userId],
        'max-age' => 60,
      ],
    ]);
  }

  /**
   * Requests a CSV export for attendees.
   */
  public function requestCsv(NodeInterface $event): RedirectResponse {
    $this->assertEventOwnership($event);
    $userId = (int) $this->currentUser->id();

    // Create dispatch record for export.
    // The actual export generation should be handled by a queue worker.
    $recipientHash = hash('sha256', (string) $userId . 'csv_export');

    $this->dispatchService->createDispatch(
      (int) $event->id(),
      AutomationDispatchService::TYPE_EXPORT_READY_CSV,
      $recipientHash,
      NULL,
      [
        'export_type' => 'attendees',
        'requested_by' => $userId,
        'format' => 'csv',
      ]
    );

    // Log in audit (fail silently if table doesn't exist).
    try {
      $this->auditLogger->log(
        (int) $event->id(),
        'export_requested',
        'export_ready_csv',
        $recipientHash,
        ['user_id' => $userId, 'export_type' => 'attendees']
      );
    }
    catch (\Exception $e) {
      // Audit table may not exist yet - log error but don't break the flow.
      \Drupal::logger('myeventlane_reporting')->warning('Failed to log audit entry: @message', ['@message' => $e->getMessage()]);
    }

    $this->getMessenger()->addStatus($this->t('CSV export requested. You will be notified when it is ready for download.'));

    return new RedirectResponse(Url::fromRoute('myeventlane_reporting.export_centre')->toString());
  }

  /**
   * Requests a sales export.
   */
  public function requestSales(NodeInterface $event): RedirectResponse {
    $this->assertEventOwnership($event);
    $userId = (int) $this->currentUser->id();

    // Create dispatch record for sales export.
    $recipientHash = hash('sha256', (string) $userId . 'sales_export');

    $this->dispatchService->createDispatch(
      (int) $event->id(),
      AutomationDispatchService::TYPE_EXPORT_READY_CSV,
      $recipientHash,
      NULL,
      [
        'export_type' => 'sales',
        'requested_by' => $userId,
        'format' => 'csv',
      ]
    );

    // Log in audit (fail silently if table doesn't exist).
    try {
      $this->auditLogger->log(
        (int) $event->id(),
        'export_requested',
        'export_ready_csv',
        $recipientHash,
        ['user_id' => $userId, 'export_type' => 'sales']
      );
    }
    catch (\Exception $e) {
      // Audit table may not exist yet - log error but don't break the flow.
      \Drupal::logger('myeventlane_reporting')->warning('Failed to log audit entry: @message', ['@message' => $e->getMessage()]);
    }

    $this->getMessenger()->addStatus($this->t('Sales export requested. You will be notified when it is ready for download.'));

    return new RedirectResponse(Url::fromRoute('myeventlane_reporting.export_centre')->toString());
  }

  /**
   * Gets events owned by the user.
   */
  private function getUserEvents(int $userId): array {
    return $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('uid', $userId)
      ->execute();
  }

}
