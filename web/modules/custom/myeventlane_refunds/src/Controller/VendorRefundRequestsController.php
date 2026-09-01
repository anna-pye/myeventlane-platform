<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_refunds\Service\RefundRequestStorage;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists buyer-initiated refund requests for vendor approval.
 */
final class VendorRefundRequestsController extends ControllerBase {

  private const SALES_HELP_PATH = '/help/organisers/managing-event-sales-orders-add-ons-and-refunds';

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
      $createdTs = is_numeric($req['created']) ? (int) $req['created'] : (int) strtotime((string) $req['created']);

      $rows[] = [
        'id' => (int) $req['id'],
        'order_number' => $order ? $order->getOrderNumber() : '#' . $req['order_id'],
        'buyer' => $buyer ? $buyer->getDisplayName() : $this->t('Unknown'),
        'amount' => $currency . ' ' . $amount,
        'requested' => $this->dateFormatter->format($createdTs, 'custom', 'M j, Y g:ia', 'UTC'),
        'approve_url' => $approveUrl->toString(),
        'reject_url' => $rejectUrl->toString(),
        'order_url' => $order ? Url::fromRoute('myeventlane_vendor.console.event_order_view', [
          'event' => $node->id(),
          'order' => $order->id(),
        ])->toString() : NULL,
      ];
    }

    $body = [
      '#theme' => 'mel_vendor_refund_requests',
      '#event' => $node,
      '#requests' => $rows,
      '#help_url' => self::SALES_HELP_PATH,
      '#orders_url' => Url::fromRoute('myeventlane_event_studio.workspace_orders', ['node' => $node->id()])->toString(),
      '#addon_orders_url' => Url::fromRoute('myeventlane_vendor.console.event_operational_addon_orders', ['event' => $node->id()])->toString(),
    ];

    $tabs = $this->eventTabsService->getTabs($node, 'refund_requests');

    return [
      '#theme' => 'mel_event_workspace',
      '#event' => $node,
      '#tabs' => $tabs,
      '#actions' => [],
      '#meta' => NULL,
      '#sidebar' => NULL,
      '#workspace_chrome_after_content' => TRUE,
      '#content' => $body,
      '#attached' => [
        'library' => [
          'myeventlane_vendor_theme/global-styling',
          'myeventlane_refunds/mel_refund_ui',
        ],
      ],
    ];
  }

}
