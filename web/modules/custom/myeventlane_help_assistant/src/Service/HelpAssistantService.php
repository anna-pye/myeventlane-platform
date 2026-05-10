<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_ai\Service\AiManager;
use Drupal\myeventlane_ai\Value\PromptDefinition;
use Drupal\myeventlane_help_centre\Service\HelpAnalyticsService;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates retrieval-first Help Assistant responses.
 */
final class HelpAssistantService {

  use StringTranslationTrait;

  /**
   * Common words ignored when checking answer tokens against retrieval text.
   *
   * @var list<string>
   */
  private const STOPWORDS = [
    'about', 'after', 'also', 'any', 'are', 'ask', 'been', 'being', 'but',
    'can', 'could', 'does', 'event', 'events', 'eventlane', 'from', 'get',
    'give', 'guidance', 'had', 'has', 'have', 'help', 'here', 'helpful',
    'how', 'into', 'just', 'like', 'make', 'more', 'most', 'much', 'need',
    'not', 'note', 'only', 'other', 'out', 'over', 'please', 'same', 'some',
    'such', 'sure', 'than', 'that', 'the', 'their', 'them', 'then', 'there',
    'these', 'they', 'this', 'ticket', 'tickets', 'very', 'want', 'what',
    'when', 'where', 'which', 'while', 'will', 'with', 'without', 'would',
    'your', 'you', 'myeventlane', 'my', 'centre', 'lane', 'australia',
    'australian', 'yes', 'using', 'used', 'use', 'way', 'may', 'might',
  ];

