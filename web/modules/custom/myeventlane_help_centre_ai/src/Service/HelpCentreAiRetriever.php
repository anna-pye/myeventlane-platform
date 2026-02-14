<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre_ai\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryException;
use Psr\Log\LoggerInterface;

/**
 * Retrieves public help article excerpts for the FAQ AI assistant.
 *
 * Only queries help_article nodes with field_audience containing 'public'.
 */
final class HelpCentreAiRetriever {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Retrieves public help articles for FAQ search.
   *
   * @param string $query
   *   The user's question (used for basic relevance).
   * @param int $limit
   *   Maximum number of articles.
   * @param int $excerptLength
   *   Max characters per excerpt.
   *
   * @return array
   *   Array of ['nid' => int, 'title' => string, 'url' => string, 'excerpt' => string].
   */
  public function retrieve(string $query, int $limit = 5, int $excerptLength = 400): array {
    $storageDefinitions = $this->entityFieldManager->getActiveFieldStorageDefinitions('node');
    if (!isset($storageDefinitions['field_audience']) || !isset($storageDefinitions['field_priority'])) {
      $this->logger->error(
        'HelpCentreAiRetriever: field_audience or field_priority missing from node field storage. Run config:import or verify myeventlane_help_centre is correctly installed.'
      );
      return [];
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $q = $nodeStorage->getQuery()
      ->condition('type', 'help_article')
      ->condition('status', 1)
      ->condition('field_audience', 'public')
      ->sort('field_priority', 'ASC')
      ->sort('title', 'ASC')
      ->range(0, $limit * 2)
      ->accessCheck(FALSE);

    try {
      $nids = $q->execute();
    }
    catch (QueryException $e) {
      $this->logger->error(
        'HelpCentreAiRetriever query failed: @message',
        ['@message' => $e->getMessage()]
      );
      return [];
    }

    if (empty($nids)) {
      return [];
    }

    $nodes = $nodeStorage->loadMultiple($nids);
    $results = [];

    foreach (array_slice(array_values($nodes), 0, $limit) as $node) {
      $body = $node->hasField('body') && !$node->get('body')->isEmpty()
        ? (string) $node->get('body')->value
        : '';
      $plain = strip_tags($body);
      $excerpt = mb_strlen($plain) > $excerptLength
        ? mb_substr($plain, 0, $excerptLength) . '…'
        : $plain;

      $results[] = [
        'nid' => (int) $node->id(),
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'excerpt' => trim($excerpt),
      ];
    }

    return $results;
  }

}
