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

    $context = [
      'title' => (string) ($data['title'] ?? ''),
      'summary' => (string) ($data['summary'] ?? ''),
      'category' => (string) ($data['category'] ?? ''),
      'tags' => (string) ($data['tags'] ?? ''),
      'tone' => (string) ($data['tone'] ?? 'community'),
      'audience' => (string) ($data['audience'] ?? 'general'),
    ];

    $uid = $this->currentUser->isAuthenticated() ? (int) $this->currentUser->id() : NULL;

    try {
      $result = $this->aiGenerator->generateEventCopy($context, $uid, NULL);

      return new JsonResponse([
        'ok' => TRUE,
        'title' => $result['title'],
        'summary' => $result['summary'],
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio AI generation failed: @msg', [
        '@msg' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'AI generation failed',
      ], 500);
    }
  }

}