  public function __construct(
    private readonly HelpRetriever $helpRetriever,
    private readonly AiManager $aiManager,
    private readonly HelpAnalyticsService $helpAnalyticsService,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Answers a Help Assistant question using grounded MEL sources only.
   *
   * @param array<string, mixed> $context
   *   Workspace context from HelpContextResolver (surface, optional event, vendor).
   *
   * @return array<string, mixed>
   *   Assistant payload.
   */
  public function answerQuestion(string $question, array $context = []): array {
    $config = $this->configFactory->get('myeventlane_help_assistant.settings');
    $question = $this->sanitiseQuestion($question);
    $supportUrl = trim((string) ($config->get('support_url') ?? '/support/tickets'));

    if (!(bool) $config->get('enabled')) {
      return $this->withStructuredFields([
        'status' => 'disabled',
        'answer' => 'Sorry, the Help Assistant is unavailable right now.',
        'confidence' => 'low',
        'escalation_recommended' => TRUE,
        'message' => $this->buildEscalationText($supportUrl),
        'hide_chat_expectations' => FALSE,
      ], [], [], [], []);
    }

    $maxRetrieved = (int) ($config->get('max_retrieved_articles') ?? 5);
    $maxRetrieved = max(3, min($maxRetrieved, 5));
    $results = $this->helpRetriever->retrieve($question, $maxRetrieved);
    $retrievalConfidence = $this->determineRetrievalConfidence($question, $results);
    $articleLinks = $this->buildArticleLinks($results);
    $resultCount = count($results);
    $this->helpAnalyticsService->logAiEvent('ai_query', $question, $resultCount, $retrievalConfidence);

    if ($results === []) {
      $this->helpAnalyticsService->logAiEvent('ai_low_confidence', $question, 0, 'low');
      return $this->buildFallbackPayload([], 'low', $supportUrl, TRUE);
    }

    if ($retrievalConfidence === 'low') {
      $this->helpAnalyticsService->logAiEvent('ai_low_confidence', $question, $resultCount, 'low');
      return $this->buildFallbackPayload($articleLinks, 'low', $supportUrl, FALSE);
    }

    $aiConfig = $this->configFactory->get('myeventlane_ai.settings');
    if (!(bool) $aiConfig->get('enabled')) {
      $this->helpAnalyticsService->logAiEvent('ai_low_confidence', $question, $resultCount, 'low');
      return $this->withStructuredFields([
        'status' => 'ai_disabled',
        'answer' => 'AI help is currently unavailable. You can still browse help articles below.',
        'confidence' => 'low',
        'escalation_recommended' => FALSE,
        'message' => '',
        'hide_chat_expectations' => TRUE,
      ], $articleLinks, [], [], []);
    }

    $prompt = $this->buildPromptDefinition($question, $results, $supportUrl, $retrievalConfidence, $context);
    $uid = $this->currentUser->isAuthenticated() ? (int) $this->currentUser->id() : NULL;
    $scopeId = 'help_assistant:' . substr(hash('sha256', mb_strtolower($question)), 0, 20);
    $maxTokens = (int) ($aiConfig->get('openai.max_tokens') ?? 600);
    $maxTokens = max(200, min($maxTokens, 600));

    $aiStarted = microtime(TRUE);
    $aiResult = $this->aiManager->analyze(
      $prompt,
      [
        'model' => (string) ($aiConfig->get('openai.model') ?: 'gpt-4o-mini'),
        'temperature' => (float) ($aiConfig->get('openai.temperature') ?? 0.2),
        'max_tokens' => $maxTokens,
        'timeout_seconds' => (int) ($aiConfig->get('openai.timeout_seconds') ?? 20),
      ],
      $uid,
      $scopeId,
      NULL,
    );
    $aiDurationMs = (int) round((microtime(TRUE) - $aiStarted) * 1000);

    if (!$aiResult->ok) {
      $this->logger->error('Help Assistant AI manager error: @error', [
        '@error' => (string) $aiResult->error,
      ]);
      $this->helpAnalyticsService->logAiEvent('ai_low_confidence', $question, $resultCount, 'low', $aiDurationMs);
      return $this->buildFallbackPayload($articleLinks, 'low', $supportUrl, FALSE);
    }

    $decoded = $this->decodeStructuredResult($aiResult->json, $aiResult->raw);
    if ($decoded === NULL) {
      $this->logger->error('Help Assistant AI response was not valid JSON.');
      $this->helpAnalyticsService->logAiEvent('ai_low_confidence', $question, $resultCount, 'low', $aiDurationMs);
      return $this->buildFallbackPayload($articleLinks, 'low', $supportUrl, FALSE);
    }

    $answer = trim((string) ($decoded['answer'] ?? ''));
    $modelConfidence = $this->normaliseConfidence((string) ($decoded['confidence'] ?? 'low'));
    $finalConfidence = $this->mergeConfidence($retrievalConfidence, $modelConfidence);
    if ($answer === '' || $finalConfidence === 'low') {
      $this->helpAnalyticsService->logAiEvent('ai_low_confidence', $question, $resultCount, 'low', $aiDurationMs);
      return $this->buildFallbackPayload($articleLinks, 'low', $supportUrl, FALSE);
    }

    $answer = $this->enforceGrounding($answer, $articleLinks, $results);
    if ($answer === '') {
      $this->logger->notice('Help Assistant answer failed grounding enforcement.');
      $this->helpAnalyticsService->logAiEvent('ai_low_confidence', $question, $resultCount, 'low', $aiDurationMs);
      return $this->buildFallbackPayload($articleLinks, 'low', $supportUrl, FALSE);
    }

    $finalLinks = $this->groundedArticleLinks($decoded['articles'] ?? NULL, $articleLinks);
    $steps = $this->sanitiseSteps($decoded['steps'] ?? NULL);
    $actions = $this->groundedActions($decoded['actions'] ?? NULL, $results, $supportUrl);
    $followups = $this->sanitiseFollowups($decoded['followups'] ?? NULL);

    $payload = $this->withStructuredFields([
      'status' => 'ok',
      'answer' => $answer,
      'confidence' => $finalConfidence,
      'escalation_recommended' => FALSE,
      'message' => '',
      'hide_chat_expectations' => FALSE,
      'retrieval_tier' => $retrievalConfidence,
    ], $finalLinks, $steps, $actions, $followups);

    $this->helpAnalyticsService->logAiEvent('ai_success', $question, $resultCount, $finalConfidence, $aiDurationMs);
    return $payload;
  }

  /**
   * @param array<string, mixed> $payload
   * @param array<int, array<string, string>> $sources
   * @param list<string> $steps
   * @param array<int, array<string, string>> $actions
   * @param list<string> $followups
   *
   * @return array<string, mixed>
   */
  private function withStructuredFields(array $payload, array $sources, array $steps, array $actions, array $followups): array {
    $payload['sources'] = $sources;
    $payload['articles'] = $sources;
    $payload['steps'] = $steps;
    $payload['actions'] = $actions;
    $payload['followups'] = $followups;
    return $payload;
  }

  /**
   * Builds an explicit, guarded AI prompt definition.
   *
   * @param array<int, array<string, mixed>> $results
   *   Retrieved grounded help articles.
   * @param array<string, mixed> $context
   *   Workspace context for tone and framing (no user ids sent to the model).
   */
  private function buildPromptDefinition(string $question, array $results, string $supportUrl, string $retrievalConfidence, array $context = []): PromptDefinition {
    $articles = [];
    foreach ($results as $result) {
      $articles[] = [
        'title' => (string) ($result['title'] ?? ''),
        'summary' => (string) ($result['summary'] ?? ''),
        'url' => (string) ($result['url'] ?? ''),
        'cta_label' => (string) ($result['cta_label'] ?? ''),
        'cta_url' => (string) ($result['cta_url'] ?? ''),
        'content' => (string) ($result['content'] ?? ''),
      ];
    }

    $structuredContext = json_encode(['articles' => $articles], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($structuredContext)) {
      $structuredContext = '{"articles": []}';
    }

    $caution = '';
    if ($retrievalConfidence === 'medium') {
      $caution = "\nRetrieval note: Several articles are moderately relevant. Keep the answer cautious, avoid stating absolutes, and invite the reader to open the linked articles for detail.";
    }

    $systemMessage = implode("\n", [
      'You are the MyEventLane Help Assistant.',
      'Only answer using the provided help content.',
      'Do not invent MyEventLane features or behaviour.',
      'If unsure, explicitly say you do not know from the Help Centre.',
      'When workspace context is provided, personalise tone (e.g. your event, your dashboard) but do not state facts that are not supported by the articles.',
      'Do not include URLs in the answer text; article links are provided separately.',
      'Use friendly, clear Australian English that feels guided and human.',
      'Return strict JSON with keys: answer, articles, confidence, steps, actions, followups.',
      'confidence must be one of: high, medium, low.',
      'articles must list relevant help articles with title and url copied exactly from the provided articles.',
      'steps is an optional array of short actionable strings (or empty array).',
      'actions is an optional array of objects {label, url} where url must be copied exactly from an article url or cta_url (internal paths only).',
      'followups is an optional array of short natural follow-up questions the user might ask next (or empty array).',
      trim($caution),
    ]);

    $workspaceJson = json_encode($this->sanitiseContextForPrompt($context), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($workspaceJson)) {
      $workspaceJson = '{}';
    }

    $userMessage = implode("\n\n", [
      'User question:',
      $question,
      'Workspace context (verified server-side; may be empty):',
      $workspaceJson,
      'Help articles:',
      $structuredContext,
      'Instructions:',
      '- Answer using only the articles.',
      '- If answer is unclear, say so and set confidence to low.',
      '- Prefer concrete next steps in steps[] when the articles support them.',
      '- Use actions[] only when a cta_url or article url gives a clear next click.',
      '- If confidence is low, invite the user to contact support without putting URLs in the answer text.',
    ]);

    $hash = hash('sha256', 'help_assistant.answer|v4|' . $systemMessage . '|' . $userMessage);
    return new PromptDefinition('help_assistant.answer', 'v4', $systemMessage, $userMessage, $hash);
  }

  /**
   * Strips identifiers from context before sending to the model.
   *
   * @param array<string, mixed> $context
   *
   * @return array<string, mixed>
   */
  private function sanitiseContextForPrompt(array $context): array {
    $out = [
      'surface' => (string) ($context['surface'] ?? 'unknown'),
      'event' => NULL,
      'vendor_label' => NULL,
    ];
    if (!empty($context['event']) && is_array($context['event'])) {
      $ev = $context['event'];
      $out['event'] = [
        'title' => (string) ($ev['title'] ?? ''),
        'status' => (string) ($ev['status'] ?? ''),
        'event_type' => (string) ($ev['event_type'] ?? ''),
      ];
    }
    if (!empty($context['vendor']['label'])) {
      $out['vendor_label'] = (string) $context['vendor']['label'];
    }
    return $out;
  }

  /**
   * Combines Search API scores, hit count, and keyword overlap.
   */
  private function determineRetrievalConfidence(string $question, array $results): string {
    if ($results === []) {
      return 'low';
    }

    $n = count($results);
    $first = (float) ($results[0]['score'] ?? 0.0);
    $second = (float) ($results[1]['score'] ?? 0.0);
    if ($first <= 0.0) {
      return 'low';
    }

    $ratio = ($second > 0.0) ? $second / $first : 0.0;
    $overlap = $this->keywordOverlapScore($question, $results);

    if ($n >= 4 && $ratio > 0.38 && $overlap < 0.38) {
      return 'low';
    }
    if ($n >= 3 && $ratio >= 0.5 && $overlap < 0.3) {
      return 'low';
    }
    if ($n >= 2 && $ratio >= 0.48 && $overlap < 0.28) {
      return 'low';
    }
    if ($overlap < 0.14 && $n >= 2) {
      return 'low';
    }

    $dominant = $second <= 0.0 || $ratio < 0.28;
    if ($n <= 2 && $dominant && $overlap >= 0.22) {
      return 'high';
    }
    if ($n === 1 && $overlap >= 0.18) {
      return 'high';
    }
    if ($n <= 3 && $dominant && $overlap >= 0.35) {
      return 'high';
    }

    return 'medium';
  }

  /**
   * Share of significant question tokens that appear in retrieved text.
   */
  private function keywordOverlapScore(string $question, array $results): float {
    $tokens = $this->significantTokens($question);
    if ($tokens === []) {
      return 0.45;
    }
    $blob = '';
    foreach ($results as $row) {
      $blob .= ' ' . ($row['title'] ?? '') . ' ' . ($row['summary'] ?? '') . ' ' . ($row['content'] ?? '');
    }
    $blob = mb_strtolower($blob);
    $hits = 0;
    foreach ($tokens as $token) {
      if (str_contains($blob, $token)) {
        $hits++;
      }
    }
    return $hits / count($tokens);
  }

  /**
   * @return list<string>
   */
  private function significantTokens(string $text): array {
    $text = mb_strtolower(strip_tags($text));
    $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($parts as $part) {
      if (mb_strlen($part) < 3) {
        continue;
      }
      if (in_array($part, self::STOPWORDS, TRUE)) {
        continue;
      }
      $out[] = $part;
    }
    return array_values(array_unique($out));
  }

  /**
   * Strips URLs and flags answers that introduce unsupported terms.
   *
   * @param array<int, array<string, string>> $articles
   * @param array<int, array<string, mixed>> $results
   */
  private function enforceGrounding(string $answer, array $articles, array $results): string {
    $allowedUrls = [];
    foreach ($articles as $row) {
      $u = trim((string) ($row['url'] ?? ''));
      if ($u !== '') {
        $allowedUrls[$u] = TRUE;
        if (str_contains($u, '?')) {
          $allowedUrls[explode('?', $u, 2)[0]] = TRUE;
        }
      }
    }

    $answer = preg_replace_callback(
      '#https?://[^\s<>"\')\]]+#iu',
      static function (array $m) use ($allowedUrls): string {
        $url = $m[0];
        $trim = rtrim($url, '.,;:!?');
        return isset($allowedUrls[$url]) || isset($allowedUrls[$trim]) ? $url : '';
      },
      $answer,
    ) ?? '';

    $answer = preg_replace('/\bwww\.[^\s<>"\')\]]+/iu', '', $answer) ?? $answer;

    $contextBlob = '';
    foreach ($results as $row) {
      $contextBlob .= ' ' . ($row['title'] ?? '') . ' ' . ($row['summary'] ?? '') . ' ' . ($row['content'] ?? '');
    }
    $contextBlob = mb_strtolower($contextBlob);

    $tokens = $this->significantTokens($answer);
    $foreign = 0;
    foreach ($tokens as $token) {
      if (mb_strlen($token) < 5) {
        continue;
      }
      if (!str_contains($contextBlob, $token)) {
        $foreign++;
      }
    }
    if ($foreign >= 4) {
      return '';
    }

    $answer = trim(preg_replace('/\s+/u', ' ', $answer) ?? '');
    return $answer;
  }

  /**
   * @param array<string, mixed>|null $json
   */
  private function decodeStructuredResult(?array $json, string $raw): ?array {
    if (is_array($json)) {
      return $json;
    }
    $decoded = json_decode(trim($raw), TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

  private function normaliseConfidence(string $confidence): string {
    $confidence = mb_strtolower(trim($confidence));
    if (!in_array($confidence, ['high', 'medium', 'low'], TRUE)) {
      return 'low';
    }
    return $confidence;
  }

  /**
   * Merges retrieval and model confidence using lower-bound safety.
   */
  private function mergeConfidence(string $retrieval, string $model): string {
    $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
    $safeRank = min($rank[$retrieval] ?? 1, $rank[$model] ?? 1);
    return array_search($safeRank, $rank, TRUE) ?: 'low';
  }

  /**
   * @param array<int, array<string, mixed>>                                        $results
   *
   * @return array<int, array<string, string>>
   */
  private function buildArticleLinks(array $results): array {
    $articles = [];
    foreach ($results as $result) {
      $title = trim((string) ($result['title'] ?? ''));
      $url = trim((string) ($result['url'] ?? ''));
      if ($title === '' || $url === '') {
        continue;
      }
      $articles[] = [
        'title' => $title,
        'url' => $url,
      ];
    }
    return $articles;
  }

  /**
   * Returns article links that appear in retrieval results only.
   *
   * @param mixed                                                                    $modelArticles
   * @param array<int, array<string, string>>                                        $fallback
   *
   * @return array<int, array<string, string>>
   */
  private function groundedArticleLinks(mixed $modelArticles, array $fallback): array {
    if ($fallback === []) {
      return [];
    }

    $byUrl = [];
    foreach ($fallback as $row) {
      $url = trim((string) ($row['url'] ?? ''));
      if ($url !== '') {
        $byUrl[$url] = [
          'title' => trim((string) ($row['title'] ?? '')),
          'url' => $url,
        ];
      }
    }

    if (!is_array($modelArticles)) {
      return array_values(array_filter($byUrl, static fn (array $item): bool => $item['title'] !== ''));
    }

    $ordered = [];
    foreach ($modelArticles as $row) {
      if (!is_array($row)) {
        continue;
      }
      $url = trim((string) ($row['url'] ?? ''));
      if ($url === '' || !isset($byUrl[$url])) {
        continue;
      }
      $item = $byUrl[$url];
      if ($item['title'] !== '') {
        $ordered[] = $item;
      }
    }

    return $ordered !== [] ? $ordered : array_values(array_filter(
      $byUrl,
      static fn (array $item): bool => $item['title'] !== '',
    ));
  }

  /**
   * @param array<int, array<string, mixed>> $results
   *
   * @return array<string, true>
   */
  private function collectAllowedActionTargets(array $results, string $supportUrl): array {
    $allowed = [];
    foreach ($results as $row) {
      foreach (['url', 'cta_url'] as $key) {
        $raw = trim((string) ($row[$key] ?? ''));
        if ($raw === '') {
          continue;
        }
        $allowed[$raw] = TRUE;
        $path = $this->normaliseInternalPath($raw);
        if ($path !== '') {
          $allowed[$path] = TRUE;
        }
      }
    }
    $supportRaw = trim($supportUrl);
    if ($supportRaw !== '') {
      $allowed[$supportRaw] = TRUE;
      $p = $this->normaliseInternalPath($supportRaw);
      if ($p !== '') {
        $allowed[$p] = TRUE;
      }
    }
    return $allowed;
  }

  private function normaliseInternalPath(string $url): string {
    $url = trim($url);
    if ($url === '') {
      return '';
    }
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
      $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
      return $path;
    }
    if (!str_starts_with($url, '/')) {
      return '/' . ltrim($url, '/');
    }
    return $url;
  }

  private function isSafeRelativeActionPath(string $path): bool {
    if ($path === '' || !str_starts_with($path, '/')) {
      return FALSE;
    }
    if (preg_match('#//#u', $path) || preg_match('/[\s<>"`]/u', $path)) {
      return FALSE;
    }
    return !str_contains(strtolower($path), 'javascript:');
  }

  /**
   * @return array<int, array<string, string>>
   */
  private function groundedActions(mixed $actions, array $results, string $supportUrl): array {
    $allowed = $this->collectAllowedActionTargets($results, $supportUrl);
    if (!is_array($actions)) {
      return [];
    }
    $out = [];
    foreach ($actions as $row) {
      if (!is_array($row)) {
        continue;
      }
      $label = trim(strip_tags((string) ($row['label'] ?? '')));
      $rawUrl = trim((string) ($row['url'] ?? ''));
      if ($label === '' || $rawUrl === '') {
        continue;
      }
      $path = $this->normaliseInternalPath($rawUrl);
      if ($path === '' || !$this->isSafeRelativeActionPath($path)) {
        continue;
      }
      if (!isset($allowed[$rawUrl]) && !isset($allowed[$path])) {
        continue;
      }
      $out[] = [
        'label' => mb_substr($label, 0, 120),
        'url' => $path,
      ];
      if (count($out) >= 4) {
        break;
      }
    }
    return $out;
  }

  /**
   * @return list<string>
   */
  private function sanitiseSteps(mixed $steps): array {
    if (!is_array($steps)) {
      return [];
    }
    $out = [];
    foreach ($steps as $step) {
      $s = trim(strip_tags((string) $step));
      if ($s === '') {
        continue;
      }
      $out[] = mb_substr($s, 0, 400);
      if (count($out) >= 8) {
        break;
      }
    }
    return $out;
  }

  /**
   * @return list<string>
   */
  private function sanitiseFollowups(mixed $followups): array {
    if (!is_array($followups)) {
      return [];
    }
    $out = [];
    foreach ($followups as $q) {
      $s = trim(strip_tags((string) $q));
      if ($s === '' || mb_strlen($s) < 8) {
        continue;
      }
      $out[] = mb_substr($s, 0, 200);
      if (count($out) >= 4) {
        break;
      }
    }
    return $out;
  }

  /**
   * @param array<int, array<string, string>> $articles
   */
  private function buildFallbackPayload(array $articles, string $confidence, string $supportUrl, bool $empty_retrieval = FALSE): array {
    if ($empty_retrieval) {
      $answer = (string) $this->t('We couldn’t find a perfect answer in the Help Centre yet. Try rephrasing your question or open one of the guides below when they appear.');
    }
    elseif ($articles === []) {
      $answer = (string) $this->t('We couldn’t find a perfect answer yet. Try contacting support with your event or booking details.');
    }
    else {
      $answer = (string) $this->t('We couldn’t find a perfect answer — here are some helpful guides from the Help Centre.');
    }

    return $this->withStructuredFields([
      'status' => 'fallback',
      'answer' => $answer,
      'confidence' => $confidence,
      'escalation_recommended' => TRUE,
      'message' => $this->buildEscalationText($supportUrl),
      'hide_chat_expectations' => FALSE,
    ], $articles, [], [], []);
  }

  private function buildEscalationText(string $supportUrl): string {
    if ($supportUrl !== '') {
      return (string) $this->t('I cannot confirm this from the Help Centre. For a personalised answer, open a support request at @url.', ['@url' => $supportUrl]);
    }
    return (string) $this->t('I cannot confirm this from the Help Centre. Please contact support for personalised help.');
  }

  private function sanitiseQuestion(string $question): string {
    $question = strip_tags($question);
    $question = preg_replace('/\s+/', ' ', $question) ?? '';
    return mb_substr(trim($question), 0, 500);
  }

}
