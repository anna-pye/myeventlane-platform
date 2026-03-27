<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_help_assistant\Service\HelpContextResolver;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds a canonical, permission-safe support context payload for MEL surfaces.
 *
 * Composes HelpContextResolver when the help assistant module is enabled.
 * Does not query analytics, escalations, or finance systems — those slots are
 * reserved for future wiring when safe signals exist.
 */
final class SupportContextBuilder {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly AccountProxyInterface $currentUser,
    private readonly ?HelpContextResolver $helpContextResolver,
    private readonly SupportActionBuilder $supportActionBuilder,
  ) {}

  /**
   * @return array<string, mixed>
   *   Structured support context (no HTML). Safe subsets may be exposed to JS.
   */
  public function build(): array {
    $account = $this->currentUser->getAccount();
    $route_name = (string) ($this->routeMatch->getRouteName() ?? '');
    $request = $this->requestStack->getCurrentRequest();
    $path = $request ? '/' . ltrim($request->getPathInfo(), '/') : '';

    $audience = $this->resolveAudience($account);
    $surface = $this->resolveSurface($route_name);

    $node = $this->routeMatch->getParameter('node');
    $entity_context = [];
    if ($node instanceof NodeInterface) {
      $entity_context = [
        'bundle' => $node->bundle(),
        'id' => (int) $node->id(),
      ];
    }

    $workspace = $this->helpContextResolver !== NULL
      ? $this->helpContextResolver->resolve(NULL)
      : [];

    $event_context = isset($workspace['event']) && is_array($workspace['event'])
      ? $this->filterEventContext($workspace['event'])
      : NULL;

    $vendor_context = isset($workspace['vendor']) && is_array($workspace['vendor'])
      ? $workspace['vendor']
      : NULL;

    $assistant_context = $this->helpContextResolver !== NULL
      ? $this->helpContextResolver->exportClientContextForRequest()
      : [];

    $is_vendor_help_query = FALSE;
    if ($request !== NULL) {
      $ctx = $request->query->get('context');
      $is_vendor_help_query = is_string($ctx) && trim($ctx) === 'vendor';
    }

    $context = [
      'audience' => $audience,
      'route_name' => $route_name,
      'path' => $path,
      'current_surface' => $surface,
      'entity_context' => $entity_context,
      'event_context' => $event_context,
      'vendor_context' => $vendor_context,
      'escalation_context' => $this->buildEscalationPermissions($account),
      'analytics_context' => [],
      'help_context' => [
        'hub_route' => 'myeventlane_help_centre.home',
        'vendor_context_query' => $is_vendor_help_query,
      ],
      'suggested_topics' => [],
      'suggested_actions' => [],
      'escalation_hints' => $this->buildEscalationHints($account, $audience),
      'assistant_context' => $assistant_context,
    ];

    $context['suggested_actions'] = $this->supportActionBuilder->buildFromSupportContext($context, FALSE);

    return $context;
  }

  /**
   * @return array<string, mixed>
   *   Minimal context safe to attach to drupalSettings for anonymous/authenticated.
   */
  public function buildClientSafeSubset(array $full_context): array {
    return [
      'audience' => $full_context['audience'] ?? 'public',
      'current_surface' => $full_context['current_surface'] ?? 'unknown',
      'event_id' => isset($full_context['event_context']['id'])
        ? (int) $full_context['event_context']['id']
        : NULL,
    ];
  }

  /**
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Real account object.
   */
  private function resolveAudience($account): string {
    if ($account->hasPermission('administer escalations')) {
      return 'staff';
    }
    if ($account->hasPermission('access vendor console')
      || $account->hasPermission('view vendor help centre')) {
      return 'vendor';
    }
    return 'public';
  }

  private function resolveSurface(string $route_name): string {
    if ($route_name === 'myeventlane_support_console.dashboard') {
      return 'admin_console';
    }

    if (str_starts_with($route_name, 'myeventlane_help_centre')) {
      return 'public_help';
    }

    if (str_starts_with($route_name, 'myeventlane_escalations_portal.vendor_')) {
      return 'vendor_support';
    }

    if (str_starts_with($route_name, 'myeventlane_vendor.console')) {
      $event = $this->routeMatch->getParameter('event');
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        return 'vendor_event';
      }
      return 'vendor_dashboard';
    }

    if ($route_name === 'entity.node.canonical') {
      $node = $this->routeMatch->getParameter('node');
      if ($node instanceof NodeInterface && $node->bundle() === 'event') {
        return 'public_event';
      }
    }

    if (str_starts_with($route_name, 'entity.escalation.')
      || str_starts_with($route_name, 'myeventlane_escalations.')
      || str_contains($route_name, 'escalation')) {
      return 'admin_support';
    }

    return 'unknown';
  }

  /**
   * @param array<string, mixed> $event
   */
  private function filterEventContext(array $event): array {
    return [
      'id' => (int) ($event['id'] ?? 0),
      'title' => (string) ($event['title'] ?? ''),
      'status' => (string) ($event['status'] ?? ''),
      'event_type' => $event['event_type'] ?? NULL,
    ];
  }

  /**
   * @param \Drupal\Core\Session\AccountInterface $account
   *
   * @return array<string, bool>
   */
  private function buildEscalationPermissions($account): array {
    return [
      'may_create_customer_escalation' => $account->hasPermission('create escalation'),
      'may_view_own_escalations' => $account->hasPermission('view own escalation'),
      'may_view_vendor_escalations' => $account->hasPermission('view vendor escalations'),
    ];
  }

  /**
   * @param \Drupal\Core\Session\AccountInterface $account
   *
   * @return list<array<string, string>>
   */
  private function buildEscalationHints($account, string $audience): array {
    $hints = [];
    if ($account->hasPermission('view own escalation')) {
      $hints[] = [
        'id' => 'customer_support_home',
        'route_name' => 'myeventlane_escalations_portal.customer_support_tickets',
        'label' => 'support_tickets',
      ];
    }
    if ($audience === 'vendor' && $account->hasPermission('view vendor escalations')) {
      $hints[] = [
        'id' => 'vendor_support_home',
        'route_name' => 'myeventlane_escalations_portal.vendor_list',
        'label' => 'vendor_support',
      ];
    }
    return $hints;
  }

}
