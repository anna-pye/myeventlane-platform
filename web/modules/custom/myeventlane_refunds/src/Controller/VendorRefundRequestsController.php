<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\myeventlane_refunds\Service\RefundRequestStorage;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists buyer-initiated refund requests for vendor approval.
 */
final class VendorRefundRequestsController extends ControllerBase {

  /**
   * Constructs VendorRefundRequestsController.
   *
   * @param \Drupal\myeventlane_refunds\Service\RefundRequestStorage $refundRequestStorage
   *   The refund request storage.
   * @param \Drupal\myeventlane_vendor\Service\VendorEventTabsService $eventTabsService
   *   The event tabs service.
   */
  public function __construct(
    private readonly RefundRequestStorage $refundRequestStorage,
    private readonly VendorEventTabsService $eventTabsService,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_refunds.refund_request_storage'),
      $container->get('myeventlane_vendor.service.event_tabs'),
      $container->get('date.formatter'),
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
    // Access (manage event + ticketed-only) is enforced by route _custom_access
    // myeventlane_refunds.vendor_refund_request_access:access.

    $requests = $this->refundRequestStorage->loadPendingByEvent((int) $node->id());
    $orderIds = array_values(array_unique(array_map(static fn (array $req): int => (int) $req['order_id'], $requests)));
    $buyerIds = array_values(array_unique(array_map(static fn (array $req): int => (int) $req['buyer_uid'], $requests)));

    $orderStorage = $this->entityTypeManager()->getStorage('commerce_order');
    $userStorage = $this->entityTypeManager()->getStorage('user');
    $orders = $orderIds !== [] ? $orderStorage->loadMultiple($orderIds) : [];
    $buyers = $buyerIds !== [] ? $userStorage->loadMultiple($buyerIds) : [];

    $rows = [];
    foreach ($requests as $req) {
      $order = $orders[(int) $req['order_id']] ?? NULL;
      $buyer = $buyers[(int) $req['buyer_uid']] ?? NULL;
      $amount = number_format($req['amount_cents'] / 100, 2);
      $currency = strtoupper($req['currency']);

      $approveUrl = Url::fromRoute('myeventlane_refunds.vendor_refund_request_approve', [
        'node' => $node->id(),
        'refund_request' => $req['id'],
      ]);
      $rejectUrl = Url::fromRoute('myeventlane_refunds.vendor_refund_request_reject', [
        'node' => $node->id(),
        'refund_request' => $req['id'],
      ]);
      $approveLink = Link::fromTextAndUrl(
        $this->t('Approve'),
        $approveUrl
      )->toRenderable();
      $approveLink['#attributes'] = ['class' => ['button', 'button--small']];
      $rejectLink = Link::fromTextAndUrl(
        $this->t('Reject'),
        $rejectUrl
      )->toRenderable();
      $rejectLink['#attributes'] = ['class' => ['button', 'button--small']];
      $actions = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-action-group']],
        'approve' => $approveLink,
        'reject' => $rejectLink,
      ];

      $createdTs = is_numeric($req['created']) ? (int) $req['created'] : (int) strtotime((string) $req['created']);

      $rows[] = [
        $req['id'],
        $order ? $order->getOrderNumber() : '#' . $req['order_id'],
        $buyer ? $buyer->getDisplayName() : $this->t('Unknown'),
        $currency . ' ' . $amount,
        $this->dateFormatter->format($createdTs, 'custom', 'M j, Y g:ia', 'UTC'),
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
        '#responsive' => FALSE,
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
      '#theme' => 'mel_event_workspace',
      '#event' => $node,
      '#tabs' => $tabs,
      '#actions' => [],
      '#meta' => NULL,
      '#sidebar' => NULL,
      '#content' => $body,
      '#attached' => [
        'library' => ['myeventlane_vendor_theme/global-styling'],
      ],
    ];
  }

}
