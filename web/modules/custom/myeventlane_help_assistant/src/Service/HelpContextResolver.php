<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves Help Assistant workspace context from the route and verified client hints.
 *
 * POST /help/assistant uses route myeventlane_help_assistant.ask, which carries no
 * event parameters. The block attaches a pageContext payload; the client echoes it
 * back and this service reloads the event and enforces organiser access.
 */
final class HelpContextResolver {

  /**
   * Surfaces accepted from the client on the ask route (allowlist).
   *
   * @var list<string>
   */
  private const CLIENT_SURFACES = [
    'unknown',
    'event_wizard',
    'vendor_dashboard',
    'event_workspace',
    'checkout',
    'help',
  ];

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Builds context for the current request.
   *
   * @param array<string, mixed>|null $client_context
   *   Optional JSON `context` from the assistant client (echo of pageContext).
   *
   * @return array<string, mixed>
   *   Serializable context (safe for drupalSettings and JSON).
   */
  public function resolve(?array $client_context = NULL): array {
    $route_name = (string) ($this->routeMatch->getRouteName() ?? '');
    $is_ask = $route_name === 'myeventlane_help_assistant.ask';

    $base = [
      'user_id' => (int) $this->currentUser->id(),
      'roles' => $this->currentUser->getRoles(),
      'vendor' => $this->vendorFromRoute(),
      'event' => NULL,
      'surface' => 'unknown',
      'context_version' => 1,
    ];

    if ($is_ask) {
      $hints = is_array($client_context) ? $client_context : [];
      $base['surface'] = $this->normaliseClientSurface($this->stringFromMixed($hints['surface'] ?? NULL));
      $base['event'] = $this->eventFromClientPayload($hints['event'] ?? NULL);
      if ($base['event'] === NULL) {
        $hint_id = (int) ($hints['event_id'] ?? 0);
        if ($hint_id > 0) {
          $base['event'] = $this->eventFromClientPayload(['id' => $hint_id]);
        }
      }
      if ($base['event'] !== NULL && $base['vendor'] === NULL) {
        $base['vendor'] = $this->vendorFromEventId((int) $base['event']['id']);
      }
      return $base;
    }

    $event = $this->getEventNodeFromRoute();
    if ($event instanceof NodeInterface && $this->userMayManageEvent($event)) {
      $base['event'] = $this->buildEventContext($event);
      $base['vendor'] = $base['vendor'] ?? $this->vendorFromEventNode($event);
    }
    $base['surface'] = $this->detectSurfaceFromRoute($route_name);
    return $base;
  }

  private function stringFromMixed(mixed $value): string {
    if ($value === NULL) {
      return '';
    }
    $s = trim((string) $value);
    return preg_replace('/\s+/', ' ', strip_tags($s)) ?? '';
  }

  private function normaliseClientSurface(string $surface): string {
    $surface = mb_strtolower(trim($surface));
    if ($surface === '' || $surface === 'vendor_console') {
      return $surface === 'vendor_console' ? 'vendor_dashboard' : 'unknown';
    }
    return in_array($surface, self::CLIENT_SURFACES, TRUE) ? $surface : 'unknown';
  }

