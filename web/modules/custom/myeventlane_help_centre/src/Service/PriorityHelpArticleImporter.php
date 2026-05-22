<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\PathAliasInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Idempotent importer for priority Help Centre articles from YAML export.
 */
final class PriorityHelpArticleImporter {

  private const BUNDLE = 'help_article';

  private const DEFAULT_YAML = 'docs/content-audit/help-article-exports/help-articles-priority-2026-05.yml';

  /**
   * @var array<string, true>
   */
  private const AUDIENCE_KEYS = [
    'public' => TRUE,
    'vendor' => TRUE,
  ];

  /**
   * @var array<string, true>
   */
  private const HELP_STATUSES = [
    'draft' => TRUE,
    'review' => TRUE,
    'approved' => TRUE,
    'published' => TRUE,
    'archived' => TRUE,
  ];

  /**
   * @var array<string, string>
   */
  private const ARTICLE_TYPE_TERM_NAMES = [
    'guide' => 'Guide',
    'faq' => 'FAQ',
    'troubleshooting' => 'Troubleshooting',
    'overview' => 'Overview',
    'policy' => 'Policy',
    'procedure' => 'Procedure',
    'release note' => 'Release note',
    'release_note' => 'Release note',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerInterface $logger,
    private readonly string $appRoot,
  ) {}

  /**
   * Imports or updates priority help articles from the YAML export.
   *
   * @return array{
   *   created: int,
   *   updated: int,
   *   skipped: int,
   *   errors: list<string>,
   *   articles: array<string, array{
   *     action: string,
   *     matched_by: string,
   *     nid: int|null,
   *     source_nid: int|null,
   *     message: string,
   *   }>,
   * }
   */
  public function import(?string $yamlPath = NULL, bool $dryRun = FALSE): array {
    $report = [
      'created' => 0,
      'updated' => 0,
      'skipped' => 0,
      'errors' => [],
      'articles' => [],
    ];

    $resolved = $this->resolveReadablePath($yamlPath ?? self::DEFAULT_YAML);
    if ($resolved === NULL) {
      $message = sprintf('Cannot read priority help article YAML: %s', $yamlPath ?? self::DEFAULT_YAML);
      $report['errors'][] = $message;
      $this->logger->error($message);
      return $report;
    }

    try {
      $parsed = Yaml::parseFile($resolved);
    }
    catch (ParseException $exception) {
      $message = sprintf('Invalid YAML in %s: %s', $resolved, $exception->getMessage());
      $report['errors'][] = $message;
      $this->logger->error($message);
      return $report;
    }

    if (!is_array($parsed)) {
      $message = sprintf('YAML root must be a mapping in %s.', $resolved);
      $report['errors'][] = $message;
      $this->logger->error($message);
      return $report;
    }

    $articles = $parsed['articles'] ?? NULL;
    if (!is_array($articles)) {
      $message = sprintf('YAML must contain a top-level "articles" array in %s.', $resolved);
      $report['errors'][] = $message;
      $this->logger->error($message);
      return $report;
    }

    foreach ($articles as $stableKey => $row) {
      $stableKey = trim((string) $stableKey);
      $this->importArticle($stableKey, $row, $report, $dryRun);
    }

    $this->logger->notice('Priority help article import @mode complete: @created created, @updated updated, @skipped skipped, @errors errors.', [
      '@mode' => $dryRun ? 'dry-run' : 'live',
      '@created' => (string) $report['created'],
      '@updated' => (string) $report['updated'],
      '@skipped' => (string) $report['skipped'],
      '@errors' => (string) count($report['errors']),
    ]);

    return $report;
  }

