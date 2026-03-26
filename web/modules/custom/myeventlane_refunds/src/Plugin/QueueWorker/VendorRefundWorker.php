<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_refunds\Service\RefundProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker to process vendor refunds.
 *
 * @QueueWorker(
 *   id = "vendor_refund_worker",
 *   title = @Translation("Vendor Refund Worker"),
 *   cron = {"time" = 60}
 * )
 */
final class VendorRefundWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs VendorRefundWorker.
   *
   * @param array $configuration
   *   Configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\myeventlane_refunds\Service\RefundProcessor $refundProcessor
   *   The refund processor.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    private readonly RefundProcessor $refundProcessor,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_refunds.processor'),
      $container->get('logger.factory')->get('myeventlane_refunds'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $data = is_array($data) ? $data : [];
    $logId = (int) ($data['refund_log_id'] ?? 0);
    if (!$logId) {
      return;
    }

    $log = $this->refundProcessor->loadRefundLog($logId);
    if (!$log) {
      return;
    }

    if (!in_array($log['status'], [
      RefundProcessor::STATUS_PROCESSING,
      RefundProcessor::STATUS_PENDING_CONFIRMATION,
      RefundProcessor::STATUS_PARTIAL,
    ], TRUE)) {
      return;
    }

    if (!empty($log['next_attempt_at']) && time() < (int) $log['next_attempt_at']) {
      return;
    }

    try {
      $result = $this->refundProcessor->processRefund($logId);

      if ($result !== RefundProcessor::STATUS_COMPLETED) {
        $this->refundProcessor->scheduleRetry(
          $logId,
          (int) ($log['retry_count'] ?? 0)
        );
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('VendorRefundWorker failed for log_id @log_id: @message', [
        '@log_id' => $logId,
        '@message' => $e->getMessage(),
      ]);
      $this->refundProcessor->scheduleRetry(
        $logId,
        (int) ($log['retry_count'] ?? 0)
      );
    }
  }

}
