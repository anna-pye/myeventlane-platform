<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
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
    private readonly AccountProxyInterface $currentUser,
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
    $fetchCap = max(24, $limit * 6);
    $query = $index->query();
    $query->keys($question);
    $query->addCondition('type', 'help_article');
    $query->addCondition('status', 1);
    $query->range(0, $fetchCap);
    $query->sort('search_api_relevance', 'DESC');

    // Prioritise title + summary + body + keywords, per MEL retrieval rules.
    $query->setFulltextFields([
      'title',
      'field_help_summary',
      'body',
      'field_help_keywords',
    ]);

    // Canonical list field: public / vendor only (never staff playbooks or staff audience).
    $allowedAudiences = $this->allowedAudienceValuesForCurrentUser();
    if ($allowedAudiences !== [] && array_key_exists('field_audience', $index->getFields())) {
      $group = $query->createConditionGroup('OR');
      foreach ($allowedAudiences as $audience) {
        $group->addCondition('field_audience', $audience);
      }
      $query->addConditionGroup($group);
    }

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

      if (!$this->isNodeRetrievableForAssistant($node)) {
        continue;
      }

      $results[] = $this->buildResultItem($node, (float) $resultItem->getScore());
      if (count($results) >= $limit) {
        break;
      }
    }

    return $results;
  }

  /**
   * Allowed field_audience values for Search API pre-filtering.
   *
   * Anonymous: public only. Authenticated: public + vendor. Staff-facing
   * help_article content tagged staff is never returned here.
   *
   * @return list<string>
   */
  private function allowedAudienceValuesForCurrentUser(): array {
    $account = $this->currentUser;
    if ($account->isAuthenticated()) {
      return ['public', 'vendor'];
    }
    return ['public'];
  }

  /**
   * Final safety: node access, AI allow-list, documentation status, audience.
   */
  private function isNodeRetrievableForAssistant(NodeInterface $node): bool {
    if (!$node->isPublished()) {
      return FALSE;
    }
    if (!$node->access('view', $this->currentUser)) {
      return FALSE;
    }
    if (!$node->hasField('field_help_ai_allowed') || $node->get('field_help_ai_allowed')->isEmpty()
      || !(bool) $node->get('field_help_ai_allowed')->value) {
      return FALSE;
    }
    if ($node->hasField('field_help_status') && !$node->get('field_help_status')->isEmpty()) {
      $status = (string) $node->get('field_help_status')->value;
      if (!in_array($status, ['published', 'approved'], TRUE)) {
        return FALSE;
      }
    }
    if (!$this->nodeAudienceAllowedForAssistant($node)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Requires canonical field_audience: only public/vendor, no staff tagging.
   */
  private function nodeAudienceAllowedForAssistant(NodeInterface $node): bool {
    if (!$node->hasField('field_audience') || $node->get('field_audience')->isEmpty()) {
      return FALSE;
    }
    foreach ($node->get('field_audience') as $item) {
      $value = (string) $item->value;
      if ($value === 'staff') {
        return FALSE;
      }
      if (!in_array($value, ['public', 'vendor'], TRUE)) {
        return FALSE;
      }
    }
    $allowed = $this->allowedAudienceValuesForCurrentUser();
    foreach ($node->get('field_audience') as $item) {
      $value = (string) $item->value;
      if (in_array($value, $allowed, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
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

    [$ctaLabel, $ctaUrl] = $this->extractCta($node);

    return [
      'nid' => $nid,
      'title' => $node->label(),
      'url' => $url,
      'summary' => $this->extractSummaryText($node),
      'content' => $this->buildExcerpt($node),
      'score' => $score,
      'cta_label' => $ctaLabel,
      'cta_url' => $ctaUrl,
    ];
  }

  /**
   * @return array{0: string, 1: string}
   *   CTA label and relative URL for grounded action buttons.
   */
  private function extractCta(NodeInterface $node): array {
    $label = '';
    $url = '';
    if ($node->hasField('field_help_cta_label') && !$node->get('field_help_cta_label')->isEmpty()) {
      $label = trim((string) $node->get('field_help_cta_label')->value);
    }
    if ($node->hasField('field_help_cta_link') && !$node->get('field_help_cta_link')->isEmpty()) {
      $link = $node->get('field_help_cta_link')->first();
      if ($link && method_exists($link, 'getUrl')) {
        try {
          $url = $link->getUrl()->setAbsolute(FALSE)->toString();
        }
        catch (\Throwable) {
          $url = '';
        }
      }
    }
    return [$label, $url];
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
