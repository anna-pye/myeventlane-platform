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
    $logId = $data['log_id'] ?? NULL;
    if (!$logId) {
      $this->logger->error('VendorRefundWorker: Missing log_id in queue item.');
      return;
    }

    try {
      $this->refundProcessor->processRefund((int) $logId);
    }
    catch (\Exception $e) {
      $this->logger->error('VendorRefundWorker failed for log_id @log_id: @message', [
        '@log_id' => $logId,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

}







