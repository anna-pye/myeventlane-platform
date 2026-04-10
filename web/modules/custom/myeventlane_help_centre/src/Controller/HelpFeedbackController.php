<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_help_centre\Service\HelpAnalyticsService;
use Drupal\myeventlane_help_centre\Service\HelpPanelAnalytics;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles Help article feedback submissions.
 */
final class HelpFeedbackController extends ControllerBase {

  use StringTranslationTrait;

  public function __construct(
    private readonly Connection $database,
    private readonly FloodInterface $flood,
    private readonly RequestStack $requestStack,
    private readonly AccountProxyInterface $accountProxy,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly HelpAnalyticsService $analyticsService,
    private readonly HelpPanelAnalytics $panelAnalytics,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('flood'),
      $container->get('request_stack'),
      $container->get('current_user'),
      $container->get('csrf_token'),
      $container->get('myeventlane_help_centre.analytics'),
      $container->get('myeventlane_help.analytics'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Stores "Was this helpful?" feedback.
   */
  public function submitFeedback(NodeInterface $node, Request $request): JsonResponse {
    if ($node->bundle() !== 'help_article') {
      return new JsonResponse(['status' => 'error', 'message' => $this->t('Invalid help article.')], 400);
    }

    $token = (string) ($request->headers->get('X-CSRF-Token') ?? $request->request->get('token', ''));
    if ($token === '' || !$this->csrfToken->validate($token, 'myeventlane_help_feedback')) {
      return new JsonResponse(['status' => 'error', 'message' => $this->t('Invalid CSRF token.')], 403);
    }

    $helpfulRaw = $request->request->get('helpful');
    if (!in_array((string) $helpfulRaw, ['0', '1'], TRUE)) {
      return new JsonResponse(['status' => 'error', 'message' => $this->t('Invalid feedback value.')], 422);
    }
    $helpful = ((string) $helpfulRaw === '1');

    $currentRequest = $this->requestStack->getCurrentRequest();
    $session = $currentRequest?->getSession();
    $sessionId = $session ? $session->getId() : '';
    if ($sessionId === '') {
      $sessionId = substr(hash('sha256', (string) $request->getClientIp()), 0, 64);
    }

    $floodKey = sprintf('help_feedback.%d.%s', (int) $node->id(), $sessionId);
    if (!$this->flood->isAllowed($floodKey, 5, 3600)) {
      return new JsonResponse(['status' => 'error', 'message' => $this->t('Too many feedback submissions. Please try again later.')], 429);
    }
    $this->flood->register($floodKey, 3600);

    $uid = $this->accountProxy->isAuthenticated() ? (int) $this->accountProxy->id() : NULL;
    $this->database->insert('myeventlane_help_feedback')
      ->fields([
        'node_id' => (int) $node->id(),
        'helpful' => $helpful ? 1 : 0,
        'created' => $this->time->getRequestTime(),
        'uid' => $uid,
        'session_id' => $sessionId,
      ])
      ->execute();

    $this->analyticsService->logFeedback((int) $node->id(), $helpful);

    return new JsonResponse([
      'status' => 'ok',
      'helpful' => $helpful ? 1 : 0,
      'message' => $this->t('Thanks for your feedback.'),
    ]);
  }

  /**
   * Stores "Was this helpful?" feedback for support panels.
   */
  public function submitPanelFeedback(Request $request): JsonResponse {
    $token = (string) ($request->headers->get('X-CSRF-Token') ?? '');
    if ($token === '' || !$this->csrfToken->validate($token, 'rest')) {
      return new JsonResponse(['status' => 'error', 'message' => $this->t('Invalid CSRF token.')], 403);
    }

    $payload = json_decode($request->getContent() ?: '', TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['status' => 'error', 'message' => $this->t('Invalid payload.')], 400);
    }

    $key = trim((string) ($payload['key'] ?? ''));
    if ($key === '' || !array_key_exists('helpful', $payload)) {
      return new JsonResponse(['status' => 'error', 'message' => $this->t('Missing required fields.')], 400);
    }

    $helpfulRaw = $payload['helpful'];
    $helpful = match (TRUE) {
      is_bool($helpfulRaw) => $helpfulRaw,
      $helpfulRaw === 1 => TRUE,
      $helpfulRaw === 0 => FALSE,
      $helpfulRaw === '1' => TRUE,
      $helpfulRaw === '0' => FALSE,
      default => NULL,
    };
    if (!is_bool($helpful)) {
      return new JsonResponse(['status' => 'error', 'message' => $this->t('Invalid helpful value.')], 422);
    }

    $this->panelAnalytics->logFeedback($key, $helpful);

    return new JsonResponse(['status' => 'ok']);
  }

}
