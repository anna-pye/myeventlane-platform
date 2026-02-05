<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_refunds\Service\RefundAccessResolver;
use Drupal\myeventlane_refunds\Service\RefundRequestStorage;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lists buyer-initiated refund requests for vendor approval.
 */
final class VendorRefundRequestsController extends ControllerBase {

  /**
   * Constructs VendorRefundRequestsController.
   *
   * @param \Drupal\myeventlane_refunds\Service\RefundRequestStorage $refundRequestStorage
   *   The refund request storage.
   * @param \Drupal\myeventlane_refunds\Service\RefundAccessResolver $accessResolver
   *   The access resolver.
   * @param \Drupal\myeventlane_vendor\Service\VendorEventTabsService $eventTabsService
   *   The event tabs service.
   */
  public function __construct(
    private readonly RefundRequestStorage $refundRequestStorage,
    private readonly RefundAccessResolver $accessResolver,
    private readonly VendorEventTabsService $eventTabsService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_refunds.refund_request_storage'),
      $container->get('myeventlane_refunds.access_resolver'),
      $container->get('myeventlane_vendor.service.event_tabs'),
    );
  }

  /**
   * Lists pending refund requests for an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return array
   *   Render array.
   */
  public function list(NodeInterface $node): array {
    if (!$this->accessResolver->vendorCanManageEvent($node, $this->currentUser())) {
      throw new AccessDeniedHttpException('You do not have access to view refund requests for this event.');
    }

    $requests = $this->refundRequestStorage->loadPendingByEvent((int) $node->id());

    $rows = [];
    foreach ($requests as $req) {
    $order = $this->entityTypeManager()->getStorage('commerce_order')->load($req['order_id']);
    $buyer = $this->entityTypeManager()->getStorage('user')->load($req['buyer_uid']);
      $amount = number_format($req['amount_cents'] / 100, 2);
      $currency = strtoupper($req['currency']);

      $actions = [
        '#type' => 'container',
        'approve' => [
          '#type' => 'link',
          '#title' => $this->t('Approve'),
          '#url' => Url::fromRoute('myeventlane_refunds.vendor_refund_request_approve', [
            'node' => $node->id(),
            'refund_request' => $req['id'],
          ]),
          '#attributes' => ['class' => ['button', 'button--small']],
        ],
        'reject' => [
          '#type' => 'link',
          '#title' => $this->t('Reject'),
          '#url' => Url::fromRoute('myeventlane_refunds.vendor_refund_request_reject', [
            'node' => $node->id(),
            'refund_request' => $req['id'],
          ]),
          '#attributes' => ['class' => ['button', 'button--small']],
        ],
      ];

      $createdTs = is_numeric($req['created']) ? (int) $req['created'] : (int) strtotime((string) $req['created']);

      $rows[] = [
        $req['id'],
        $order ? $order->getOrderNumber() : '#' . $req['order_id'],
        $buyer ? $buyer->getDisplayName() : $this->t('Unknown'),
        $currency . ' ' . $amount,
        date('M j, Y g:ia', $createdTs),
        ['data' => $actions],
      ];
    }

    $body = [
      '#type' => 'container',
      '#attributes' => ['class' => ['vendor-refund-requests']],
    ];

    $body['header'] = [
      '#type' => 'markup',
      '#markup' => '<h2>' . $this->t('Refund requests for @event', ['@event' => $node->label()]) . '</h2>',
    ];

    if (empty($rows)) {
      $body['empty'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No pending refund requests.') . '</p>',
      ];
    }
    else {
      $body['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('ID'),
          $this->t('Order'),
          $this->t('Buyer'),
          $this->t('Amount'),
          $this->t('Requested'),
          $this->t('Actions'),
        ],
        '#rows' => $rows,
      ];
    }

    $tabs = $this->eventTabsService->getTabs($node, 'refund_requests');

    return [
      '#theme' => 'myeventlane_vendor_console_page',
      '#title' => $node->label() . ' — Refund requests',
      '#tabs' => $tabs,
      '#body' => $body,
      '#attached' => [
        'library' => ['myeventlane_vendor_theme/global-styling'],
      ],
    ];
  }

}
