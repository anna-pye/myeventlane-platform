<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManager;
use Drupal\search_api\IndexInterface;
use Psr\Log\LoggerInterface;

/**
 * Retrieves grounded Help Centre sources through Search API.
 */
final class HelpRetriever {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AliasManager $aliasManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns top matching help_article records for a user question.
   *
   * @return array<int, array<string, mixed>>
   *   Search-ranked result items with grounded fields.
   */
  public function retrieve(string $question, int $limit = 5): array {
    $trimmedQuestion = trim($question);
    if ($trimmedQuestion === '') {
      return [];
    }

    $limit = max(3, min($limit, 5));
    try {
      $index = $this->loadContentIndex();
      if (!$index) {
        $this->logger->error('Help Assistant retrieval failed: Search API index "mel_content" was not found.');
        return [];
      }

      return $this->executeQuery($index, $trimmedQuestion, $limit);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Help retriever failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Executes Search API query with retrieval-first field constraints.
   *
   * @return array<int, array<string, mixed>>
   *   Structured result rows.
   */
  private function executeQuery(IndexInterface $index, string $question, int $limit): array {
    $query = $index->query();
    $query->keys($question);
    $query->addCondition('type', 'help_article');
    $query->addCondition('status', 1);
    $query->range(0, $limit);
    $query->sort('search_api_relevance', 'DESC');

    // Prioritise title + summary + body + keywords, per MEL retrieval rules.
    $query->setFulltextFields([
      'title',
      'field_help_summary',
      'body',
      'field_help_keywords',
    ]);

    $results = [];
    foreach ($query->execute()->getResultItems() as $resultItem) {
      $originalObject = $resultItem->getOriginalObject();
      if (!$originalObject) {
        continue;
      }

      $node = $originalObject->getValue();
      if (!$node instanceof NodeInterface || $node->bundle() !== 'help_article') {
        continue;
      }

      $results[] = $this->buildResultItem($node, (float) $resultItem->getScore());
    }

    return $results;
  }

  /**
   * Loads the MEL Search API content index.
   */
  private function loadContentIndex(): ?IndexInterface {
    $index = $this->entityTypeManager->getStorage('search_api_index')->load('mel_content');
    return $index instanceof IndexInterface ? $index : NULL;
  }

  /**
   * Builds a serialisable result item.
   *
   * @return array<string, mixed>
   *   Result payload.
   */
  private function buildResultItem(NodeInterface $node, float $score): array {
    $nid = (int) $node->id();
    $internalPath = '/node/' . $nid;
    $url = $this->aliasManager->getAliasByPath($internalPath);
    if ($url === $internalPath) {
      $url = $node->toUrl()->toString();
    }

    return [
      'nid' => $nid,
      'title' => $node->label(),
      'url' => $url,
      'summary' => $this->extractSummaryText($node),
      'content' => $this->buildExcerpt($node),
      'score' => $score,
    ];
  }

  /**
   * Builds a plain-text content excerpt.
   */
  private function buildExcerpt(NodeInterface $node): string {
    $body = $this->extractBodyText($node);
    $body = preg_replace('/\s+/', ' ', trim($body)) ?? '';

    if (mb_strlen($body) > 1200) {
      return mb_substr($body, 0, 1200) . '...';
    }

    return $body;
  }

  /**
   * Extracts body text safely.
   */
  private function extractBodyText(NodeInterface $node): string {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return '';
    }

    $summary = (string) ($node->get('body')->summary ?? '');
    if ($summary !== '') {
      return trim(strip_tags($summary));
    }

    return trim(strip_tags((string) $node->get('body')->value));
  }

  /**
   * Extracts summary text from field_help_summary or body summary.
   */
  private function extractSummaryText(NodeInterface $node): string {
    if ($node->hasField('field_help_summary') && !$node->get('field_help_summary')->isEmpty()) {
      return trim((string) $node->get('field_help_summary')->value);
    }

    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $summary = trim((string) $node->get('body')->summary);
      if ($summary !== '') {
        return strip_tags($summary);
      }
    }

    return '';
  }

}
