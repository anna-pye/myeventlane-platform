<?php

declare(strict_types=1);

namespace Drupal\myeventlane_docs_importer\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\myeventlane_docs_importer\ValueObject\DocsImportRow;
use Drupal\myeventlane_docs_importer\ValueObject\DocsImportSummary;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Imports MEL documentation CSV rows into help_article and staff_playbook nodes.
 */
final class DocsImportService {

  private const BUNDLE_HELP = 'help_article';

  private const BUNDLE_PLAYBOOK = 'staff_playbook';

  /**
   * @var array<string, true>
   */
  private const AUDIENCE_KEYS = [
    'public' => TRUE,
    'vendor' => TRUE,
    'staff' => TRUE,
  ];

  /**
   * field_help_status allowed values.
   *
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
   * Editorial workflow states on help_article.
   *
   * @var array<string, true>
   */
  private const MODERATION_STATES = [
    'draft' => TRUE,
    'published' => TRUE,
    'review' => TRUE,
    'archived' => TRUE,
  ];

  private EntityStorageInterface $nodeStorage;

  private EntityStorageInterface $termStorage;

  private EntityStorageInterface $userStorage;

  /**
   * @var array<string, bool>
   */
  private array $melFieldExistsCache = [];

  /**
   * @var array<string, bool>
   */
  private array $nodeFieldTableExistsCache = [];

  private bool $loggedMissingInternalOnlyTable = FALSE;

