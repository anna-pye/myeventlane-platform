<?php

declare(strict_types=1);

namespace Drupal\myeventlane_seed\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for safely resetting/deleting all Event entities.
 */
final class EventResetService {

  /**
   * Constructs an EventResetService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Deletes ALL Event nodes (bundle 'event').
   *
   * Uses batch deletion to avoid memory issues. Logs counts per bundle.
   *
   * @return array
   *   Statistics: ['deleted' => int, 'bundles' => [bundle => count]]
   */
  public function deleteAllEvents(): array {
    $logger = $this->loggerFactory->get('myeventlane_seed');
    $storage = $this->entityTypeManager->getStorage('node');
    $stats = ['deleted' => 0, 'bundles' => []];

    // Query all event nodes.
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event');

    $nids = $query->execute();
    if (empty($nids)) {
      $logger->info('No events found to delete.');
      return $stats;
    }

    // Batch delete to avoid memory issues.
    $batch_size = 50;
    $nids_chunked = array_chunk($nids, $batch_size);
    $deleted = 0;

    foreach ($nids_chunked as $chunk) {
      $nodes = $storage->loadMultiple($chunk);
      foreach ($nodes as $node) {
        $bundle = $node->bundle();
        $stats['bundles'][$bundle] = ($stats['bundles'][$bundle] ?? 0) + 1;
        $node->delete();
        $deleted++;
      }
    }

    $stats['deleted'] = $deleted;

    $logger->info('Deleted @count event nodes (bundles: @bundles)', [
      '@count' => $deleted,
      '@bundles' => implode(', ', array_map(fn($b, $c) => "$b: $c", array_keys($stats['bundles']), $stats['bundles'])),
    ]);

    return $stats;
  }

}
