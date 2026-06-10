<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioGovernanceBuilder;
use Drupal\myeventlane_event_studio\Service\EventStudioGovernanceComponentBuilder;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * POST vendor-only governed component refresh for Event Studio.
 */
final class EventStudioGovernanceComponentController {

  public function __construct(
    private readonly EventStudioGovernanceBuilder $governanceBuilder,
    private readonly EventStudioGovernanceComponentBuilder $componentBuilder,
    private readonly RendererInterface $renderer,
    private readonly AccountProxyInterface $currentUser,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
    private readonly LoggerInterface $logger,
  ) {}

  public function render(NodeInterface $node, string $component, Request $request): JsonResponse {
    if (!$request->isMethod('POST')) {
      return new JsonResponse(['ok' => FALSE], 405);
    }

    if ($node->bundle() !== 'event') {
      return new JsonResponse(['ok' => FALSE, 'message' => 'Not found'], 404);
    }

    if (!$this->componentBuilder->isAllowedComponent($component)) {
      $this->logger->notice('Event Studio component refresh rejected invalid component=@component nid=@nid', [
        '@component' => $component,
        '@nid' => (string) $node->id(),
      ]);
      return new JsonResponse(['ok' => FALSE, 'message' => 'Unknown component'], 404);
    }

    $account = $this->currentUser;
    if ($account->isAnonymous()) {
      $this->logger->warning('Event Studio component refresh denied: anonymous component=@component nid=@nid', [
        '@component' => $component,
        '@nid' => (string) $node->id(),
      ]);
      throw new AccessDeniedHttpException();
    }

    if (!$account->hasPermission('administer nodes')) {
      if (!$node->access('update', $account)) {
        $this->logger->warning('Event Studio component refresh denied: node update component=@component nid=@nid uid=@uid', [
          '@component' => $component,
          '@nid' => (string) $node->id(),
          '@uid' => (string) $account->id(),
        ]);
        throw new AccessDeniedHttpException();
      }
      if (!$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($node, $account)) {
        $this->logger->warning('Event Studio component refresh denied: workspace parity component=@component nid=@nid uid=@uid', [
          '@component' => $component,
          '@nid' => (string) $node->id(),
          '@uid' => (string) $account->id(),
        ]);
        throw new AccessDeniedHttpException();
      }
    }

    try {
      $governance_bundle = $this->governanceBuilder->buildForEvent($node);
      if (empty($governance_bundle['enabled'])) {
        $response = new JsonResponse([
          'ok' => TRUE,
          'governance_enabled' => FALSE,
          'component' => $component,
          'html' => '',
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
      }

      $build = $this->componentBuilder->buildComponent($governance_bundle, $component);
      $html = (string) $this->renderer->renderRoot($build);
      $response = new JsonResponse([
        'ok' => TRUE,
        'governance_enabled' => TRUE,
        'component' => $component,
        'html' => $html,
      ]);
      $response->headers->set('Cache-Control', 'private, no-store');
      return $response;
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio governance component refresh failed component=@component nid=@nid uid=@uid: @message', [
        '@component' => $component,
        '@nid' => (string) $node->id(),
        '@uid' => (string) $account->id(),
        '@message' => $e->getMessage(),
      ]);
      $response = new JsonResponse([
        'ok' => FALSE,
        'message' => 'Governance refresh failed. Try again shortly.',
      ], 500);
      $response->headers->set('Cache-Control', 'private, no-store');
      return $response;
    }
  }

}
