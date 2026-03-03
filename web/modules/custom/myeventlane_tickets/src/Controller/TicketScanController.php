<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_tickets\Service\EventAccess;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Door scanner UI page controller.
 */
final class TicketScanController extends ControllerBase {

  public function __construct(
    private readonly EventAccess $eventAccess,
    private readonly CsrfTokenGenerator $csrfTokenGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_tickets.event_access'),
      $container->get('csrf_token'),
    );
  }

  /**
   * Renders scanner page shell.
   */
  public function page(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    if (!$this->eventAccess->canManageEventTickets($event)) {
      throw new AccessDeniedHttpException();
    }

    $event_id = (int) $event->id();
    $manifest_url = Url::fromRoute('myeventlane_tickets.ticket_checkin_manifest', ['event' => $event_id])->toString();

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-checkin-scanner-page']],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-checkin-scanner-header']],
        'title' => ['#markup' => '<h1>' . $this->t('Door scanner') . '</h1>'],
        'status' => ['#markup' => '<div class="mel-checkin-offline-indicator" id="mel-checkin-offline-indicator">' . $this->t('Online') . '</div>'],
        'queued' => ['#markup' => '<div class="mel-checkin-queued-badge" id="mel-checkin-queued-badge">' . $this->t('Queued: 0') . '</div>'],
      ],
      'camera' => [
        '#markup' => '<div class="mel-checkin-camera-wrap"><div id="mel-checkin-camera" class="mel-checkin-camera"></div><div id="mel-checkin-status-overlay" class="mel-checkin-status-overlay is-idle"><div class="mel-checkin-status-icon" id="mel-checkin-status-icon">...</div><div class="mel-checkin-status-text" id="mel-checkin-status-text">' . $this->t('Ready to scan') . '</div><div class="mel-checkin-status-subtext" id="mel-checkin-status-subtext"></div></div></div>',
      ],
      'manual' => [
        '#markup' => '<form id="mel-checkin-manual-form" class="mel-checkin-manual-form"><label for="mel-checkin-manual-input">' . $this->t('Manual entry') . '</label><input id="mel-checkin-manual-input" type="text" maxlength="512" autocomplete="off" placeholder="' . $this->t('Paste ticket code or payload') . '" /><button type="submit">' . $this->t('Validate') . '</button></form>',
      ],
    ];

    $build['#attached']['library'][] = 'myeventlane_tickets/checkin_scanner';
    $build['#attached']['drupalSettings']['myeventlaneTicketsCheckin'] = [
      'eventId' => $event_id,
      'submitUrl' => Url::fromRoute('myeventlane_tickets.ticket_checkin_api_submit', ['event' => $event_id])->toString(),
      'batchUrl' => Url::fromRoute('myeventlane_tickets.ticket_checkin_api_batch', ['event' => $event_id])->toString(),
      'csrfToken' => $this->csrfTokenGenerator->get('myeventlane_tickets_checkin_api:' . $event_id),
      'swUrl' => Url::fromRoute('myeventlane_tickets.ticket_checkin_sw', ['event' => $event_id])->toString(),
      'swScope' => '/event/' . $event_id . '/tickets/',
      'manifestUrl' => $manifest_url,
    ];
    $build['#attached']['html_head_link'][] = [
      [
        'rel' => 'manifest',
        'href' => $manifest_url,
      ],
      'myeventlane_tickets_checkin_manifest',
    ];

    return $build;
  }

}