  /**
   * @param array<string, mixed> $report
   */
  private function importArticle(string $stableKey, mixed $row, array &$report, bool $dryRun): void {
    $articleReport = [
      'action' => 'skipped',
      'matched_by' => '',
      'nid' => NULL,
      'source_nid' => NULL,
      'message' => '',
    ];

    if ($stableKey === '') {
      $articleReport['message'] = 'Article key is empty.';
      $report['errors'][] = $articleReport['message'];
      $report['skipped']++;
      $report['articles'][''] = $articleReport;
      return;
    }

    if (!is_array($row)) {
      $articleReport['message'] = sprintf('Article "%s" must be a mapping.', $stableKey);
      $report['errors'][] = $articleReport['message'];
      $report['skipped']++;
      $report['articles'][$stableKey] = $articleReport;
      return;
    }

    $validation = $this->validateArticleRow($stableKey, $row);
    if ($validation['error'] !== NULL) {
      $articleReport['message'] = $validation['error'];
      $report['errors'][] = sprintf('%s: %s', $stableKey, $validation['error']);
      $report['skipped']++;
      $report['articles'][$stableKey] = $articleReport;
      return;
    }

    /** @var array<string, mixed> $data */
    $data = $validation['data'];
    $articleReport['source_nid'] = $data['source_nid'];

    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $match = $this->findExistingNode($stableKey, (string) $data['alias'], (string) $data['title'], $langcode);

    if ($match['node'] === NULL) {
      $conflict = $this->aliasUsedByOtherNode((string) $data['alias'], 0);
      if ($conflict !== NULL) {
        $articleReport['message'] = $conflict;
        $report['errors'][] = sprintf('%s: %s', $stableKey, $conflict);
        $report['skipped']++;
        $report['articles'][$stableKey] = $articleReport;
        return;
      }

      $termError = $this->validateArticleTypeTerm((string) $data['article_type']);
      if ($termError !== NULL) {
        $articleReport['message'] = $termError;
        $report['errors'][] = sprintf('%s: %s', $stableKey, $termError);
        $report['skipped']++;
        $report['articles'][$stableKey] = $articleReport;
        return;
      }

      if ($dryRun) {
        $articleReport['action'] = 'create';
        $articleReport['matched_by'] = 'created';
        $articleReport['message'] = 'Dry run: would create help_article.';
        $report['created']++;
        $report['articles'][$stableKey] = $articleReport;
        return;
      }

      try {
        $node = $this->entityTypeManager->getStorage('node')->create([
          'type' => self::BUNDLE,
          'title' => $data['title'],
          'langcode' => $langcode,
        ]);
        if (!$node instanceof NodeInterface) {
          throw new \RuntimeException('Failed to create help_article node.');
        }
        $this->applyArticleFields($node, $stableKey, $data);
        $node->save();
        $articleReport['action'] = 'created';
        $articleReport['matched_by'] = 'created';
        $articleReport['nid'] = (int) $node->id();
        $report['created']++;
        $report['articles'][$stableKey] = $articleReport;
        $this->logger->notice('Created help_article @key (nid @nid).', [
          '@key' => $stableKey,
          '@nid' => (string) $node->id(),
        ]);
      }
      catch (\Throwable $exception) {
        $articleReport['message'] = $exception->getMessage();
        $report['errors'][] = sprintf('%s: %s', $stableKey, $exception->getMessage());
        $report['skipped']++;
        $report['articles'][$stableKey] = $articleReport;
        $this->logger->error('Failed creating help_article @key: @message', [
          '@key' => $stableKey,
          '@message' => $exception->getMessage(),
        ]);
      }
      return;
    }

    $node = $match['node'];
    $articleReport['matched_by'] = $match['matched_by'];
    $articleReport['nid'] = (int) $node->id();

    $conflict = $this->aliasUsedByOtherNode((string) $data['alias'], (int) $node->id());
    if ($conflict !== NULL) {
      $articleReport['message'] = $conflict;
      $report['errors'][] = sprintf('%s: %s', $stableKey, $conflict);
      $report['skipped']++;
      $report['articles'][$stableKey] = $articleReport;
      return;
    }

    try {
      if ($this->nodeStateFingerprint($node) === $this->expectedFingerprint($stableKey, $data)) {
        $articleReport['action'] = 'skipped';
        $articleReport['message'] = 'No field changes detected.';
        $report['skipped']++;
        $report['articles'][$stableKey] = $articleReport;
        return;
      }
      if ($dryRun) {
        $articleReport['action'] = 'updated';
        $articleReport['message'] = 'Dry run: would update help_article.';
        $report['updated']++;
        $report['articles'][$stableKey] = $articleReport;
        return;
      }
      $this->applyArticleFields($node, $stableKey, $data);
      $node->save();
      $articleReport['action'] = 'updated';
      $report['updated']++;
      $report['articles'][$stableKey] = $articleReport;
      $this->logger->notice('Updated help_article @key (nid @nid, matched by @by).', [
        '@key' => $stableKey,
        '@nid' => (string) $node->id(),
        '@by' => $match['matched_by'],
      ]);
    }
    catch (\Throwable $exception) {
      $articleReport['message'] = $exception->getMessage();
      $report['errors'][] = sprintf('%s: %s', $stableKey, $exception->getMessage());
      $report['skipped']++;
      $report['articles'][$stableKey] = $articleReport;
      $this->logger->error('Failed updating help_article @key (nid @nid): @message', [
        '@key' => $stableKey,
        '@nid' => (string) $node->id(),
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array{error: string|null, data?: array<string, mixed>}
   */
  private function validateArticleRow(string $stableKey, array $row): array {
    $title = trim((string) ($row['title'] ?? ''));
    if ($title === '') {
      return ['error' => sprintf('Article "%s" is missing title.', $stableKey)];
    }

    $alias = $this->normalizeAlias((string) ($row['alias'] ?? ''));
    if ($alias === '') {
      return ['error' => sprintf('Article "%s" is missing alias.', $stableKey)];
    }

    $audience = strtolower(trim((string) ($row['audience'] ?? '')));
    if ($audience === '' || !isset(self::AUDIENCE_KEYS[$audience])) {
      return ['error' => sprintf('Article "%s" has invalid audience "%s" (allowed: public, vendor).', $stableKey, (string) ($row['audience'] ?? ''))];
    }

    $articleType = strtolower(trim((string) ($row['article_type'] ?? '')));
    if ($articleType === '' || !isset(self::ARTICLE_TYPE_TERM_NAMES[$articleType])) {
      return ['error' => sprintf('Article "%s" has invalid article_type "%s".', $stableKey, (string) ($row['article_type'] ?? ''))];
    }
    if ($articleType === 'procedure') {
      return ['error' => sprintf('Article "%s" uses staff-only article_type procedure; skipped.', $stableKey)];
    }

    $helpStatus = strtolower(trim((string) ($row['help_status'] ?? '')));
    if ($helpStatus === '' || !isset(self::HELP_STATUSES[$helpStatus])) {
      return ['error' => sprintf('Article "%s" has invalid help_status "%s".', $stableKey, (string) ($row['help_status'] ?? ''))];
    }

    if (!array_key_exists('ai_allowed', $row)) {
      return ['error' => sprintf('Article "%s" is missing ai_allowed.', $stableKey)];
    }

    $summary = $this->validateFormattedText($row['summary'] ?? NULL, 'summary');
    if ($summary === NULL) {
      return ['error' => sprintf('Article "%s" has invalid summary shape (requires value and format).', $stableKey)];
    }

    $body = $this->validateFormattedText($row['body'] ?? NULL, 'body');
    if ($body === NULL) {
      return ['error' => sprintf('Article "%s" has invalid body shape (requires value and format).', $stableKey)];
    }

    $sourceNid = $row['source_nid'] ?? NULL;
    $sourceNidInt = NULL;
    if ($sourceNid !== NULL && $sourceNid !== '') {
      if (!is_numeric($sourceNid)) {
        return ['error' => sprintf('Article "%s" has non-numeric source_nid.', $stableKey)];
      }
      $sourceNidInt = (int) $sourceNid;
    }

    return [
      'error' => NULL,
      'data' => [
        'title' => $title,
        'alias' => $alias,
        'audience' => $audience,
        'article_type' => $articleType,
        'help_status' => $helpStatus,
        'ai_allowed' => $this->normalizeBoolean($row['ai_allowed']),
        'summary' => $summary,
        'body' => $body,
        'source_nid' => $sourceNidInt,
      ],
    ];
  }

  /**
   * @param mixed $raw
   *
   * @return array{value: string, format: string}|null
   */
  private function validateFormattedText(mixed $raw, string $label): ?array {
    if (!is_array($raw)) {
      return NULL;
    }
    $value = (string) ($raw['value'] ?? '');
    $format = trim((string) ($raw['format'] ?? ''));
    if ($format === '') {
      return NULL;
    }
    return [
      'value' => $value,
      'format' => $format,
    ];
  }

  /**
   * @param array<string, mixed> $data
   */
  private function applyArticleFields(NodeInterface $node, string $stableKey, array $data): void {
    $node->setTitle((string) $data['title']);
    $this->applyPublishedState($node, (string) $data['help_status']);

    if ($node->hasField('field_help_seed_key')) {
      $node->set('field_help_seed_key', $stableKey);
    }

    if ($node->hasField('field_audience')) {
      $node->set('field_audience', [['value' => (string) $data['audience']]]);
    }

    if ($node->hasField('field_help_article_type')) {
      $termName = self::ARTICLE_TYPE_TERM_NAMES[(string) $data['article_type']];
      $tid = $this->loadTaxonomyTermIdByName('help_article_type', $termName);
      if ($tid === NULL) {
        throw new \RuntimeException(sprintf('help_article_type term "%s" not found.', $termName));
      }
      $node->set('field_help_article_type', ['target_id' => $tid]);
    }

    if ($node->hasField('field_help_status')) {
      $node->set('field_help_status', (string) $data['help_status']);
    }

    if ($node->hasField('field_help_ai_allowed')) {
      $node->set('field_help_ai_allowed', (bool) $data['ai_allowed'] ? 1 : 0);
    }

    if ($node->hasField('field_help_summary')) {
      $node->set('field_help_summary', (string) $data['summary']['value']);
    }

    if ($node->hasField('body')) {
      /** @var array{value: string, format: string} $body */
      $body = $data['body'];
      $node->set('body', [
        'value' => $body['value'],
        'summary' => '',
        'format' => $body['format'],
      ]);
    }

    $this->setAlias($node, (string) $data['alias']);
  }

  private function applyPublishedState(NodeInterface $node, string $helpStatus): void {
    $published = $helpStatus === 'published' || $helpStatus === 'approved';
    if ($helpStatus === 'archived') {
      $published = FALSE;
    }
    $node->setPublished($published);

    if ($node->hasField('moderation_state')) {
      $moderation = match ($helpStatus) {
        'approved' => 'published',
        'archived' => 'archived',
        default => $helpStatus,
      };
      $node->set('moderation_state', $moderation);
    }
  }

  /**
   * @return array{node: NodeInterface|null, matched_by: string}
   */
  private function findExistingNode(string $stableKey, string $alias, string $title, string $langcode): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $bySeed = $nodeStorage->loadByProperties([
      'type' => self::BUNDLE,
      'field_help_seed_key' => $stableKey,
    ]);
    if ($bySeed !== []) {
      $node = reset($bySeed);
      return [
        'node' => $node instanceof NodeInterface ? $node : NULL,
        'matched_by' => 'seed_key',
      ];
    }

    $byAlias = $this->findNodeByAlias($alias, $langcode);
    if ($byAlias instanceof NodeInterface) {
      return [
        'node' => $byAlias,
        'matched_by' => 'alias',
      ];
    }

    $byTitle = $nodeStorage->loadByProperties([
      'type' => self::BUNDLE,
      'title' => $title,
    ]);
    if ($byTitle !== []) {
      $node = reset($byTitle);
      return [
        'node' => $node instanceof NodeInterface ? $node : NULL,
        'matched_by' => 'title',
      ];
    }

    return [
      'node' => NULL,
      'matched_by' => '',
    ];
  }

  private function findNodeByAlias(string $alias, string $langcode): ?NodeInterface {
    $alias = $this->normalizeAlias($alias);
    $pathAliasStorage = $this->entityTypeManager->getStorage('path_alias');
    $ids = $pathAliasStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('alias', $alias)
      ->condition('langcode', [$langcode, 'und'], 'IN')
      ->sort('id', 'ASC')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      $ids = $pathAliasStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('alias', $alias)
        ->sort('id', 'ASC')
        ->range(0, 1)
        ->execute();
    }
    if ($ids === []) {
      return NULL;
    }

    $pathAlias = $pathAliasStorage->load(reset($ids));
    if (!$pathAlias instanceof PathAliasInterface) {
      return NULL;
    }

    $path = $pathAlias->getPath();
    if (!preg_match('#^/node/(\d+)$#', $path, $matches)) {
      return NULL;
    }

    $node = $this->entityTypeManager->getStorage('node')->load((int) $matches[1]);
    if (!$node instanceof NodeInterface || $node->bundle() !== self::BUNDLE) {
      return NULL;
    }

    return $node;
  }

  private function aliasUsedByOtherNode(string $alias, int $excludeNid): ?string {
    $alias = $this->normalizeAlias($alias);
    $pathAliasStorage = $this->entityTypeManager->getStorage('path_alias');
    $ids = $pathAliasStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('alias', $alias)
      ->execute();
    if ($ids === []) {
      return NULL;
    }

    foreach ($pathAliasStorage->loadMultiple($ids) as $pathAlias) {
      if (!$pathAlias instanceof PathAliasInterface) {
        continue;
      }
      $path = $pathAlias->getPath();
      if (!preg_match('#^/node/(\d+)$#', $path, $matches)) {
        continue;
      }
      $nid = (int) $matches[1];
      if ($excludeNid > 0 && $nid === $excludeNid) {
        continue;
      }
      return sprintf('Alias %s is already assigned to node %d.', $alias, $nid);
    }

    return NULL;
  }

  private function setAlias(NodeInterface $node, string $alias): void {
    if ($alias === '' || !$node->hasField('path')) {
      return;
    }
    $node->set('path', [
      'alias' => $this->normalizeAlias($alias),
      'pathauto' => 0,
    ]);
  }

  private function validateArticleTypeTerm(string $articleType): ?string {
    $termName = self::ARTICLE_TYPE_TERM_NAMES[$articleType] ?? '';
    if ($termName === '') {
      return sprintf('help_article_type term mapping missing for "%s".', $articleType);
    }
    if ($this->loadTaxonomyTermIdByName('help_article_type', $termName) === NULL) {
      return sprintf('help_article_type term "%s" not found.', $termName);
    }
    return NULL;
  }

  private function loadTaxonomyTermIdByName(string $vocabularyId, string $name): ?int {
    $name = trim($name);
    if ($name === '') {
      return NULL;
    }
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vocabularyId)
      ->condition('name', $name)
      ->range(0, 1)
      ->execute();
    if ($tids === []) {
      $needle = mb_strtolower($name);
      $candidateIds = $termStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('vid', $vocabularyId)
        ->execute();
      foreach ($termStorage->loadMultiple($candidateIds) as $term) {
        if (mb_strtolower($term->label()) === $needle) {
          return (int) $term->id();
        }
      }
      return NULL;
    }
    return (int) reset($tids);
  }

  private function normalizeAlias(string $alias): string {
    $alias = trim($alias);
    if ($alias === '') {
      return '';
    }
    return str_starts_with($alias, '/') ? $alias : '/' . $alias;
  }

  /**
   * @param array<string, mixed> $data
   */
  private function expectedFingerprint(string $stableKey, array $data): string {
    $termName = self::ARTICLE_TYPE_TERM_NAMES[(string) $data['article_type']];
    $tid = $this->loadTaxonomyTermIdByName('help_article_type', $termName) ?? 0;
    $helpStatus = (string) $data['help_status'];
    $published = $helpStatus === 'published' || $helpStatus === 'approved';
    if ($helpStatus === 'archived') {
      $published = FALSE;
    }
    $moderation = match ($helpStatus) {
      'approved' => 'published',
      'archived' => 'archived',
      default => $helpStatus,
    };

    /** @var array{value: string, format: string} $body */
    $body = $data['body'];

    $parts = [
      'title' => (string) $data['title'],
      'status' => $published ? 1 : 0,
      'seed_key' => $stableKey,
      'audience' => [(string) $data['audience']],
      'article_type' => $tid,
      'help_status' => $helpStatus,
      'ai_allowed' => (bool) $data['ai_allowed'] ? 1 : 0,
      'summary' => (string) $data['summary']['value'],
      'body' => [
        'value' => $body['value'],
        'format' => $body['format'],
      ],
      'path_alias' => $this->normalizeAlias((string) $data['alias']),
      'moderation' => $moderation,
    ];

    return hash('sha256', serialize($parts));
  }

  /**
   * Fingerprints import-managed fields to detect no-op updates.
   */
  private function nodeStateFingerprint(NodeInterface $node): string {
    $audience = [];
    if ($node->hasField('field_audience')) {
      foreach ($node->get('field_audience')->getValue() as $item) {
        $value = (string) ($item['value'] ?? '');
        if ($value !== '') {
          $audience[] = $value;
        }
      }
      sort($audience);
    }

    $body = ['value' => '', 'format' => ''];
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $item = $node->get('body')->first();
      $body = [
        'value' => (string) ($item->value ?? ''),
        'format' => (string) ($item->format ?? ''),
      ];
    }

    $parts = [
      'title' => $node->label(),
      'status' => (int) $node->isPublished(),
      'seed_key' => $node->hasField('field_help_seed_key') ? (string) $node->get('field_help_seed_key')->value : '',
      'audience' => $audience,
      'article_type' => $node->hasField('field_help_article_type') ? (int) $node->get('field_help_article_type')->target_id : 0,
      'help_status' => $node->hasField('field_help_status') ? (string) $node->get('field_help_status')->value : '',
      'ai_allowed' => $node->hasField('field_help_ai_allowed') ? ((bool) $node->get('field_help_ai_allowed')->value ? 1 : 0) : 0,
      'summary' => $node->hasField('field_help_summary') ? (string) $node->get('field_help_summary')->value : '',
      'body' => $body,
      'path_alias' => $node->hasField('path') ? $this->normalizeAlias((string) $node->get('path')->alias) : '',
      'moderation' => $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '',
    ];
    return hash('sha256', serialize($parts));
  }

