<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\myeventlane_tickets\Service\TicketCheckinService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\myeventlane_tickets\Service\EventAccess;
use Psr\Log\LoggerInterface;

/**
 * JSON API controller for scanner submissions.
 */
final class TicketCheckinApiController extends ControllerBase {

  public function __construct(
    private readonly EventAccess $eventAccess,
    private readonly TicketCheckinService $ticketCheckinService,
    private readonly CsrfTokenGenerator $csrfTokenGenerator,
    private readonly FloodInterface $flood,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_tickets.event_access'),
      $container->get('myeventlane_tickets.ticket_checkin_service'),
      $container->get('csrf_token'),
      $container->get('flood'),
      $container->get('logger.channel.myeventlane_tickets'),
    );
  }

  /**
   * Handles one live scanner submission.
   */
  public function submit(NodeInterface $event, Request $request): JsonResponse {
    $this->assertEventAccess($event);
    if (!$this->validateCsrfToken($request, (int) $event->id())) {
      return new JsonResponse(['ok' => FALSE, 'result' => 'error', 'message' => 'Invalid CSRF token.'], 403);
    }

    $data = $this->decodeJsonBody($request);
    if ($data === NULL) {
      return new JsonResponse(['ok' => FALSE, 'result' => 'invalid', 'message' => 'Invalid request body.'], 400);
    }

    $input = trim((string) ($data['input'] ?? ''));
    $device_id = $this->normalizeDeviceId((string) ($data['device_id'] ?? 'unknown-device'));
    $mode = (string) ($data['mode'] ?? 'online');

    if (!$this->checkRateLimit((int) $event->id(), $device_id)) {
      return new JsonResponse(['ok' => FALSE, 'result' => 'error', 'message' => 'Rate limit exceeded. Try again shortly.'], 429);
    }

    $result = $this->ticketCheckinService->checkIn((int) $event->id(), $input, $device_id, $mode);
    return new JsonResponse($result, 200);
  }

  /**
   * Handles queued offline submissions in one request.
   */
  public function batch(NodeInterface $event, Request $request): JsonResponse {
    $this->assertEventAccess($event);
    if (!$this->validateCsrfToken($request, (int) $event->id())) {
      return new JsonResponse(['ok' => FALSE, 'result' => 'error', 'message' => 'Invalid CSRF token.'], 403);
    }

    $data = $this->decodeJsonBody($request);
    if ($data === NULL || !isset($data['items']) || !is_array($data['items'])) {
      return new JsonResponse(['ok' => FALSE, 'result' => 'invalid', 'message' => 'Invalid batch payload.'], 400);
    }

    $device_id = $this->normalizeDeviceId((string) ($data['device_id'] ?? 'unknown-device'));
    $items = array_slice($data['items'], 0, 250);
    $results = [];
    $processed = 0;

    foreach ($items as $item) {
      $input = trim((string) ($item['input'] ?? ''));
      if ($input === '') {
        $results[] = [
          'ok' => FALSE,
          'result' => 'invalid',
          'message' => 'Ticket code is required.',
          'ticket_label' => '----',
          'checked_in_at' => 0,
        ];
        continue;
      }

      if (!$this->checkRateLimit((int) $event->id(), $device_id)) {
        $results[] = [
          'ok' => FALSE,
          'result' => 'error',
          'message' => 'Rate limit exceeded while processing batch.',
          'ticket_label' => '----',
          'checked_in_at' => 0,
        ];
        continue;
      }

      $results[] = $this->ticketCheckinService->checkIn((int) $event->id(), $input, $device_id, 'offline');
      $processed++;
    }

    return new JsonResponse([
      'ok' => TRUE,
      'processed' => $processed,
      'results' => $results,
    ], 200);
  }

  /**
   * Enforces event context access constraints.
   */
  private function assertEventAccess(NodeInterface $event): void {
    if ($event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    if (!$this->eventAccess->canManageEventTickets($event)) {
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * Validates API CSRF token from header.
   */
  private function validateCsrfToken(Request $request, int $event_id): bool {
    $token = (string) $request->headers->get('X-CSRF-Token', '');
    if ($token === '') {
      return FALSE;
    }
    return $this->csrfTokenGenerator->validate($token, 'myeventlane_tickets_checkin_api:' . $event_id);
  }

  /**
   * Decodes JSON body safely.
   *
   * @return array<string, mixed>|null
   *   Decoded payload or NULL when invalid.
   */
  private function decodeJsonBody(Request $request): ?array {
    try {
      $raw = (string) $request->getContent();
      if ($raw === '') {
        return [];
      }
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
      return is_array($decoded) ? $decoded : NULL;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Ticket check-in API received invalid JSON body: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Applies flood control by event and device.
   */
  private function checkRateLimit(int $event_id, string $device_id): bool {
    $identifier = $event_id . ':' . $device_id;
    $allowed = $this->flood->isAllowed('myeventlane_tickets.checkin_api', 30, 60, $identifier);
    if ($allowed) {
      $this->flood->register('myeventlane_tickets.checkin_api', 60, $identifier);
    }
    return $allowed;
  }

  /**
   * Normalizes device id for storage and throttling.
   */
  private function normalizeDeviceId(string $device_id): string {
    $normalized = preg_replace('/[^a-zA-Z0-9\-_]/', '', trim($device_id)) ?? '';
    return $normalized !== '' ? substr($normalized, 0, 64) : 'unknown-device';
  }

}
