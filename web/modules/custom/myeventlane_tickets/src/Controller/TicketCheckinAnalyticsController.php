<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\EventAccess;
use Drupal\myeventlane_tickets\Service\TicketCheckinLogger;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ticket check-in analytics dashboard controller.
 */
final class TicketCheckinAnalyticsController extends ControllerBase {

  public function __construct(
    private readonly EventAccess $eventAccess,
    private readonly EntityTypeManagerInterface $ticketsEntityTypeManager,
    private readonly TicketCheckinLogger $ticketCheckinLogger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_tickets.event_access'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_tickets.ticket_checkin_logger'),
    );
  }

  /**
   * Analytics page shell.
   */
  public function page(NodeInterface $event): array {
    $this->assertEventAccess($event);
    $event_id = (int) $event->id();

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-checkin-analytics-page']],
      'summary' => [
        '#markup' => '<div class="mel-checkin-cards"><div class="mel-checkin-card"><div class="label">' . $this->t('Issued') . '</div><div id="mel-analytics-issued" class="value">0</div></div><div class="mel-checkin-card"><div class="label">' . $this->t('Assigned') . '</div><div id="mel-analytics-assigned" class="value">0</div></div><div class="mel-checkin-card"><div class="label">' . $this->t('Checked in') . '</div><div id="mel-analytics-checked-in" class="value">0</div></div></div>',
      ],
      'progress' => [
        '#markup' => '<div class="mel-checkin-progress-wrap"><div class="mel-checkin-progress-head"><span>' . $this->t('Progress') . '</span><strong id="mel-analytics-progress-percent">0%</strong></div><div class="mel-checkin-progress"><div id="mel-analytics-progress-bar" class="mel-checkin-progress-bar" style="width:0%"></div></div></div>',
      ],
      'recent' => [
        '#markup' => '<h2>' . $this->t('Last 20 scans') . '</h2><table class="mel-checkin-recent-table"><thead><tr><th>' . $this->t('Time') . '</th><th>' . $this->t('Result') . '</th><th>' . $this->t('Ticket') . '</th><th>' . $this->t('Device') . '</th><th>' . $this->t('User') . '</th></tr></thead><tbody id="mel-analytics-recent-body"><tr><td colspan="5">' . $this->t('Loading...') . '</td></tr></tbody></table>',
      ],
      'breakdowns' => [
        '#markup' => '<div class="mel-checkin-breakdowns"><div><h3>' . $this->t('Top devices') . '</h3><ul id="mel-analytics-devices"><li>' . $this->t('Loading...') . '</li></ul></div><div><h3>' . $this->t('Top users') . '</h3><ul id="mel-analytics-users"><li>' . $this->t('Loading...') . '</li></ul></div></div>',
      ],
    ];
    $build['#attached']['library'][] = 'myeventlane_tickets/checkin_analytics';
    $build['#attached']['drupalSettings']['myeventlaneTicketsCheckinAnalytics'] = [
      'endpoint' => Url::fromRoute('myeventlane_tickets.ticket_checkin_analytics_json', ['event' => $event_id])->toString(),
      'refreshMs' => 5000,
    ];
    return $build;
  }

  /**
   * Live analytics JSON endpoint.
   */
  public function json(NodeInterface $event): JsonResponse {
    $this->assertEventAccess($event);
    $event_id = (int) $event->id();
    $storage = $this->ticketsEntityTypeManager->getStorage('myeventlane_ticket');

    $issued_count = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event_id)
      ->count()
      ->execute();

    $assigned_count = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event_id)
      ->condition('status', [Ticket::STATUS_ASSIGNED, Ticket::STATUS_CHECKED_IN], 'IN')
      ->count()
      ->execute();

    $checked_in_count = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event_id)
      ->condition('status', Ticket::STATUS_CHECKED_IN)
      ->count()
      ->execute();

    $log_counts = $this->ticketCheckinLogger->countsByEvent($event_id);
    $recent = array_values($this->ticketCheckinLogger->recentByEvent($event_id, 20));

    $uids = [];
    foreach ($recent as $row) {
      if (!empty($row['scanned_by_uid'])) {
        $uids[(int) $row['scanned_by_uid']] = (int) $row['scanned_by_uid'];
      }
    }
    $users = $uids ? $this->ticketsEntityTypeManager->getStorage('user')->loadMultiple($uids) : [];

    $recent_out = [];
    foreach ($recent as $row) {
      $uid = !empty($row['scanned_by_uid']) ? (int) $row['scanned_by_uid'] : 0;
      $recent_out[] = [
        'time' => (int) $row['scanned_at'],
        'result' => (string) $row['result'],
        'ticket_label_masked' => (string) ($row['ticket_label_masked'] ?? '----'),
        'device_id_short' => substr((string) ($row['device_id'] ?? 'unknown-device'), 0, 12),
        'user_label' => isset($users[$uid]) ? $users[$uid]->getDisplayName() : 'System',
      ];
    }

    return new JsonResponse([
      'issued_count' => $issued_count,
      'assigned_count' => $assigned_count,
      'checked_in_count' => $checked_in_count,
      'result_breakdown' => $log_counts['result_breakdown'] ?? [],
      'device_breakdown' => $log_counts['device_breakdown'] ?? [],
      'user_breakdown' => $log_counts['user_breakdown'] ?? [],
      'recent' => $recent_out,
    ]);
  }

  /**
   * Enforces event-scope access.
   */
  private function assertEventAccess(NodeInterface $event): void {
    if ($event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    if (!$this->eventAccess->canManageEventTickets($event)) {
      throw new AccessDeniedHttpException();
    }
  }

}
