<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_boost\Service\BoostImpressionAttributionService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives client-side Boost impression beacons.
 */
final class BoostImpressionController extends ControllerBase {

  /**
   * Constructs a BoostImpressionController.
   */
  public function __construct(
    private readonly BoostImpressionAttributionService $attributionService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_boost.impression_attribution'),
    );
  }

  /**
   * Records a validated Boost card impression.
   */
  public function record(Request $request): Response {
    $payload = $this->decodePayload($request);
    if ($payload === NULL) {
      return new JsonResponse(['status' => 'error', 'message' => 'invalid_payload'], 400);
    }

    $orderItemId = (int) ($payload['order_item_id'] ?? 0);
    $eventId = (int) ($payload['event_id'] ?? 0);
    $placement = trim((string) ($payload['placement'] ?? ''));

    $recorded = $this->attributionService->recordValidatedImpression($orderItemId, $eventId, $placement);
    if (!$recorded) {
      return new JsonResponse(['status' => 'error', 'message' => 'not_recorded'], 400);
    }

    return new JsonResponse(['status' => 'ok']);
  }

  /**
   * @return array<string, mixed>|null
   *   Decoded JSON payload or NULL when malformed.
   */
  private function decodePayload(Request $request): ?array {
    $contentType = (string) $request->headers->get('Content-Type', '');
    if (str_contains($contentType, 'application/json')) {
      $raw = $request->getContent();
      if ($raw === '') {
        return NULL;
      }
      try {
        $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException) {
        return NULL;
      }
      return is_array($decoded) ? $decoded : NULL;
    }

    $orderItemId = $request->request->get('order_item_id');
    $eventId = $request->request->get('event_id');
    $placement = $request->request->get('placement');
    if ($orderItemId === NULL && $eventId === NULL && $placement === NULL) {
      return NULL;
    }

    return [
      'order_item_id' => $orderItemId,
      'event_id' => $eventId,
      'placement' => $placement,
    ];
  }

}
