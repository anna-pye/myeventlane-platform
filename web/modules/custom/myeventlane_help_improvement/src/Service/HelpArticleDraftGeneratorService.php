<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_improvement\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_ai\Service\AiManager;
use Drupal\myeventlane_ai\Value\PromptDefinition;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Creates unpublished help_article nodes from AI output (human review required).
 */
final class HelpArticleDraftGeneratorService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AiManager $aiManager,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * @param array<string, mixed> $opportunity
   *   Row from mel_doc_opportunity.
   *
   * @return array{node: \Drupal\node\NodeInterface, trace: array<string, mixed>}
   */
  public function generateDraft(array $opportunity, AccountInterface $account): array {
    $audience = $this->normaliseAudienceForDraft((string) ($opportunity['audience'] ?? 'unknown'));
    if ($audience === 'unknown') {
      throw new \InvalidArgumentException('Set a concrete audience (public, vendor, or staff) before generating an AI draft.');
    }

    $query = (string) ($opportunity['query_text'] ?? '');
    $oppId = (int) ($opportunity['id'] ?? 0);
    $sourceType = (string) ($opportunity['source_type'] ?? '');
    $linkedNid = $opportunity['linked_help_article_nid'] !== NULL ? (int) $opportunity['linked_help_article_nid'] : 0;
    $isRevision = $sourceType === DocumentationOpportunityStorage::SOURCE_LOW_HELPFULNESS && $linkedNid > 0;

    if ($isRevision) {
      $existing = $this->entityTypeManager->getStorage('node')->load($linkedNid);
      if (!$existing instanceof NodeInterface || $existing->bundle() !== 'help_article') {
        throw new \InvalidArgumentException('Linked help article is missing or not a help_article bundle.');
      }
      return $this->reviseExistingArticle($existing, $opportunity, $account, $audience);
    }

    $prompt = $this->buildDraftPrompt($query, $audience, $sourceType, FALSE, '');
    $aiConfig = $this->configFactory->get('myeventlane_ai.settings');
    $uid = (int) $account->id();
    $scopeId = 'help_improvement:draft:' . substr(hash('sha256', 'opp|' . (string) $oppId), 0, 24);
    $model = (string) ($aiConfig->get('openai.model') ?: 'gpt-4o-mini');
    $aiResult = $this->aiManager->analyze(
      $prompt,
      [
        'model' => $model,
        'temperature' => 0.25,
        'max_tokens' => 2200,
        'timeout_seconds' => (int) ($aiConfig->get('openai.timeout_seconds') ?? 45),
      ],
      $uid,
      $scopeId,
      NULL,
    );

    if (!$aiResult->ok) {
      $this->logger->error('AI draft generation failed for opportunity @id: @err', [
        '@id' => (string) $oppId,
        '@err' => (string) $aiResult->error,
      ]);
      throw new \RuntimeException((string) $aiResult->error);
    }

    $decoded = $this->decodeJson($aiResult->json, $aiResult->raw);
    if ($decoded === NULL) {
      throw new \RuntimeException('AI response was not valid JSON.');
    }

    $node = $this->buildNewDraftNode($decoded, $audience, $account, $oppId, $aiResult->model ?? $model, $aiResult->provider ?? 'unknown');
    $trace = [
      'opportunity_id' => $oppId,
      'generated_at' => $this->time->getCurrentTime(),
      'model' => $aiResult->model ?? $model,
      'provider' => $aiResult->provider ?? 'unknown',
      'ai_assisted' => TRUE,
      'mode' => 'new_article',
    ];
    return ['node' => $node, 'trace' => $trace];
  }

  /**
   * @param array<string, mixed> $decoded
   */
  private function buildNewDraftNode(array $decoded, string $audience, AccountInterface $account, int $oppId, string $model, string $provider): NodeInterface {
    $title = trim((string) ($decoded['title'] ?? ''));
    if ($title === '') {
      throw new \RuntimeException('AI returned an empty title.');
    }
    $summary = trim((string) ($decoded['summary'] ?? ''));
    $bodyHtml = trim((string) ($decoded['body_html'] ?? ''));
    if ($bodyHtml === '') {
      throw new \RuntimeException('AI returned empty body_html.');
    }
    $typeName = trim((string) ($decoded['taxonomy_article_type_name'] ?? ''));
    $typeTid = $this->resolveArticleTypeTermId($typeName);
    $allowAi = $audience === 'staff' ? 0 : (isset($decoded['allow_in_help_assistant']) ? (int) (bool) $decoded['allow_in_help_assistant'] : 1);

    $storage = $this->entityTypeManager->getStorage('node');
    /** @var \Drupal\node\NodeInterface $node */
    $node = $storage->create([
      'type' => 'help_article',
      'title' => $title,
      'uid' => (int) $account->id(),
      'status' => 0,
    ]);

    $node->set('field_help_summary', $summary);
    $node->set('body', [
      'value' => $bodyHtml,
      'summary' => $summary !== '' ? $summary : '',
      'format' => 'basic_html',
    ]);
    $node->set('field_audience', [['value' => $audience]]);
    $node->set('field_help_article_type', ['target_id' => $typeTid]);
    $node->set('field_help_status', 'draft');
    $node->set('field_help_ai_allowed', (bool) $allowAi);

    $revNote = sprintf(
      "AI-assisted DRAFT (not published). Opportunity #%d. Model: %s (%s). Editor must review before publication.",
      $oppId,
      $model,
      $provider,
    );
    $node->setRevisionLogMessage($revNote);
    $node->setNewRevision(TRUE);

    $node->save();
    return $node;
  }

  /**
   * @param array<string, mixed> $opportunity
   *
   * @return array{node: \Drupal\node\NodeInterface, trace: array<string, mixed>}
   */
  private function reviseExistingArticle(NodeInterface $existing, array $opportunity, AccountInterface $account, string $audience): array {
    $oppId = (int) ($opportunity['id'] ?? 0);
    $bodyText = '';
    if ($existing->hasField('body') && !$existing->get('body')->isEmpty()) {
      $bodyText = (string) $existing->get('body')->value;
    }
    $title = $existing->label();
    $prompt = $this->buildDraftPrompt((string) ($opportunity['query_text'] ?? ''), $audience, (string) ($opportunity['source_type'] ?? ''), TRUE, $title . "\n\n" . $bodyText);

    $aiConfig = $this->configFactory->get('myeventlane_ai.settings');
    $uid = (int) $account->id();
    $scopeId = 'help_improvement:revise:' . substr(hash('sha256', 'opp|' . (string) $oppId), 0, 24);
    $model = (string) ($aiConfig->get('openai.model') ?: 'gpt-4o-mini');
    $aiResult = $this->aiManager->analyze(
      $prompt,
      [
        'model' => $model,
        'temperature' => 0.2,
        'max_tokens' => 2400,
        'timeout_seconds' => (int) ($aiConfig->get('openai.timeout_seconds') ?? 45),
      ],
      $uid,
      $scopeId,
      NULL,
    );

    if (!$aiResult->ok) {
      $this->logger->error('AI revision draft failed for opportunity @id: @err', [
        '@id' => (string) $oppId,
        '@err' => (string) $aiResult->error,
      ]);
      throw new \RuntimeException((string) $aiResult->error);
    }

    $decoded = $this->decodeJson($aiResult->json, $aiResult->raw);
    if ($decoded === NULL) {
      throw new \RuntimeException('AI response was not valid JSON.');
    }

    $summary = trim((string) ($decoded['summary'] ?? ''));
    $bodyHtml = trim((string) ($decoded['body_html'] ?? ''));
    if ($bodyHtml === '') {
      throw new \RuntimeException('AI returned empty body_html for revision.');
    }

    $duplicate = $existing->createDuplicate();
    $duplicate->setOwnerId((int) $account->id());
    $duplicate->setTitle($existing->label());
    $duplicate->setUnpublished();
    $prevSummary = $existing->hasField('field_help_summary') && !$existing->get('field_help_summary')->isEmpty()
      ? (string) $existing->get('field_help_summary')->value
      : '';
    $duplicate->set('field_help_summary', $summary !== '' ? $summary : $prevSummary);
    $duplicate->set('body', [
      'value' => $bodyHtml,
      'summary' => $summary !== '' ? $summary : $prevSummary,
      'format' => 'basic_html',
    ]);
    $duplicate->set('field_help_status', 'review');
    $duplicate->setRevisionLogMessage(sprintf(
      'AI-assisted revision DRAFT from documentation opportunity #%d (source article nid %d). Model: %s (%s). Not published.',
      $oppId,
      (int) $existing->id(),
      $aiResult->model ?? $model,
      $aiResult->provider ?? 'unknown',
    ));
    $duplicate->setNewRevision(TRUE);
    $duplicate->save();

    $trace = [
      'opportunity_id' => $oppId,
      'source_nid' => (int) $existing->id(),
      'generated_at' => $this->time->getCurrentTime(),
      'model' => $aiResult->model ?? $model,
      'provider' => $aiResult->provider ?? 'unknown',
      'ai_assisted' => TRUE,
      'mode' => 'revision_copy',
    ];

    return ['node' => $duplicate, 'trace' => $trace];
  }

  private function normaliseAudienceForDraft(string $audience): string {
    $v = mb_strtolower(trim($audience));
    return in_array($v, ['public', 'vendor', 'staff'], TRUE) ? $v : 'unknown';
  }

  private function buildDraftPrompt(string $query, string $audience, string $sourceType, bool $isRevision, string $existingContent): PromptDefinition {
    $audienceNote = match ($audience) {
      'staff' => 'Audience is STAFF-ONLY. Do not write for the general public. Tone: internal operations.',
      'vendor' => 'Audience is vendors using MyEventLane.',
      default => 'Audience is the general public (attendees and organisers).',
    };
    $revisionNote = $isRevision
      ? "Revise the following existing article content. Preserve factual accuracy; improve clarity and address likely gaps implied by feedback.\n\nEXISTING:\n" . mb_substr($existingContent, 0, 12000)
      : 'Propose a new help article.';

    $system = <<<TXT
You are a senior MyEventLane documentation editor. Output a single JSON object ONLY, no markdown fences.
Keys: title (string), summary (string, one paragraph), body_html (string, basic HTML: p, ul, li, strong only), taxonomy_article_type_name (short label matching a help centre article type such as Guide or FAQ), product_area (short string), allow_in_help_assistant (boolean).
Rules: Do not invent product features. If unsure, say what is unknown and point readers to contact support. Do not include URLs unless they are clearly generic (e.g. /contact).
{$audienceNote}
JSON must be valid UTF-8.
TXT;

    $user = "Documentation opportunity context:\n- Source signal: {$sourceType}\n- User need / query: {$query}\n\n{$revisionNote}";
    $hash = hash('sha256', 'help_improvement.draft|v1|' . $system . '|' . $user);
    return new PromptDefinition('help_improvement.draft_article', 'v1', $system, $user, $hash);
  }

  /**
   * @param array<string, mixed>|null $json
   *
   * @return array<string, mixed>|null
   */
  private function decodeJson(?array $json, string $raw): ?array {
    if (is_array($json)) {
      return $json;
    }
    $trim = trim($raw);
    if ($trim === '') {
      return NULL;
    }
    $decoded = json_decode($trim, TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

  private function resolveArticleTypeTermId(string $preferredName): int {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    if ($preferredName !== '') {
      $terms = $storage->loadByProperties(['vid' => 'help_article_type', 'name' => $preferredName]);
      $term = $terms ? reset($terms) : NULL;
      if ($term instanceof TermInterface) {
        return (int) $term->id();
      }
    }
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'help_article_type')
      ->sort('name')
      ->range(0, 1)
      ->execute();
    if ($ids !== []) {
      return (int) reset($ids);
    }

    throw new \RuntimeException('No taxonomy terms exist in help_article_type; create at least one term before generating drafts.');
  }

}
