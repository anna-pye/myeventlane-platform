<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Retrieves help article excerpts for vendor AI context.
 *
 * Queries help_article nodes with audience vendor or public.
 */
final class HelpArticleRetriever {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Retrieves relevant help articles for a question.
   *
   * @param string $query
   *   The user's question (used for basic keyword matching).
   * @param string $audience
   *   'vendor' or 'public'.
   * @param int $limit
   *   Maximum number of articles to return.
   * @param int $excerptLength
   *   Max characters per excerpt.
   *
   * @return array
   *   Array of ['nid' => int, 'title' => string, 'url' => string, 'excerpt' => string].
   */
  public function retrieve(string $query, string $audience = 'vendor', int $limit = 5, int $excerptLength = 400): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $q = $nodeStorage->getQuery()
      ->condition('type', 'help_article')
      ->condition('status', 1)
      ->accessCheck(FALSE);

    if ($audience === 'public') {
      $q->condition('field_audience', 'public');
    }
    else {
      $q->condition($q->orConditionGroup()
        ->condition('field_audience', 'vendor')
        ->condition('field_audience', 'public'));
    }

    $q->sort('field_priority', 'ASC')
      ->sort('title', 'ASC')
      ->range(0, $limit * 2);

    $nids = $q->execute();
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
