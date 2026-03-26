<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Access;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_refunds\Service\RefundAccessResolver;
use Drupal\myeventlane_refunds\Service\RefundProcessor;
use Drupal\node\NodeInterface;

/**
 * Route access for vendor refund retries.
 */
final class RetryRefundAccessCheck {

  /**
   * Constructs the access checker.
   */
  public function __construct(
    private readonly RefundProcessor $refundProcessor,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RefundAccessResolver $accessResolver,
  ) {}

  /**
   * Checks access for the retry route.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $logId = (int) $route_match->getParameter('log');
    if ($logId <= 0) {
      return AccessResult::forbidden('Refund log parameter required.');
    }

    $log = $this->refundProcessor->loadRefundLog($logId);
    if ($log === NULL) {
      return AccessResult::forbidden('Refund log not found.');
    }

    if ((string) ($log['status'] ?? '') !== RefundProcessor::STATUS_FAILED) {
      return AccessResult::forbidden('Only failed refund logs can be retried.');
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load((int) ($log['order_id'] ?? 0));
    $event = $this->entityTypeManager->getStorage('node')->load((int) ($log['event_id'] ?? 0));
    if (!$order instanceof OrderInterface || !$event instanceof NodeInterface) {
      return AccessResult::forbidden('Refund log references an invalid order or event.');
    }

    return $this->accessResolver->accessRefundOrder($order, $event, $account);
  }

}
