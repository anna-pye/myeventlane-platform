<?php

declare(strict_types=1);

namespace Drupal\myeventlane_growth\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_growth\Service\GrowthTrackingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Records dismissal of dismissible growth insights.
 */
final class GrowthDismissController extends ControllerBase {

  public function __construct(
    private readonly GrowthTrackingService $tracking,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_growth.tracking'),
      $container->get('csrf_token'),
    );
  }

  public function dismiss(Request $request): JsonResponse {
    $token = $request->headers->get('X-CSRF-Token', '');
    if (!$this->csrfToken->validate($token, 'rest')) {
      throw new AccessDeniedHttpException('Invalid CSRF token.');
    }
    $raw = $request->getContent();
    $data = $raw !== '' ? Json::decode($raw) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    $key = (string) ($data['insight_key'] ?? '');
    $surface = (string) ($data['surface'] ?? 'dashboard');
    $eventId = isset($data['event_id']) && is_numeric($data['event_id']) ? (int) $data['event_id'] : NULL;
    if ($eventId !== NULL && $eventId <= 0) {
      $eventId = NULL;
    }
    if ($key === '') {
      throw new BadRequestHttpException('Missing insight_key.');
    }
    if ($this->isNonDismissibleKey($key)) {
      throw new AccessDeniedHttpException('This insight cannot be dismissed.');
    }
    $uid = (int) $this->currentUser()->id();
    $this->tracking->recordDismiss($key, $uid, $eventId, $surface);
    return new JsonResponse(['status' => 'ok']);
  }

  private function isNonDismissibleKey(string $key): bool {
    return $key === 'pro_grace_access_risk';
  }

}