  /**
   * Normalized help_topic term label => first matching tid (built per import).
   *
   * @var array<string, int>|null
   */
  private ?array $helpTopicNormalizedNameIndex = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityDefinitionUpdateManagerInterface $entityDefinitionUpdateManager,
    private readonly Connection $connection,
    private readonly FileSystemInterface $fileSystem,
    private readonly LanguageManagerInterface $languageManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly LoggerInterface $logger,
  ) {
    $this->nodeStorage = $this->entityTypeManager->getStorage('node');
    $this->termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $this->userStorage = $this->entityTypeManager->getStorage('user');
  }

  /**
   * Runs the import for a readable CSV path or stream URI.
   */
  public function import(
    string $resolvedPath,
    int $actorUid,
    bool $updateOnly,
    bool $createMissingTopics,
    bool $dryRun,
    bool $publishOnImport = TRUE,
  ): DocsImportSummary {
    $summary = new DocsImportSummary();
    $actor = $this->userStorage->load($actorUid);
    if (!$actor instanceof UserInterface || $actor->id() < 1) {
      $this->logger->error('MEL docs import aborted: invalid actor uid @uid', ['@uid' => (string) $actorUid]);
      $summary->errored++;
      return $summary;
    }

    $handle = fopen($resolvedPath, 'rb');
    if ($handle === FALSE) {
      $this->logger->error('MEL docs import failed to fopen @path', ['@path' => $resolvedPath]);
      $summary->errored++;
      return $summary;
    }

    try {
      $rawHeader = fgetcsv($handle);
      if ($rawHeader === FALSE) {
        $this->logger->error('MEL docs import: empty CSV.');
        $summary->errored++;
        return $summary;
      }

      $rawHeader[0] = $this->stripBom((string) $rawHeader[0]);
      $columnMap = $this->buildColumnMap($rawHeader);
      $missing = array_values(array_diff($this->requiredHeaders(), array_keys($columnMap)));
      if ($missing !== []) {
        $this->logger->error('MEL docs import: missing columns @cols', ['@cols' => implode(', ', $missing)]);
        $summary->errored++;
        return $summary;
      }

      $rows = [];
      $line = 1;
      while (($data = fgetcsv($handle)) !== FALSE) {
        $line++;
        if ($this->rowIsEmpty($data)) {
          continue;
        }
        $assoc = $this->rowToAssoc($columnMap, $data);
        $rows[] = $this->assocToRow($assoc, $line);
      }
    }
    finally {
      fclose($handle);
    }

    /** @var array<string, int> $melIdToNid */
    $melIdToNid = [];

    $this->helpTopicNormalizedNameIndex = NULL;

    $this->repairMissingFieldInternalOnlyStorage();

    $this->accountSwitcher->switchTo($actor);
    try {
      foreach ($rows as $row) {
        $this->processRowPassOne($row, $updateOnly, $createMissingTopics, $dryRun, $publishOnImport, $summary, $melIdToNid);
      }

      if (!$dryRun) {
        foreach ($rows as $row) {
          $this->processRowPassTwo($row, $melIdToNid, $summary);
        }
      }
      else {
        $this->logger->notice('MEL docs import dry run: skipping related-article pass (requires saved nodes).');
      }
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    $this->logger->notice('MEL docs import complete: @c created, @u updated, @s skipped, @e errors', [
      '@c' => (string) $summary->created,
      '@u' => (string) $summary->updated,
      '@s' => (string) $summary->skipped,
      '@e' => (string) $summary->errored,
    ]);

    return $summary;
  }

  /**
   * @return list<string>
   */
  private function requiredHeaders(): array {
    return [
      'id',
      'title',
      'audience',
      'type',
      'product_area',
      'surface',
      'owner',
      'status',
      'review_cycle',
      'ai_eligible',
      'summary',
      'body',
      'keywords',
      'cta_url',
      'cta_title',
      'related_ids',
      'notes',
    ];
  }

  /**
   * @param array<int, string|null> $rawHeader
   *
   * @return array<string, int>
   */
  private function buildColumnMap(array $rawHeader): array {
    $map = [];
    foreach ($rawHeader as $i => $label) {
      $key = $this->normalizeHeaderKey((string) $label);
      if ($key === '') {
        continue;
      }
      $canonical = $this->canonicalColumnKey($key);
      if ($canonical !== NULL) {
        $map[$canonical] = (int) $i;
      }
    }
    return $map;
  }

  private function normalizeHeaderKey(string $label): string {
    $label = trim($label);
    $label = preg_replace('/\s+/', ' ', $label) ?? $label;
    return strtolower($label);
  }

  private function canonicalColumnKey(string $normalized): ?string {
    return match ($normalized) {
      'id' => 'id',
      'title' => 'title',
      'audience' => 'audience',
      'type' => 'type',
      'product area', 'product_area', 'product-area' => 'product_area',
      'surface' => 'surface',
      'owner' => 'owner',
      'status' => 'status',
      'review cycle', 'review_cycle', 'review-cycle' => 'review_cycle',
      'ai eligible', 'ai_eligible', 'ai-eligible' => 'ai_eligible',
      'summary' => 'summary',
      'body' => 'body',
      'keywords' => 'keywords',
      'cta url', 'cta_url', 'cta-url' => 'cta_url',
      'cta title', 'cta_title', 'cta-title' => 'cta_title',
      'related ids', 'related_ids', 'related-ids' => 'related_ids',
      'notes' => 'notes',
      default => NULL,
    };
  }

  /**
   * @param array<string, int> $columnMap
   * @param array<int, string|null> $row
   *
   * @return array<string, string>
   */
  private function rowToAssoc(array $columnMap, array $row): array {
    $out = [];
    foreach ($columnMap as $key => $index) {
      $out[$key] = trim((string) ($row[$index] ?? ''));
    }
    return $out;
  }

  /**
   * @param array<string, string> $assoc
   */
  private function assocToRow(array $assoc, int $lineNumber): DocsImportRow {
    return new DocsImportRow(
      $lineNumber,
      $this->normalizeImportText((string) ($assoc['id'] ?? '')),
      $this->normalizeImportText((string) ($assoc['title'] ?? '')),
      $this->normalizeImportText((string) ($assoc['audience'] ?? '')),
      $this->normalizeImportText((string) ($assoc['type'] ?? '')),
      $this->normalizeImportText((string) ($assoc['product_area'] ?? '')),
      $this->normalizeImportText((string) ($assoc['surface'] ?? '')),
      $this->normalizeImportText((string) ($assoc['owner'] ?? '')),
      $this->normalizeImportText((string) ($assoc['status'] ?? '')),
      $this->normalizeImportText((string) ($assoc['review_cycle'] ?? '')),
      $this->normalizeImportText((string) ($assoc['ai_eligible'] ?? '')),
      $this->normalizeImportText((string) ($assoc['summary'] ?? '')),
      $this->normalizeImportText((string) ($assoc['body'] ?? '')),
      $this->normalizeImportText((string) ($assoc['keywords'] ?? '')),
      $this->normalizeImportText((string) ($assoc['cta_url'] ?? '')),
      $this->normalizeImportText((string) ($assoc['cta_title'] ?? '')),
      $this->normalizeImportText((string) ($assoc['related_ids'] ?? '')),
      $this->normalizeImportText((string) ($assoc['notes'] ?? '')),
    );
  }

  /**
   * @param array<int, string|null> $row
   */
  private function rowIsEmpty(array $row): bool {
    foreach ($row as $cell) {
      if (trim((string) $cell) !== '') {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * @param array<string, int> $melIdToNid
   */
  private function processRowPassOne(
    DocsImportRow $row,
    bool $updateOnly,
    bool $createMissingTopics,
    bool $dryRun,
    bool $publishOnImport,
    DocsImportSummary $summary,
    array &$melIdToNid,
  ): void {
    $melId = trim($row->melId);
    if ($melId === '') {
      $this->logRowError($row->lineNumber, $melId, 'ID is required.');
      $summary->errored++;
      return;
    }

    $title = trim($row->title);
    if ($title === '') {
      $this->logRowError($row->lineNumber, $melId, 'Title is required.');
      $summary->errored++;
      return;
    }

    $audienceParse = $this->parseAudience($row->audience);
    if (!$audienceParse['ok']) {
      $this->logRowError($row->lineNumber, $melId, (string) $audienceParse['error']);
      $summary->errored++;
      return;
    }
    $audienceKeys = $audienceParse['keys'];

    $bundle = $this->resolveTargetBundle($row);
    $langcode = $this->languageManager->getDefaultLanguage()->getId();

    $conflict = $this->findMelIdConflict($melId, $bundle);
    if ($conflict !== NULL) {
      $this->logRowError($row->lineNumber, $melId, $conflict);
      $summary->errored++;
      return;
    }

    if ($bundle === self::BUNDLE_HELP) {
      $typeName = trim($row->type);
      if ($typeName === '') {
        $this->logRowError($row->lineNumber, $melId, 'Type is required for help_article rows.');
        $summary->errored++;
        return;
      }
      $typeTid = $this->loadTaxonomyTermIdByName('help_article_type', $typeName);
      if ($typeTid === NULL) {
        $this->logRowError($row->lineNumber, $melId, sprintf('Type "%s" is not a term in vocabulary help_article_type.', $typeName));
        $summary->errored++;
        return;
      }

      $productArea = trim($row->productArea);
      if ($productArea === '') {
        $this->logRowError($row->lineNumber, $melId, 'Product Area is required (maps to field_help_topic / help_topic).');
        $summary->errored++;
        return;
      }
      $topicTid = $this->resolveTopicTermId($productArea, $createMissingTopics, $row->lineNumber, $melId);
      if ($topicTid === NULL) {
        $summary->errored++;
        return;
      }

      $surface = trim($row->surface);
      if ($surface === '') {
        $this->logRowError($row->lineNumber, $melId, 'Surface is required (maps to field_help_category / help_categories).');
        $summary->errored++;
        return;
      }
      $categoryTid = $this->resolveCategoryTermId($surface, $createMissingTopics, $row->lineNumber, $melId);
      if ($categoryTid === NULL) {
        $summary->errored++;
        return;
      }

      $statusMap = $this->parseDocumentationStatus($row->status);
      if ($statusMap === NULL && trim($row->status) !== '') {
        $this->logRowError($row->lineNumber, $melId, sprintf('Status "%s" is invalid for field_help_status.', $row->status));
        $summary->errored++;
        return;
      }
      if ($publishOnImport) {
        $statusMap = $this->publishedDocumentationStatusMap();
      }

      $existing = $this->findExistingNode($melId, $title, $bundle, $langcode);
      if ($existing === NULL && $updateOnly) {
        $this->logger->notice('Row @line (@id): update-only skip (no match).', ['@line' => (string) $row->lineNumber, '@id' => $melId]);
        $summary->skipped++;
        return;
      }

      $isNew = $existing === NULL;
      if ($isNew && trim($row->body) === '') {
        $this->logRowError($row->lineNumber, $melId, 'Body is required when creating a new help_article.');
        $summary->errored++;
        return;
      }

      if ($dryRun) {
        $action = $isNew ? 'create' : 'update';
        $summary->{$isNew ? 'created' : 'updated'}++;
        $suffix = $publishOnImport ? ' [published]' : '';
        $summary->dryRunPlanned[] = sprintf('[dry-run] row %d (%s) %s help_article "%s"%s', $row->lineNumber, $melId, $action, $title, $suffix);
        $this->logger->notice('Dry run row @line @id: would @action help_article @title', [
          '@line' => (string) $row->lineNumber,
          '@id' => $melId,
          '@action' => $action,
          '@title' => $title,
        ]);
        return;
      }

      try {
        $node = $existing ?? $this->nodeStorage->create([
          'type' => self::BUNDLE_HELP,
          'title' => $title,
          'langcode' => $langcode,
        ]);
        if (!$node instanceof NodeInterface) {
          throw new \RuntimeException('Failed to load or create help_article node.');
        }

        $this->applyMelId($node, $melId);
        $node->setTitle($title);
        $node->set('field_audience', $this->buildAudienceFieldValues($audienceKeys));
        $node->set('field_help_article_type', ['target_id' => $typeTid]);
        $this->applyBodyIfPresent($node, $row->body, 'full_html', $isNew);

        if (trim($row->summary) !== '') {
          $node->set('field_help_summary', trim($row->summary));
        }

        $node->set('field_help_topic', ['target_id' => $topicTid]);
        $node->set('field_help_category', ['target_id' => $categoryTid]);

        $this->applyHelpStatusAndModeration($node, $statusMap);
        if ($publishOnImport) {
          $node->setPublished(TRUE);
        }
        if ($node->hasField('field_help_ai_allowed')) {
          $node->set('field_help_ai_allowed', $this->parseBoolean($row->aiEligible));
        }

        $ownerId = $this->resolveOwnerUserId($row->owner);
        if ($ownerId !== NULL && $node->hasField('field_content_owner')) {
          $node->set('field_content_owner', ['target_id' => $ownerId]);
        }

        if (trim($row->keywords) !== '' && $node->hasField('field_help_keywords')) {
          $node->set('field_help_keywords', trim($row->keywords));
        }

        $this->applyCtaFields($node, $row->ctaUrl, $row->ctaTitle);

        $reviewed = $this->parseReviewDate($row->reviewCycle);
        if ($reviewed !== NULL && $node->hasField('field_last_reviewed')) {
          $node->set('field_last_reviewed', $reviewed);
        }

        $violations = $node->validate();
        if ($violations->count() > 0) {
          $this->logValidationViolations($row->lineNumber, $melId, $violations);
          $summary->errored++;
          return;
        }

        $node->save();
        $melIdToNid[$melId] = (int) $node->id();
        if ($isNew) {
          $summary->created++;
          $this->logger->notice('Row @line @id: created help_article nid @nid', [
            '@line' => (string) $row->lineNumber,
            '@id' => $melId,
            '@nid' => (string) $node->id(),
          ]);
        }
        else {
          $summary->updated++;
          $this->logger->notice('Row @line @id: updated help_article nid @nid', [
            '@line' => (string) $row->lineNumber,
            '@id' => $melId,
            '@nid' => (string) $node->id(),
          ]);
        }
      }
      catch (\Throwable $e) {
        $this->logRowException($row->lineNumber, $melId, $e);
        $summary->errored++;
        return;
      }

      return;
    }

    // staff_playbook path.
    $body = trim($row->body);
    if ($body === '') {
      $this->logRowError($row->lineNumber, $melId, 'Body is required for staff_playbook rows.');
      $summary->errored++;
      return;
    }

    $statusMap = $this->parseDocumentationStatus($row->status);
    if ($statusMap === NULL && trim($row->status) !== '') {
      $this->logRowError($row->lineNumber, $melId, sprintf('Status "%s" is invalid.', $row->status));
      $summary->errored++;
      return;
    }
    if ($publishOnImport) {
      $statusMap = $this->publishedDocumentationStatusMap();
    }

    $existing = $this->findExistingNode($melId, $title, $bundle, $langcode);
    if ($existing === NULL && $updateOnly) {
      $this->logger->notice('Row @line (@id): update-only skip (no match).', ['@line' => (string) $row->lineNumber, '@id' => $melId]);
      $summary->skipped++;
      return;
    }

    $isNew = $existing === NULL;

    if ($dryRun) {
      $action = $isNew ? 'create' : 'update';
      $summary->{$isNew ? 'created' : 'updated'}++;
      $suffix = $publishOnImport ? ' [published]' : '';
      $summary->dryRunPlanned[] = sprintf('[dry-run] row %d (%s) %s staff_playbook "%s"%s', $row->lineNumber, $melId, $action, $title, $suffix);
      return;
    }

    try {
      $node = $existing ?? $this->nodeStorage->create([
        'type' => self::BUNDLE_PLAYBOOK,
        'title' => $title,
        'langcode' => $langcode,
      ]);
      if (!$node instanceof NodeInterface) {
        throw new \RuntimeException('Failed to load or create staff_playbook node.');
      }

      $this->applyMelId($node, $melId);
      $node->setTitle($title);
      $node->set('body', [
        'value' => $body,
        'summary' => '',
        'format' => 'basic_html',
      ]);

      if ($node->hasField('field_internal_only')) {
        if ($this->nodeFieldDataTableExists('field_internal_only')) {
          $node->set('field_internal_only', TRUE);
        }
        else {
          // Clear any default-applied value so dedicated-table storage does not
          // INSERT into a missing node__field_internal_only table.
          $node->set('field_internal_only', []);
          if (!$this->loggedMissingInternalOnlyTable) {
            $this->loggedMissingInternalOnlyTable = TRUE;
            $this->logger->warning('field_internal_only is configured but its database table is missing; skipping that field for staff_playbook imports. Run `drush updatedb` or import field storage config, then rebuild caches.');
          }
        }
      }

      if ($node->hasField('field_playbook_type')) {
        // Vocabulary has no "procedure" value; "other" is the closest bucket.
        $node->set('field_playbook_type', [['value' => 'other']]);
      }

      if (trim($row->summary) !== '' && $node->hasField('field_ai_summary')) {
        $node->set('field_ai_summary', trim($row->summary));
      }

      $this->applyPlaybookPublishedState($node, $statusMap);

      $violations = $node->validate();
      if ($violations->count() > 0) {
        $this->logValidationViolations($row->lineNumber, $melId, $violations);
        $summary->errored++;
        return;
      }

      $node->save();
      $melIdToNid[$melId] = (int) $node->id();

      if ($isNew) {
        $summary->created++;
        $this->logger->notice('Row @line @id: created staff_playbook nid @nid', [
          '@line' => (string) $row->lineNumber,
          '@id' => $melId,
          '@nid' => (string) $node->id(),
        ]);
      }
      else {
        $summary->updated++;
        $this->logger->notice('Row @line @id: updated staff_playbook nid @nid', [
          '@line' => (string) $row->lineNumber,
          '@id' => $melId,
          '@nid' => (string) $node->id(),
        ]);
      }
    }
    catch (\Throwable $e) {
      $this->logRowException($row->lineNumber, $melId, $e);
      $summary->errored++;
      return;
    }

    return;
  }

  /**
   * Resolves field_related_articles for help_article rows.
   *
   * @param array<string, int> $melIdToNid
   */
  private function processRowPassTwo(DocsImportRow $row, array $melIdToNid, DocsImportSummary $summary): void {
    if ($this->resolveTargetBundle($row) !== self::BUNDLE_HELP) {
      return;
    }
    $melId = trim($row->melId);
    if ($melId === '' || trim($row->relatedIds) === '') {
      return;
    }
    if (!isset($melIdToNid[$melId])) {
      return;
    }
    $nid = $melIdToNid[$melId];
    $node = $this->nodeStorage->load($nid);
    if (!$node instanceof NodeInterface || !$node->hasField('field_related_articles')) {
      return;
    }

    $targets = [];
    foreach ($this->splitRelatedMelIds($row->relatedIds) as $relatedId) {
      if ($relatedId === $melId) {
        continue;
      }
      $targetNid = $melIdToNid[$relatedId] ?? $this->loadHelpArticleNidByMelId($relatedId);
      if ($targetNid !== NULL) {
        $targets[$targetNid] = ['target_id' => $targetNid];
      }
      else {
        $this->logger->warning('Row @line @id: related MEL ID @rel not found.', [
          '@line' => (string) $row->lineNumber,
          '@id' => $melId,
          '@rel' => $relatedId,
        ]);
      }
    }

    if ($targets === []) {
      return;
    }

    try {
      $node->set('field_related_articles', array_values($targets));
      // Pass two only updates references; core entity save does not run full
      // form validation. Legacy nodes should still persist.
      $node->save();
      $this->logger->notice('Row @line @id: updated related articles on nid @nid', [
        '@line' => (string) $row->lineNumber,
        '@id' => $melId,
        '@nid' => (string) $nid,
      ]);
    }
    catch (\Throwable $e) {
      $this->logRowException($row->lineNumber, $melId, $e);
      $summary->errored++;
    }
  }

  private function resolveTargetBundle(DocsImportRow $row): string {
    $type = strtolower(trim($row->type));
    if ($type === 'procedure' && $this->audienceIsStaffOnly($row->audience)) {
      return self::BUNDLE_PLAYBOOK;
    }
    return self::BUNDLE_HELP;
  }

  private function audienceIsStaffOnly(string $raw): bool {
    $parse = $this->parseAudience($raw);
    if (!$parse['ok']) {
      return FALSE;
    }
    return $parse['keys'] === ['staff'];
  }

  /**
   * @return array{ok: bool, keys?: list<string>, error?: string}
   */
  private function parseAudience(string $raw): array {
    if (trim($raw) === '') {
      return [
        'ok' => FALSE,
        'error' => 'Audience is required (public, vendor, staff).',
      ];
    }
    $parts = preg_split('/[,|]/', $raw) ?: [];
    $keys = [];
    foreach ($parts as $part) {
      $trimmed = strtolower(trim((string) $part));
      if ($trimmed === '') {
        continue;
      }
      if (!isset(self::AUDIENCE_KEYS[$trimmed])) {
        return [
          'ok' => FALSE,
          'error' => sprintf('Invalid audience "%s". Allowed: public, vendor, staff.', $part),
        ];
      }
      $keys[$trimmed] = TRUE;
    }
    if ($keys === []) {
      return [
        'ok' => FALSE,
        'error' => 'Audience is required (public, vendor, staff).',
      ];
    }
    ksort($keys);
    return ['ok' => TRUE, 'keys' => array_keys($keys)];
  }

  /**
   * @param list<string> $keys
   *
   * @return list<array{value: string}>
   */
  private function buildAudienceFieldValues(array $keys): array {
    $out = [];
    foreach ($keys as $key) {
      $out[] = ['value' => $key];
    }
    return $out;
  }

  /**
   * @return array{help_status: string, moderation: string}|null
   */
  /**
   * @return array{help_status: string, moderation: string}
   */
  private function publishedDocumentationStatusMap(): array {
    return [
      'help_status' => 'published',
      'moderation' => 'published',
    ];
  }

  private function parseDocumentationStatus(string $raw): ?array {
    $t = strtolower(trim($raw));
    if ($t === '') {
      return [
        'help_status' => 'draft',
        'moderation' => 'draft',
      ];
    }
    if (!isset(self::HELP_STATUSES[$t])) {
      return NULL;
    }
    $moderation = match ($t) {
      'approved' => 'published',
      default => $t,
    };
    if (!isset(self::MODERATION_STATES[$moderation])) {
      return NULL;
    }
    return [
      'help_status' => $t,
      'moderation' => $moderation,
    ];
  }

  /**
   * @param array{help_status: string, moderation: string}|null $map
   */
  private function applyHelpStatusAndModeration(NodeInterface $node, ?array $map): void {
    if ($map === NULL) {
      $map = [
        'help_status' => 'draft',
        'moderation' => 'draft',
      ];
    }
    if ($node->hasField('field_help_status')) {
      $node->set('field_help_status', $map['help_status']);
    }
    if ($node->hasField('moderation_state')) {
      $node->set('moderation_state', $map['moderation']);
    }
  }

  /**
   * @param array{help_status: string, moderation: string}|null $map
   */
  private function applyPlaybookPublishedState(NodeInterface $node, ?array $map): void {
    if ($map === NULL) {
      $map = [
        'help_status' => 'draft',
        'moderation' => 'draft',
      ];
    }
    $published = in_array($map['help_status'], ['published', 'approved'], TRUE);
    if ($map['help_status'] === 'archived') {
      $published = FALSE;
    }
    $node->setPublished($published);
  }

  private function parseBoolean(string $raw): bool {
    $v = strtolower(trim($raw));
    if ($v === '') {
      return FALSE;
    }
    return in_array($v, ['1', 'true', 'yes', 'y', 'on'], TRUE);
  }

  private function applyBodyIfPresent(NodeInterface $node, string $body, string $format, bool $isNew): void {
    $trimmed = trim($body);
    if ($trimmed !== '') {
      $node->set('body', [
        'value' => $trimmed,
        'summary' => '',
        'format' => $format,
      ]);
      return;
    }
    if ($isNew) {
      $node->set('body', [
        'value' => '',
        'summary' => '',
        'format' => $format,
      ]);
    }
  }

  private function applyCtaFields(NodeInterface $node, string $ctaUrl, string $ctaTitle): void {
    if (!$node->hasField('field_help_cta_link')) {
      return;
    }
    $uri = $this->normalizeLinkUri($ctaUrl);
    if ($uri === NULL) {
      $node->set('field_help_cta_link', []);
      return;
    }
    $link = ['uri' => $uri];
    $title = trim($ctaTitle);
    $titleMode = (int) $node->getFieldDefinition('field_help_cta_link')->getSetting('title');
    if ($title !== '' && $titleMode !== 0) {
      $link['title'] = $title;
    }
    $node->set('field_help_cta_link', $link);
    if ($title !== '' && $node->hasField('field_help_cta_label')) {
      $node->set('field_help_cta_label', $title);
    }
  }

  private function normalizeLinkUri(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
      return NULL;
    }
    if (preg_match('#^https?://#i', $raw)) {
      return $raw;
    }
    if (str_starts_with($raw, '/')) {
      return 'internal:' . $raw;
    }
    if (str_starts_with($raw, 'internal:')) {
      return $raw;
    }
    if (str_starts_with($raw, 'mailto:')) {
      return $raw;
    }
    return 'https://' . $raw;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function parseReviewDate(string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '') {
      return NULL;
    }
    $ts = strtotime($raw);
    if ($ts === FALSE) {
      return NULL;
    }
    return [
      'value' => gmdate('Y-m-d\TH:i:s', $ts),
    ];
  }

  private function applyMelId(NodeInterface $node, string $melId): void {
    if ($node->hasField('field_mel_register_id')) {
      $node->set('field_mel_register_id', $melId);
    }
    else {
      $this->logger->warning('field_mel_register_id missing on bundle @bundle; install myeventlane_docs_importer config for stable matching.', [
        '@bundle' => $node->bundle(),
      ]);
    }
  }

  private function melRegisterFieldExistsOnBundle(string $bundle): bool {
    if (!isset($this->melFieldExistsCache[$bundle])) {
      $defs = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
      $this->melFieldExistsCache[$bundle] = isset($defs['field_mel_register_id']);
    }
    return $this->melFieldExistsCache[$bundle];
  }

  private function findExistingNode(string $melId, string $title, string $bundle, string $langcode): ?NodeInterface {
    if ($this->melRegisterFieldExistsOnBundle($bundle)) {
      $byMel = $this->loadNodeByMelIdInBundle($melId, $bundle, $langcode);
      if ($byMel instanceof NodeInterface) {
        return $byMel;
      }
    }
    return $this->loadNodeByTitle($title, $bundle, $langcode);
  }

  private function loadNodeByMelIdInBundle(string $melId, string $bundle, string $langcode): ?NodeInterface {
    $ids = $this->nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('field_mel_register_id', $melId)
      ->condition('langcode', $langcode)
      ->sort('nid', 'ASC')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $id = (int) reset($ids);
    $node = $this->nodeStorage->load($id);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  private function loadHelpArticleNidByMelId(string $melId): ?int {
    if (!$this->melRegisterFieldExistsOnBundle(self::BUNDLE_HELP)) {
      return NULL;
    }
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $node = $this->loadNodeByMelIdInBundle($melId, self::BUNDLE_HELP, $langcode);
    return $node instanceof NodeInterface ? (int) $node->id() : NULL;
  }

  private function loadNodeByTitle(string $title, string $bundle, string $langcode): ?NodeInterface {
    $ids = $this->nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('title', $title)
      ->condition('langcode', $langcode)
      ->sort('nid', 'ASC')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $id = (int) reset($ids);
    $node = $this->nodeStorage->load($id);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Ensures the MEL ID is not already used on a different bundle or duplicate rows.
   */
  private function findMelIdConflict(string $melId, string $intendedBundle): ?string {
    if (!$this->melRegisterFieldExistsOnBundle(self::BUNDLE_HELP) && !$this->melRegisterFieldExistsOnBundle(self::BUNDLE_PLAYBOOK)) {
      return NULL;
    }
    $ids = $this->nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', [self::BUNDLE_HELP, self::BUNDLE_PLAYBOOK], 'IN')
      ->condition('field_mel_register_id', $melId)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    if (count($ids) > 1) {
      return sprintf('Multiple nodes already use MEL ID %s; resolve duplicates manually.', $melId);
    }
    $node = $this->nodeStorage->load(reset($ids));
    if (!$node instanceof NodeInterface) {
      return NULL;
    }
    if ($node->bundle() !== $intendedBundle) {
      return sprintf('MEL ID %s already exists on %s but this row targets %s.', $melId, $node->bundle(), $intendedBundle);
    }
    return NULL;
  }

  private function resolveTopicTermId(string $name, bool $allowCreate, int $line, string $melId): ?int {
    foreach ($this->helpTopicNameCandidates($name) as $candidate) {
      $existing = $this->loadTaxonomyTermIdByName('help_topic', $candidate);
      if ($existing !== NULL) {
        return $existing;
      }
    }

    $this->ensureHelpTopicNormalizedNameIndex();
    foreach ($this->helpTopicNormalizedLookupKeys($name) as $normKey) {
      if ($normKey !== '' && isset($this->helpTopicNormalizedNameIndex[$normKey])) {
        return $this->helpTopicNormalizedNameIndex[$normKey];
      }
    }

    if (!$allowCreate) {
      $this->logRowError($line, $melId, sprintf('Product Area "%s" not found in help_topic (use --create-missing-topics).', $name));
      return NULL;
    }
    return $this->createTerm('help_topic', trim($name));
  }

  /**
   * Builds a map of normalized term names for help_topic (once per import).
   */
  private function ensureHelpTopicNormalizedNameIndex(): void {
    if ($this->helpTopicNormalizedNameIndex !== NULL) {
      return;
    }
    $this->helpTopicNormalizedNameIndex = [];
    $ids = $this->termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'help_topic')
      ->execute();
    foreach ($this->termStorage->loadMultiple($ids) as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }
      $norm = $this->normalizeHelpTopicKey($term->getName());
      if ($norm === '') {
        continue;
      }
      if (!isset($this->helpTopicNormalizedNameIndex[$norm])) {
        $this->helpTopicNormalizedNameIndex[$norm] = (int) $term->id();
      }
    }
  }

  /**
   * Normalizes a product-area string for comparison with help_topic term names.
   */
  private function normalizeHelpTopicKey(string $label): string {
    $label = trim($label);
    if ($label === '') {
      return '';
    }
    $label = mb_strtolower($label);
    $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $label = str_replace('&', ' and ', $label);
    $label = preg_replace('/[^a-z0-9]+/u', '_', $label) ?? '';
    $label = trim($label, '_');
    while (str_contains($label, '__')) {
      $label = str_replace('__', '_', $label);
    }
    return $label;
  }

  /**
   * @return list<string>
   */
  private function helpTopicNormalizedLookupKeys(string $raw): array {
    $keys = [];
    foreach ($this->helpTopicNameCandidates($raw) as $candidate) {
      $k = $this->normalizeHelpTopicKey($candidate);
      if ($k !== '') {
        $keys[] = $k;
      }
    }
    return array_values(array_unique($keys));
  }

  /**
   * CSV "Product Area" values and human-readable synonyms to try against help_topic.
   *
   * @return list<string>
   */
  private function helpTopicNameCandidates(string $raw): array {
    $raw = trim($raw);
    $out = [$raw];
    if ($raw === '') {
      return $out;
    }
    $key = strtolower(str_replace([' ', '-'], '_', $raw));
    $aliases = [
      'trust' => ['Trust', 'Trust & safety', 'Trust and safety', 'Policies', 'Safety'],
      // MEL docs register slugs: align with HelpContentSeeder topics and live site slugs.
      'events' => [
        'Check-in',
        'Tickets',
        'RSVP',
        'checkout',
        'discovery',
        'Events',
        'Event',
        'Event management',
        'Managing events',
      ],
      'event_management' => [
        'dashboard',
        'event_wizard',
        'ticketing',
        'analytics',
        'Event creation',
        'Event management',
        'Events',
        'Event',
        'Managing events',
      ],
      'event_setup' => [
        'event_wizard',
        'ticketing',
        'Event creation',
        'Ticket setup',
        'discovery',
        'Event setup',
        'Event Setup',
        'Events',
        'Event management',
        'Managing events',
      ],
      'communication' => [
        'dashboard',
        'ticketing',
        'growth',
        'Tickets',
        'Ticket setup',
        'Communication',
        'Communications',
        'Messaging',
        'Email',
        'Notifications',
      ],
      'growth' => ['Growth', 'Promotion', 'Marketing', 'Visibility'],
    ];
    if (isset($aliases[$key])) {
      foreach ($aliases[$key] as $alias) {
        $out[] = $alias;
      }
    }
    $normalized = ucfirst(str_replace('_', ' ', $key));
    if ($normalized !== $raw) {
      $out[] = $normalized;
    }
    return array_values(array_unique($out));
  }

  private function resolveCategoryTermId(string $name, bool $allowCreate, int $line, string $melId): ?int {
    foreach ($this->helpCategoryNameCandidates($name) as $candidate) {
      $existing = $this->loadTaxonomyTermIdByName('help_categories', $candidate);
      if ($existing !== NULL) {
        return $existing;
      }
    }
    if (!$allowCreate) {
      $this->logRowError($line, $melId, sprintf('Surface "%s" not found in help_categories (use --create-missing-topics).', $name));
      return NULL;
    }
    return $this->createTerm('help_categories', trim($name));
  }

  /**
   * Surface strings may include composite values (e.g. "help_centre+checkout").
   *
   * @return list<string>
   */
  private function helpCategoryNameCandidates(string $raw): array {
    $raw = trim($raw);
    $out = [$raw];
    if ($raw === '') {
      return $out;
    }
    if (str_contains($raw, '+')) {
      foreach (preg_split('/\s*\+\s*/', $raw) ?: [] as $part) {
        $part = trim((string) $part);
        if ($part !== '') {
          $out[] = $part;
        }
      }
    }
    $key = strtolower(str_replace([' ', '-'], '_', $raw));
    $aliases = [
      'help_centre' => ['Help centre', 'Help Center', 'Help'],
      'checkout' => ['Checkout', 'Ticketing'],
    ];
    if (isset($aliases[$key])) {
      foreach ($aliases[$key] as $alias) {
        $out[] = $alias;
      }
    }
    return array_values(array_unique($out));
  }

  /**
   * TRUE when the dedicated field data table exists (config can exist without schema).
   */
  private function nodeFieldDataTableExists(string $fieldName): bool {
    if (!isset($this->nodeFieldTableExistsCache[$fieldName])) {
      $this->nodeFieldTableExistsCache[$fieldName] = $this->connection->schema()->tableExists('node__' . $fieldName);
    }
    return $this->nodeFieldTableExistsCache[$fieldName];
  }

  /**
   * Creates node__field_internal_only when FieldStorageConfig exists but tables do not.
   *
   * Any load() of staff_playbook runs SQL against all configured fields; missing
   * dedicated tables abort the request before our save-time empty-field workaround.
   */
  private function repairMissingFieldInternalOnlyStorage(): void {
    if ($this->connection->schema()->tableExists('node__field_internal_only')) {
      return;
    }

    $storage = FieldStorageConfig::load('node.field_internal_only');
    if (!$storage instanceof FieldStorageConfig) {
      return;
    }

    try {
      $this->entityDefinitionUpdateManager->installFieldStorageDefinition(
        $storage->getName(),
        $storage->getTargetEntityTypeId(),
        $storage->getProvider(),
        $storage,
      );
      $this->logger->notice('MEL docs import: installed missing database tables for field_internal_only (config present without schema).');
    }
    catch (\Throwable $e) {
      $this->logger->error('MEL docs import: could not create node__field_internal_only: @message', [
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    unset($this->nodeFieldTableExistsCache['field_internal_only']);
  }

  private function createTerm(string $vid, string $name): ?int {
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $term = $this->termStorage->create([
      'vid' => $vid,
      'name' => $name,
      'langcode' => $langcode,
    ]);
    if (!$term instanceof TermInterface) {
      return NULL;
    }
    $violations = $term->validate();
    if ($violations->count() > 0) {
      foreach ($violations as $violation) {
        $this->logger->error('Term validation (@vid): @m', [
          '@vid' => $vid,
          '@m' => (string) $violation->getMessage(),
        ]);
      }
      return NULL;
    }
    $term->save();
    if ($vid === 'help_topic') {
      $this->helpTopicNormalizedNameIndex = NULL;
    }
    $this->logger->notice('Created @vid term @tid: @name', [
      '@vid' => $vid,
      '@tid' => (string) $term->id(),
      '@name' => $name,
    ]);
    return (int) $term->id();
  }

  private function loadTaxonomyTermIdByName(string $vid, string $name): ?int {
    $name = trim($name);
    if ($name === '') {
      return NULL;
    }
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $tids = $this->termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vid)
      ->condition('name', $name)
      ->condition('langcode', $langcode)
      ->range(0, 1)
      ->execute();
    if ($tids === []) {
      $tids = $this->termStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('vid', $vid)
        ->condition('name', $name)
        ->range(0, 1)
        ->execute();
    }
    if ($tids === []) {
      return $this->loadTaxonomyTermIdByNameCaseInsensitive($vid, $name);
    }
    $tid = (int) reset($tids);
    return $tid > 0 ? $tid : NULL;
  }

  private function loadTaxonomyTermIdByNameCaseInsensitive(string $vid, string $name): ?int {
    $needle = mb_strtolower(trim($name));
    if ($needle === '') {
      return NULL;
    }
    $candidateIds = $this->termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vid)
      ->execute();
    if ($candidateIds === []) {
      return NULL;
    }
    foreach ($this->termStorage->loadMultiple($candidateIds) as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }
      if (mb_strtolower($term->getName()) === $needle) {
        return (int) $term->id();
      }
    }
    return NULL;
  }

  private function resolveOwnerUserId(string $raw): ?int {
    $raw = trim($raw);
    if ($raw === '') {
      return NULL;
    }
    $byMail = $this->userStorage->loadByProperties(['mail' => $raw]);
    $user = reset($byMail);
    if ($user instanceof UserInterface) {
      return (int) $user->id();
    }
    $byName = $this->userStorage->loadByProperties(['name' => $raw]);
    $user = reset($byName);
    if ($user instanceof UserInterface) {
      return (int) $user->id();
    }
    $this->logger->warning('Owner "@owner" did not match a username or email; leaving content owner empty.', ['@owner' => $raw]);
    return NULL;
  }

  /**
   * @return list<string>
   */
  private function splitRelatedMelIds(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') {
      return [];
    }
    $parts = preg_split('/\s*,\s*/', $raw) ?: [];
    $out = [];
    foreach ($parts as $part) {
      $id = trim((string) $part);
      if ($id !== '') {
        $out[] = $id;
      }
    }
    return $out;
  }

  private function logRowError(int $line, string $melId, string $message): void {
    $this->logger->error('MEL docs import row @line (@id): @msg', [
      '@line' => (string) $line,
      '@id' => $melId,
      '@msg' => $message,
    ]);
  }

  private function logRowException(int $line, string $melId, \Throwable $e): void {
    $this->logger->error('MEL docs import row @line (@id) exception: @msg', [
      '@line' => (string) $line,
      '@id' => $melId,
      '@msg' => $e->getMessage(),
    ]);
  }

  private function logValidationViolations(int $line, string $melId, ConstraintViolationListInterface $violations): void {
    $messages = [];
    foreach ($violations as $violation) {
      $path = $violation->getPropertyPath();
      $messages[] = ($path !== '' ? $path . ': ' : '') . (string) $violation->getMessage();
    }
    $this->logRowError($line, $melId, 'Validation failed: ' . implode(' ', $messages));
  }

  private function stripBom(string $cell): string {
    if (str_starts_with($cell, "\xEF\xBB\xBF")) {
      return substr($cell, 3);
    }
    return $cell;
  }

  private function normalizeImportText(string $text): string {
    if ($text === '') {
      return '';
    }
    if (mb_check_encoding($text, 'UTF-8')) {
      return $text;
    }
    $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    if (is_string($converted) && $converted !== '') {
      return $converted;
    }
    $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
    return is_string($clean) ? $clean : '';
  }

  /**
   * Resolves a user-provided path or URI to something fopen() can read.
   */
  public function resolveReadablePath(string $source, string $drupalRoot): ?string {
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

    if (str_starts_with($normalized, '/')) {
      $resolved = realpath($normalized);
      if ($resolved !== FALSE && is_readable($resolved)) {
        return $resolved;
      }
      return is_readable($normalized) ? $normalized : NULL;
    }

    $projectRoot = dirname($drupalRoot);
    $fromProject = $projectRoot . '/' . ltrim($normalized, '/');
    $resolved = realpath($fromProject);
    if ($resolved !== FALSE && is_readable($resolved)) {
      return $resolved;
    }

    $fromWeb = $drupalRoot . '/' . ltrim($normalized, '/');
    $resolved = realpath($fromWeb);
    if ($resolved !== FALSE && is_readable($resolved)) {
      return $resolved;
    }

    $resolved = realpath($source);
    if ($resolved !== FALSE && is_readable($resolved)) {
      return $resolved;
    }

    return is_readable($source) ? $source : NULL;
  }

}
