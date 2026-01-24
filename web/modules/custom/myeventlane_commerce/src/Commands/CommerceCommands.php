<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Commands;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for MyEventLane Commerce.
 */
final class CommerceCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The platform fee order processor.
   *
   * @var \Drupal\commerce_order\OrderProcessorInterface
   */
  private OrderProcessorInterface $platformFeeProcessor;

  /**
   * Constructs the commands.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\commerce_order\OrderProcessorInterface $platformFeeProcessor
   *   The platform fee order processor.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    OrderProcessorInterface $platformFeeProcessor,
  ) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
    $this->platformFeeProcessor = $platformFeeProcessor;
  }

  /**
   * Backfills the platform fee on existing (non-draft) orders that lack it.
   *
   * Uses the configured platform fee % from General settings. Only adds the
   * fee when it is missing. Does not change draft or canceled orders.
   *
   * @command mel:commerce-backfill-fees
   * @aliases mel-backfill-fees
   * @usage ddev drush mel:commerce-backfill-fees
   *   Add platform fee to all eligible orders that do not yet have it.
   */
  public function backfillFees(): void {
    $storage = $this->entityTypeManager->getStorage('commerce_order');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', ['draft', 'canceled'], 'NOT IN')
      ->sort('order_id', 'ASC')
      ->execute();

    if (empty($ids)) {
      $this->logger()->success('No non-draft, non-canceled orders to process.');
      return;
    }

    $added = 0;
    $skipped = 0;

    foreach ($storage->loadMultiple($ids) as $order) {
      if (!$order instanceof OrderInterface) {
        continue;
      }

      if ($this->orderHasPlatformFee($order)) {
        $skipped++;
        continue;
      }

      $this->platformFeeProcessor->process($order);

      if ($this->orderHasPlatformFee($order)) {
        $order->save();
        $added++;
        $this->logger()->info(
          'Added platform fee to order @id (@number).',
          ['@id' => $order->id(), '@number' => $order->getOrderNumber() ?: $order->id()]
        );
      }
    }

    $this->logger()->success(
      'Backfill complete: @added order(s) updated, @skipped already had the fee or had no ticket subtotal.',
      ['@added' => $added, '@skipped' => $skipped]
    );
  }

  /**
   * Checks if the order already has the platform fee adjustment.
   */
  private function orderHasPlatformFee(OrderInterface $order): bool {
    foreach ($order->getAdjustments() as $adj) {
      if ($adj->getSourceId() === 'myeventlane_platform_fee') {
        return TRUE;
      }
    }
    return FALSE;
  }

}
