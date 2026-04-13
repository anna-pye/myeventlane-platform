<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_ai\Service\AiManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * POST JSON: AI-generated title + summary for Event Studio Basic step.
 */
final class EventStudioAiController implements ContainerInjectionInterface {

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_ai.generator'),
      $container->get('current_user'),
      $container->get('logger.channel.myeventlane_event_studio'),
    );
  }

  public function __construct(
    private readonly AiManager $aiGenerator,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  public function generate(Request $request): JsonResponse {
    if (!$request->isMethod('POST')) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Method not allowed'], 405);
    }

    $raw = $request->getContent();
    $data = [];
    if ($raw !== '') {
      try {
        $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
        $data = is_array($decoded) ? $decoded : [];
      }
      catch (\JsonException $e) {
        $this->logger->notice('Event Studio AI: invalid JSON body: @msg', ['@msg' => $e->getMessage()]);
        return new JsonResponse(['ok' => FALSE, 'error' => 'Invalid JSON'], 400);
      }
    }

    $title = (string) ($data['title'] ?? '');
    $summary = (string) ($data['summary'] ?? '');
    $category = (string) ($data['category'] ?? '');
    $tags = (string) ($data['tags'] ?? '');
    $tone = (string) ($data['tone'] ?? 'community');
    $audience = (string) ($data['audience'] ?? 'general');

    $uid = $this->currentUser->isAuthenticated() ? (int) $this->currentUser->id() : NULL;

    $response = $this->aiGenerator->generateEventCopyReliable([
      'title' => $title,
      'summary' => $summary,
      'category' => $category,
      'tags' => $tags,
      'tone' => $tone,
      'audience' => $audience,
    ], $uid, NULL);

    \Drupal::logger('myeventlane_ai')->notice('AI result ok=@ok', [
      '@ok' => !empty($response['ok']) ? '1' : '0',
    ]);

    if (empty($response['ok'])) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => $response['error'] ?? 'AI failed',
        'title' => '',
        'summary' => '',
      ], 200);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'title' => $response['title'] ?? '',
      'summary' => $response['summary'] ?? '',
      'error' => '',
    ], 200);
  }

  /**
   * POST JSON: AI rewrite for About (body) and What to expect (field_event_intro).
   */
  public function rewrite(Request $request): JsonResponse {
    if (!$request->isMethod('POST')) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Method not allowed'], 405);
    }

    $raw = $request->getContent();
    $data = [];
    if ($raw !== '') {
      try {
        $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
        $data = is_array($decoded) ? $decoded : [];
      }
      catch (\JsonException $e) {
        $this->logger->notice('Event Studio AI rewrite: invalid JSON body: @msg', ['@msg' => $e->getMessage()]);
        return new JsonResponse(['ok' => FALSE, 'error' => 'Invalid JSON'], 400);
      }
    }

    $payload = [
      'about' => (string) ($data['about'] ?? ''),
      'what_to_expect' => (string) ($data['what_to_expect'] ?? ''),
      'category' => (string) ($data['category'] ?? ''),
      'tone' => (string) ($data['tone'] ?? 'community'),
      'audience' => (string) ($data['audience'] ?? 'general'),
    ];

    $uid = $this->currentUser->isAuthenticated() ? (int) $this->currentUser->id() : NULL;

    $response = $this->aiGenerator->rewriteEventContentReliable($payload, $uid, NULL);

    \Drupal::logger('myeventlane_ai')->notice('AI rewrite ok=@ok', [
      '@ok' => !empty($response['ok']) ? '1' : '0',
    ]);

    if (empty($response['ok'])) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => $response['error'] ?? 'AI failed',
        'about' => $response['about'] ?? '',
        'what_to_expect' => $response['what_to_expect'] ?? '',
      ], 200);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'about' => $response['about'] ?? '',
      'what_to_expect' => $response['what_to_expect'] ?? '',
      'error' => '',
    ], 200);
  }

}
