<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_help_assistant\Service\HelpAssistantService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for Help Assistant page and JSON endpoint.
 */
final class HelpAssistantController extends ControllerBase {

  /**
   * Flood event names must fit core flood.event column (varchar 64).
   */
  private const FLOOD_EVENT_ASK = 'myeventlane_help_assistant.ask';

  private const FLOOD_EVENT_IP = 'myeventlane_help_assistant.ip';

  public function __construct(
    private readonly HelpAssistantService $helpAssistantService,
    private readonly ConfigFactoryInterface $assistantConfigFactory,
    private readonly FloodInterface $flood,
    private readonly RequestStack $requestStack,
    private readonly AccountProxyInterface $accountProxy,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_help_assistant.assistant'),
      $container->get('config.factory'),
      $container->get('flood'),
      $container->get('request_stack'),
      $container->get('current_user'),
    );
  }

  /**
   * Builds the Help Assistant page.
   */
  public function page(): array {
    return [
      '#theme' => 'myeventlane_help_assistant_page',
      '#attached' => [
        'library' => [
          'myeventlane_help_assistant/assistant',
        ],
        'drupalSettings' => [
          'myeventlaneHelpAssistant' => [
            'endpoint' => '/help/assistant',
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Handles AJAX assistant question requests.
   */
  public function ask(Request $request): JsonResponse {
    if (!$this->isAllowedByFlood($request)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Too many requests. Please wait a few minutes and try again.'),
      ], 429);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Invalid request body.'),
      ], 400);
    }

    $question = trim((string) ($payload['question'] ?? ''));
    $question = preg_replace('/\s+/', ' ', strip_tags($question)) ?? '';
    if ($question === '') {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Question is required.'),
      ], 422);
    }

    $result = $this->helpAssistantService->answerQuestion($question);
    return new JsonResponse($result);
  }

  /**
   * Applies per-IP burst limits and per-session hourly limits.
   */
  private function isAllowedByFlood(Request $request): bool {
    $settings = $this->assistantConfigFactory->get('myeventlane_help_assistant.settings');
    $hourly = (int) ($settings->get('max_requests_per_hour') ?? $settings->get('rate_limit_per_session_hour') ?? 20);
    $hourly = max(5, min($hourly, 100));
    $burst = (int) ($settings->get('max_requests_per_10min') ?? 5);
    $burst = max(1, min($burst, 60));

    $sessionId = '';
    $currentRequest = $this->requestStack->getCurrentRequest();
    $session = $currentRequest?->getSession();
    if ($session) {
      $sessionId = (string) $session->getId();
    }
    if ($sessionId === '') {
      $sessionId = 'ip:' . (string) $request->getClientIp();
    }

    $userPart = $this->accountProxy->isAuthenticated() ? (string) $this->accountProxy->id() : 'anon';
    $visitorKey = hash('sha256', $userPart . '|' . $sessionId);
    $ipKey = hash('sha256', 'ip|' . (string) $request->getClientIp());

    if (!$this->flood->isAllowed(self::FLOOD_EVENT_IP, $burst, 600, $ipKey)) {
      return FALSE;
    }
    if (!$this->flood->isAllowed(self::FLOOD_EVENT_ASK, $hourly, 3600, $visitorKey)) {
      return FALSE;
    }

    $this->flood->register(self::FLOOD_EVENT_IP, 600, $ipKey);
    $this->flood->register(self::FLOOD_EVENT_ASK, 3600, $visitorKey);
    return TRUE;
  }

}