  /**
   * @param array<string, mixed>|null $payload
   */
  private function eventFromClientPayload(mixed $payload): ?array {
    if (!is_array($payload)) {
      return NULL;
    }
    $id = (int) ($payload['id'] ?? 0);
    if ($id < 1) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $node = $storage->load($id);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return NULL;
    }
    if (!$this->userMayManageEvent($node)) {
      $this->logger->notice('Help Assistant rejected context event @nid for uid @uid.', [
        '@nid' => $id,
        '@uid' => (string) $this->currentUser->id(),
      ]);
      return NULL;
    }
    return $this->buildEventContext($node);
  }

  private function getEventNodeFromRoute(): ?NodeInterface {
    foreach (['node', 'event'] as $key) {
      $param = $this->routeMatch->getParameter($key);
      if ($param instanceof NodeInterface && $param->bundle() === 'event') {
        return $param;
      }
    }
    return NULL;
  }

  private function vendorFromRoute(): ?array {
    $vendor = $this->routeMatch->getParameter('myeventlane_vendor');
    if (!$vendor instanceof Vendor) {
      return NULL;
    }
    if (!$vendor->access('view', $this->currentUser->getAccount())) {
      return NULL;
    }
    return [
      'id' => (int) $vendor->id(),
      'label' => $vendor->label(),
    ];
  }

  private function vendorFromEventNode(NodeInterface $event): ?array {
    return $this->vendorFromEventId((int) $event->id());
  }

  private function vendorFromEventId(int $event_id): ?array {
    if ($event_id < 1) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $node = $storage->load($event_id);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return NULL;
    }
    if (!$node->hasField('field_event_vendor') || $node->get('field_event_vendor')->isEmpty()) {
      return NULL;
    }
    $vendor = $node->get('field_event_vendor')->entity;
    if (!$vendor instanceof Vendor) {
      return NULL;
    }
    return [
      'id' => (int) $vendor->id(),
      'label' => $vendor->label(),
    ];
  }

  private function userMayManageEvent(NodeInterface $event): bool {
    $account = $this->currentUser->getAccount();
    if ($account->hasPermission('administer nodes')) {
      return TRUE;
    }
    if ((int) $event->getOwnerId() === (int) $account->id() && $account->hasPermission('edit own event content')) {
      return TRUE;
    }
    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor instanceof Vendor && $vendor->hasField('field_vendor_users')) {
        foreach ($vendor->get('field_vendor_users')->getValue() as $item) {
          if (isset($item['target_id']) && (int) $item['target_id'] === (int) $account->id()) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildEventContext(NodeInterface $node): array {
    $event_type = NULL;
    if ($node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()) {
      $event_type = (string) $node->get('field_event_type')->value;
    }
    return [
      'id' => (int) $node->id(),
      'title' => $node->label(),
      'status' => $node->isPublished() ? 'published' : 'draft',
      'event_type' => $event_type,
    ];
  }

  private function detectSurfaceFromRoute(string $route_name): string {
    if ($route_name !== '' && (
      str_contains($route_name, 'commerce_checkout')
      || str_contains($route_name, 'checkout')
    )) {
      return 'checkout';
    }
    if ($route_name !== '' && (str_starts_with($route_name, 'myeventlane_event.wizard') || str_starts_with($route_name, 'myeventlane_event_studio.'))) {
      return 'event_wizard';
    }
    if ($route_name !== '' && str_contains($route_name, 'myeventlane_boost.vendor_boost')) {
      return 'event_wizard';
    }
    if ($route_name !== '' && str_starts_with($route_name, 'myeventlane_vendor.console')) {
      if ($this->getEventNodeFromRoute() instanceof NodeInterface) {
        return 'event_workspace';
      }
      return 'vendor_dashboard';
    }
    if ($route_name !== '' && str_contains($route_name, 'myeventlane_help_centre')) {
      return 'help';
    }
    return 'unknown';
  }

  /**
   * Workspace hints for the help hub (optional ?event=, server-validated).
   *
   * @param int|null $event_query_param
   *   Raw event node ID from the query string, or NULL.
   *
   * @return array<string, mixed>
   *   Full resolver context with surface forced to "help" for grounded assistant calls.
   */
  public function resolveSupportHubContext(?int $event_query_param = NULL): array {
    $base = $this->resolve(NULL);
    $base['surface'] = 'help';
    if ($event_query_param !== NULL && $event_query_param > 0) {
      $event = $this->eventFromClientPayload(['id' => $event_query_param]);
      if (is_array($event)) {
        $base['event'] = $event;
        if (($base['vendor'] ?? NULL) === NULL) {
          $base['vendor'] = $this->vendorFromEventId($event_query_param);
        }
      }
    }
    return $base;
  }

  /**
   * Client echo payload for the current HTTP request (GET), including ?event=.
   *
   * @return array<string, mixed>
   */
  public function exportClientContextForRequest(): array {
    $request = $this->requestStack->getCurrentRequest();
    $event_id = NULL;
    if ($request !== NULL) {
      $param = $request->query->get('event');
      if (is_numeric($param)) {
        $parsed = (int) $param;
        $event_id = $parsed > 0 ? $parsed : NULL;
      }
    }
    $full = $event_id !== NULL
      ? $this->resolveSupportHubContext($event_id)
      : $this->resolve(NULL);
    return $this->exportClientEchoPayload($full);
  }

  /**
   * Minimal payload echoed by the browser on POST (no user ids or roles).
   *
   * @param array<string, mixed> $full
   *   Output of resolve() or resolveSupportHubContext().
   *
   * @return array<string, mixed>
   */
  public function exportClientEchoPayload(array $full): array {
    $out = [
      'surface' => (string) ($full['surface'] ?? 'unknown'),
      'context_version' => (int) ($full['context_version'] ?? 1),
    ];
    if (!empty($full['event']) && is_array($full['event'])) {
      $eid = (int) ($full['event']['id'] ?? 0);
      if ($eid > 0) {
        $out['event'] = ['id' => $eid];
      }
    }
    return $out;
  }

}
