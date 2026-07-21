<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_ai\Service\AiManager;
use Drupal\myeventlane_event_studio\Service\EventStudioAiPromptBuilder;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Event-scoped MEL Write with AI endpoint for Event Studio fields.
 */
final class EventStudioAiController {

  public function __construct(
    private readonly AiManager $aiManager,
    private readonly EventStudioAiPromptBuilder $promptBuilder,
    private readonly AccountProxyInterface $currentUser,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
    private readonly LoggerInterface $logger,
  ) {}

  public function assist(Request $request, NodeInterface $node): JsonResponse {
    if (!$request->isMethod('POST')) {
      return new JsonResponse(['ok' => FALSE, 'success' => FALSE, 'error' => 'Method not allowed'], 405);
    }

    $this->assertCanAssist($node);
    $data = $this->decodeJsonBody($request);
    if ($data instanceof JsonResponse) {
      return $data;
    }

    $field_name = (string) ($data['field'] ?? $data['target'] ?? '');
    if (!$this->promptBuilder->isSupportedField($field_name)) {
      $this->logger->notice('Event Studio AI assist rejected unsupported field @field for nid @nid', [
        '@field' => $field_name,
        '@nid' => (string) $node->id(),
      ]);
      return new JsonResponse(['ok' => FALSE, 'success' => FALSE, 'error' => 'Unsupported field'], 400);
    }

    try {
      $input = $this->normalizeInput($data, $field_name);
      $request_bundle = $this->promptBuilder->build($node, $field_name, $input);
      $result = $this->aiManager->analyze(
        $request_bundle['definition'],
        $request_bundle['options'],
        $this->currentUser->isAuthenticated() ? (int) $this->currentUser->id() : NULL,
        $request_bundle['scope_id'],
        $request_bundle['vendor_id'],
      );
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['ok' => FALSE, 'success' => FALSE, 'error' => $e->getMessage()], 400);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio AI assist failed before provider call nid=@nid field=@field: @message', [
        '@nid' => (string) $node->id(),
        '@field' => $field_name,
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse(['ok' => FALSE, 'success' => FALSE, 'error' => 'AI is unavailable right now.'], 200);
    }

    $this->logger->notice('Event Studio AI assist result ok=@ok nid=@nid field=@field', [
      '@ok' => $result->ok ? '1' : '0',
      '@nid' => (string) $node->id(),
      '@field' => $field_name,
    ]);

    if (!$result->ok) {
      return new JsonResponse([
        'ok' => FALSE,
        'success' => FALSE,
        'error' => $result->error ?: 'AI is unavailable right now.',
        'text' => '',
      ], 200);
    }

    $text = $this->promptBuilder->extractGeneratedText($result->json, $result->raw);
    if ($text === '') {
      return new JsonResponse(['ok' => FALSE, 'success' => FALSE, 'error' => 'AI did not return usable text.', 'text' => ''], 200);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'success' => TRUE,
      'field' => $field_name,
      'text' => $text,
      'error' => '',
    ], 200);
  }

  /**
   * @param array<string, mixed> $data
   *
   * @return array<string, mixed>
   */
  private function normalizeInput(array $data, string $field_name): array {
    $context = $data['context'] ?? [];
    if (!is_array($context)) {
      $context = [];
    }
    $event_context = $data['event_context'] ?? ($context['event_context'] ?? []);
    if (!is_array($event_context)) {
      $event_context = [];
    }

    $context['prompt_type'] = (string) ($data['prompt_type'] ?? $context['prompt_type'] ?? '');
    $context['current_value'] = (string) ($data['current_value'] ?? $context['current_value'] ?? '');
    $context['event_context'] = $event_context;
    $context['field'] = $field_name;

    return $context;
  }

  private function assertCanAssist(NodeInterface $node): void {
    $account = $this->currentUser;
    if ($account->isAnonymous() || $node->bundle() !== 'event') {
      $this->logger->warning('Event Studio AI assist denied: invalid event or anonymous nid=@nid uid=@uid', [
        '@nid' => (string) $node->id(),
        '@uid' => (string) $account->id(),
      ]);
      throw new AccessDeniedHttpException();
    }

    if ($account->hasPermission('administer nodes')) {
      return;
    }

    if (!$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($node, $account)) {
      $this->logger->warning('Event Studio AI assist denied: workspace parity nid=@nid uid=@uid', [
        '@nid' => (string) $node->id(),
        '@uid' => (string) $account->id(),
      ]);
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * @return array<string, mixed>|\Symfony\Component\HttpFoundation\JsonResponse
   */
  private function decodeJsonBody(Request $request): array|JsonResponse {
    $raw = $request->getContent();
    if ($raw === '') {
      return [];
    }

    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->logger->notice('Event Studio AI assist: invalid JSON body: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse(['ok' => FALSE, 'success' => FALSE, 'error' => 'Invalid JSON'], 400);
    }

    return is_array($decoded) ? $decoded : [];
  }

}
