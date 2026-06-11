<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\TermInterface;

/**
 * Seeds Help Centre landing pages and baseline help articles.
 */
final class HelpContentSeeder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly HelpContentRepository $helpContentRepository,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('myeventlane_help_centre');
  }

  /**
   * Module logger channel.
   */
  private \Psr\Log\LoggerInterface $logger;

  public function seedLandingPages(): int {
    $definitions = [
      [
        'title' => 'Help Centre Home',
        // Avoid /help and /help/* — those paths are registered Help Centre routes.
        'alias' => '/help/landing/home',
        'summary' => 'Quick answers for attendees, organisers, vendors, and policies in one place.',
        'body' => '<p>Welcome to the MyEventLane Help Centre. Start with the audience that matches you, then follow clear steps to get moving again.</p>',
        'audience' => ['General'],
        'featured' => TRUE,
      ],
      [
        'title' => 'Attendee Help',
        'alias' => '/help/landing/attendees',
        'summary' => 'Find help with tickets, RSVPs, refunds, and event-day access.',
        'body' => '<p>Need help finding your ticket, updating your booking, or understanding refund options? Start here for attendee support.</p>',
        'audience' => ['Attendees'],
        'featured' => TRUE,
      ],
      [
        'title' => 'Organiser Help',
        'alias' => '/help/landing/organisers',
        'summary' => 'Guides for setting up events, tickets, payouts, and attendee comms.',
        'body' => '<p>Running an event? This section covers setup, ticketing choices, dashboard basics, and practical next steps.</p>',
        'audience' => ['Organisers'],
        'featured' => TRUE,
      ],
      [
        'title' => 'Vendor Help',
        'alias' => '/help/landing/vendors',
        'summary' => 'Support for vendor dashboards, applications, and stall workflows.',
        'body' => '<p>Find clear guidance for vendor applications, profile management, and day-to-day vendor operations on MyEventLane.</p>',
        'audience' => ['Vendors'],
        'featured' => TRUE,
      ],
      [
        'title' => 'Policies and Trust',
        'alias' => '/help/landing/policies',
        'summary' => 'Understand privacy, terms, safety expectations, and community rules.',
        'body' => '<p>Here is where we explain how MyEventLane handles trust, privacy, and community safety in plain language.</p>',
        'audience' => ['General'],
        'featured' => TRUE,
      ],
    ];

    return $this->seedLandingPageNodes($definitions);
  }

  /**
   * @param list<string>|null $seedKeys
   *   Optional allow-list of seed_key values. When set, only those articles run.
   */
  public function seedHelpArticles(?array $seedKeys = NULL): int {
    $yaml = $this->helpContentRepository->getHelpArticleSeeds();
    if ($yaml === []) {
      $this->logger->warning('No YAML help article seeds found; nothing to seed.');
      return 0;
    }

    $rows = $this->normalizeYamlSeedsToArticleRows($yaml, $seedKeys);
    if ($rows === []) {
      $this->logger->warning('YAML help article seeds were invalid; nothing to seed.');
      return 0;
    }

    return $this->seedArticleNodes($rows);
  }

  /**
   * @param array<string, array<string, mixed>> $helpArticles
   *
   * @return array<int, array<string, mixed>>
   */
  /**
   * @param list<string>|null $seedKeys
   */
  private function normalizeYamlSeedsToArticleRows(array $helpArticles, ?array $seedKeys = NULL): array {
    $allowed = $seedKeys !== NULL ? array_fill_keys($seedKeys, TRUE) : NULL;
    $out = [];
    foreach ($helpArticles as $key => $row) {
      if (!is_array($row)) {
        continue;
      }
      $seedKey = trim((string) ($row['seed_key'] ?? $key ?? ''));
      if ($seedKey === '') {
        continue;
      }
      if ($allowed !== NULL && !isset($allowed[$seedKey])) {
        continue;
      }
      $title = trim((string) ($row['title'] ?? ''));
      if ($title === '') {
        continue;
      }
      $kw = $row['keywords'] ?? '';
      if (is_array($kw)) {
        $kw = implode(', ', $kw);
      }
      $audience = $row['audience'] ?? [];
      if (!is_array($audience)) {
        $audience = $audience !== '' && $audience !== NULL ? [(string) $audience] : [];
      }
      $out[] = [
        'seed_key' => $seedKey,
        'title' => $title,
        'summary' => (string) ($row['summary'] ?? ''),
        'body' => (string) ($row['body'] ?? ''),
        'audience' => $audience,
        'topic' => (string) ($row['topic'] ?? ''),
        'article_type' => (string) ($row['article_type'] ?? ''),
        'keywords' => (string) $kw,
        'cta_label' => (string) ($row['cta_label'] ?? ''),
        'cta_link' => (string) ($row['cta_link'] ?? ''),
        'alias' => (string) ($row['alias'] ?? ''),
        'featured' => (bool) ($row['featured'] ?? FALSE),
      ];
    }
    return $out;
  }

  private function seedLandingPageNodes(array $definitions): int {
    $storage = $this->entityTypeManager->getStorage('node');
    $created = 0;

    foreach ($definitions as $item) {
      $existing = $storage->loadByProperties([
        'type' => 'help_landing_page',
        'title' => $item['title'],
      ]);
      if ($existing !== []) {
        continue;
      }

      $node = Node::create([
        'type' => 'help_landing_page',
        'title' => $item['title'],
        'status' => 1,
      ]);
      $this->setTextField($node, 'field_help_summary', (string) $item['summary']);
      $this->setBodyField($node, (string) $item['body']);
      $this->setBoolField($node, 'field_featured_help', (bool) $item['featured']);
      $this->setAudienceField($node, (array) $item['audience']);
      $this->setAlias($node, (string) $item['alias']);
      $node->save();
      $created++;
    }

    $this->logger->notice('Help Centre landing pages seeded. Created @count pages.', ['@count' => (string) $created]);
    return $created;
  }

  private function seedArticleNodes(array $definitions): int {
    $created = 0;
    $updated = 0;

    foreach ($definitions as $item) {
      $seedKey = (string) ($item['seed_key'] ?? '');
      if ($seedKey === '') {
        continue;
      }
      $existing = $this->loadSeededArticle($seedKey, (string) $item['title']);
      $isNew = $existing === NULL;
      if (!$isNew && !$this->nodeNeedsSeedUpdate($existing, $item)) {
        continue;
      }
      $node = $isNew ? Node::create([
        'type' => 'help_article',
        'title' => $item['title'],
        'status' => 1,
      ]) : $existing;
      $node->setTitle($item['title']);
      $this->setTextField($node, 'field_help_seed_key', $seedKey);
      $this->setTextField($node, 'field_help_summary', (string) $item['summary']);
      $this->setBodyField($node, (string) $item['body']);
      $this->setAudienceField($node, (array) $item['audience']);
      $this->setTermFieldByName($node, 'field_help_topic', 'help_topic', (string) $item['topic']);
      $this->setTermFieldByName($node, 'field_help_article_type', 'help_article_type', (string) $item['article_type']);
      $this->setTextField($node, 'field_help_keywords', (string) $item['keywords']);
      $this->setBoolField($node, 'field_featured_help', (bool) ($item['featured'] ?? FALSE));
      $this->setTextField($node, 'field_help_cta_label', (string) $item['cta_label']);
      $this->setLinkField($node, 'field_help_cta_link', (string) $item['cta_link']);
      if ($isNew || ($node->hasField('field_last_reviewed') && $node->get('field_last_reviewed')->isEmpty())) {
        $this->setDateField($node, 'field_last_reviewed', date('Y-m-d'));
      }
      $this->setAlias($node, (string) $item['alias']);
      if ($isNew) {
        $this->applyPublishedState($node);
        if ($node->hasField('field_help_status')) {
          $this->setTextField($node, 'field_help_status', 'published');
        }
        $node->save();
        $created++;
        continue;
      }
      $node->save();
      $updated++;
    }

    $this->logger->notice('Help Centre articles seeded. Created @created, updated @updated.', [
      '@created' => (string) $created,
      '@updated' => (string) $updated,
    ]);
    return $created + $updated;
  }

  private function setBodyField(NodeInterface $node, string $html): void {
    if (!$node->hasField('body')) {
      return;
    }
    $node->set('body', [
      'value' => $html,
      'format' => 'basic_html',
    ]);
  }

  private function setTextField(NodeInterface $node, string $fieldName, string $value): void {
    if ($value === '' || !$node->hasField($fieldName)) {
      return;
    }
    $node->set($fieldName, $value);
  }

  private function setBoolField(NodeInterface $node, string $fieldName, bool $value): void {
    if (!$node->hasField($fieldName)) {
      return;
    }
    $node->set($fieldName, $value ? 1 : 0);
  }

  private function setLinkField(NodeInterface $node, string $fieldName, string $uri): void {
    if ($uri === '' || !$node->hasField($fieldName)) {
      return;
    }
    $node->set($fieldName, [
      'uri' => str_starts_with($uri, 'http') ? $uri : 'internal:' . $uri,
      'title' => '',
    ]);
  }

  private function setDateField(NodeInterface $node, string $fieldName, string $date): void {
    if (!$node->hasField($fieldName)) {
      return;
    }
    $node->set($fieldName, $date);
  }

  private function setAlias(NodeInterface $node, string $alias): void {
    if ($alias === '' || !$node->hasField('path')) {
      return;
    }
    $node->set('path', [
      'alias' => $alias,
      'pathauto' => 0,
    ]);
  }

  private function setAudienceField(NodeInterface $node, array $names): void {
    if ($names === []) {
      return;
    }

    if (!$node->hasField('field_audience')) {
      return;
    }

    $canonical = [];
    foreach ($names as $name) {
      $mapped = $this->mapSeedAudienceNameToCanonical($name);
      if ($mapped !== NULL) {
        $canonical[$mapped] = $mapped;
      }
    }
    if ($canonical !== []) {
      $node->set('field_audience', array_map(
        static fn(string $v): array => ['value' => $v],
        array_values($canonical),
      ));
    }
  }

  /**
   * Maps seeded audience labels to field_audience list values.
   */
  private function mapSeedAudienceNameToCanonical(string $name): ?string {
    $key = mb_strtolower(trim($name));
    return match ($key) {
      'vendors', 'vendor' => 'vendor',
      'admin', 'staff' => 'staff',
      'attendees', 'attendee', 'organisers', 'organizers', 'general', 'public' => 'public',
      default => NULL,
    };
  }

  private function setTermFieldByName(NodeInterface $node, string $fieldName, string $vocabularyId, string $termName): void {
    if (!$node->hasField($fieldName) || $termName === '') {
      return;
    }
    $term = $this->ensureTermByName($vocabularyId, $termName);
    if ($term instanceof TermInterface) {
      $node->set($fieldName, ['target_id' => (int) $term->id()]);
    }
  }

  private function ensureTermByName(string $vocabularyId, string $termName): ?TermInterface {
    $term = $this->loadTermByName($vocabularyId, $termName);
    if ($term instanceof TermInterface) {
      return $term;
    }
    if ($vocabularyId !== 'help_topic') {
      return NULL;
    }
    $term = Term::create([
      'vid' => $vocabularyId,
      'name' => $termName,
    ]);
    $term->save();
    $this->logger->notice('Created missing help_topic term: @name', ['@name' => $termName]);
    return $term;
  }

  private function loadTermByName(string $vocabularyId, string $name): ?TermInterface {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabularyId,
        'name' => $name,
      ]);
    if ($terms === []) {
      return NULL;
    }
    $term = reset($terms);
    return $term instanceof TermInterface ? $term : NULL;
  }

  /**
   * Loads an existing help article by seed key, then title fallback.
   */
  private function loadSeededArticle(string $seedKey, string $title): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    if ($this->hasHelpSeedKeyField()) {
      $existing = $storage->loadByProperties([
        'type' => 'help_article',
        'field_help_seed_key' => $seedKey,
      ]);
      if ($existing !== []) {
        $node = reset($existing);
        return $node instanceof NodeInterface ? $node : NULL;
      }
    }
    $existing = $storage->loadByProperties([
      'type' => 'help_article',
      'title' => $title,
    ]);
    if ($existing === []) {
      return NULL;
    }
    $node = reset($existing);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Whether seed-managed fields differ from the stored node.
   *
   * Compares current field values directly because Drupal 11 does not keep a
   * populated $node->original snapshot on routine entity loads.
   */
  private function nodeNeedsSeedUpdate(NodeInterface $node, array $item): bool {
    if ($node->getTitle() !== (string) $item['title']) {
      return TRUE;
    }
    if ($this->getTextFieldValue($node, 'field_help_seed_key') !== (string) $item['seed_key']) {
      return TRUE;
    }
    if ($this->getTextFieldValue($node, 'field_help_summary') !== (string) $item['summary']) {
      return TRUE;
    }
    if (!$this->bodyFieldMatches($node, (string) $item['body'])) {
      return TRUE;
    }
    if (!$this->audienceFieldMatches($node, (array) $item['audience'])) {
      return TRUE;
    }
    if ($this->getTermName($node, 'field_help_topic') !== (string) $item['topic']) {
      return TRUE;
    }
    if ($this->getTermName($node, 'field_help_article_type') !== (string) $item['article_type']) {
      return TRUE;
    }
    if ($this->getTextFieldValue($node, 'field_help_keywords') !== (string) $item['keywords']) {
      return TRUE;
    }
    if ((bool) $this->getBoolFieldValue($node, 'field_featured_help') !== (bool) ($item['featured'] ?? FALSE)) {
      return TRUE;
    }
    if ($this->getTextFieldValue($node, 'field_help_cta_label') !== (string) $item['cta_label']) {
      return TRUE;
    }
    if (!$this->linkFieldMatches($node, 'field_help_cta_link', (string) $item['cta_link'])) {
      return TRUE;
    }
    if ($this->getNodeAlias($node) !== (string) $item['alias']) {
      return TRUE;
    }
    if ($node->hasField('field_last_reviewed') && $node->get('field_last_reviewed')->isEmpty()) {
      return TRUE;
    }
    return FALSE;
  }

  private function hasHelpSeedKeyField(): bool {
    $fieldStorage = $this->entityTypeManager->getStorage('field_storage_config');
    return $fieldStorage->load('node.field_help_seed_key') !== NULL;
  }

  private function getTextFieldValue(NodeInterface $node, string $fieldName): string {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return '';
    }
    return (string) $node->get($fieldName)->value;
  }

  private function getBoolFieldValue(NodeInterface $node, string $fieldName): bool {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return FALSE;
    }
    return (bool) $node->get($fieldName)->value;
  }

  private function bodyFieldMatches(NodeInterface $node, string $body): bool {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return $body === '';
    }
    return (string) $node->get('body')->value === $body
      && (string) ($node->get('body')->format ?? 'basic_html') === 'basic_html';
  }

  private function audienceFieldMatches(NodeInterface $node, array $names): bool {
    $expected = [];
    foreach ($names as $name) {
      $mapped = $this->mapSeedAudienceNameToCanonical($name);
      if ($mapped !== NULL) {
        $expected[$mapped] = $mapped;
      }
    }
    if (!$node->hasField('field_audience')) {
      return $expected === [];
    }
    $actual = [];
    foreach ($node->get('field_audience')->getValue() as $row) {
      if (!empty($row['value'])) {
        $actual[(string) $row['value']] = (string) $row['value'];
      }
    }
    ksort($expected);
    ksort($actual);
    return $expected === $actual;
  }

  private function getTermName(NodeInterface $node, string $fieldName): string {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return '';
    }
    $term = $node->get($fieldName)->entity;
    return $term instanceof TermInterface ? $term->getName() : '';
  }

  private function linkFieldMatches(NodeInterface $node, string $fieldName, string $uri): bool {
    if ($uri === '') {
      return !$node->hasField($fieldName) || $node->get($fieldName)->isEmpty();
    }
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return FALSE;
    }
    $expected = str_starts_with($uri, 'http') ? $uri : 'internal:' . $uri;
    return (string) $node->get($fieldName)->uri === $expected;
  }

  private function getNodeAlias(NodeInterface $node): string {
    if (!$node->hasField('path') || $node->get('path')->isEmpty()) {
      return '';
    }
    return (string) $node->get('path')->alias;
  }

  /**
   * Ensures newly seeded help articles are publicly visible under moderation.
   */
  private function applyPublishedState(NodeInterface $node): void {
    $node->setPublished();
    if ($node->hasField('moderation_state')) {
      $node->set('moderation_state', 'published');
    }
  }

}