  private function normalizeBoolean(mixed $value): bool {
    if (is_bool($value)) {
      return $value;
    }
    if (is_int($value) || is_float($value)) {
      return (int) $value === 1;
    }
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], TRUE);
  }

  /**
   * Resolves a project-relative or absolute path to a readable YAML file.
   */
  public function resolveReadablePath(string $source): ?string {
    $source = trim($source);
    if ($source === '') {
      return NULL;
    }

    if (str_contains($source, '://')) {
      $real = $this->fileSystem->realpath($source);
      if ($real !== FALSE && is_readable($real)) {
        return $real;
      }
      $handle = @fopen($source, 'rb');
      if ($handle !== FALSE) {
        fclose($handle);
        return $source;
      }
      return NULL;
    }

    $normalized = str_replace('\\', '/', $source);
    $candidates = [];

    if (str_starts_with($normalized, '/')) {
      $candidates[] = $normalized;
    }
    else {
      $projectRoot = dirname($this->appRoot);
      $candidates[] = $projectRoot . '/' . ltrim($normalized, '/');
      $candidates[] = $this->appRoot . '/' . ltrim($normalized, '/');
      $candidates[] = $normalized;
    }

    foreach ($candidates as $candidate) {
      $resolved = realpath($candidate);
      if ($resolved !== FALSE && is_readable($resolved)) {
        return $resolved;
      }
      if (is_readable($candidate)) {
        return $candidate;
      }
    }

    return NULL;
  }

}
