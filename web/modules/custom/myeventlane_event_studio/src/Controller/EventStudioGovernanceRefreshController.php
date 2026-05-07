<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioGovernanceBuilder;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * POST vendor-only incremental governance refresh for Event Studio (saved events).
 */
final class EventStudioGovernanceRefreshController {

  public function __construct(
    private readonly EventStudioGovernanceBuilder $governanceBuilder,
    private readonly AccountProxyInterface $currentUser,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
    private readonly LoggerInterface $logger,
  ) {}

  public function refresh(NodeInterface $node, Request $request): JsonResponse {
    if (!$request->isMethod('POST')) {
      return new JsonResponse(['ok' => FALSE], 405);
    }

    if ($node->bundle() !== 'event') {
      return new JsonResponse(['ok' => FALSE, 'message' => 'Not found'], 404);
    }

    $account = $this->currentUser;
    if ($account->isAnonymous()) {
      $this->logger->warning('Event Studio governance refresh denied: anonymous nid=@nid', [
        '@nid' => (string) $node->id(),
      ]);
      throw new AccessDeniedHttpException();
    }

    if (!$account->hasPermission('administer nodes')) {
      if (!$node->access('update', $account)) {
        $this->logger->warning('Event Studio governance refresh denied: node update nid=@nid uid=@uid', [
          '@nid' => (string) $node->id(),
          '@uid' => (string) $account->id(),
        ]);
        throw new AccessDeniedHttpException();
      }
      if (!$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($node, $account)) {
        $this->logger->warning('Event Studio governance refresh denied: workspace parity nid=@nid uid=@uid', [
          '@nid' => (string) $node->id(),
          '@uid' => (string) $account->id(),
        ]);
        throw new AccessDeniedHttpException();
      }
    }

    $baseline = NULL;
    $raw = $request->getContent();
    if ($raw !== '') {
      try {
        $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded) && isset($decoded['baseline']) && is_array($decoded['baseline'])) {
          $baseline = $decoded['baseline'];
        }
      }
      catch (\JsonException $e) {
        $this->logger->notice('Event Studio governance refresh: invalid JSON uid=@uid @message', [
          '@uid' => (string) $account->id(),
          '@message' => $e->getMessage(),
        ]);
        return new JsonResponse(['ok' => FALSE, 'message' => 'Invalid JSON'], 400);
      }
    }

    $delta_bundle = $this->governanceBuilder->buildDeltaPayload($node, $baseline);
    $response = new JsonResponse([
      'ok' => TRUE,
      'governance_enabled' => $delta_bundle['governance_enabled'],
      'unchanged' => $delta_bundle['unchanged'],
      'delta' => $delta_bundle['delta'],
    ]);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
