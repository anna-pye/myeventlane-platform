<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Builds normalised, access-checked support actions for MEL surfaces.
 *
 * Returns structured arrays (no HTML). URLs are resolved with Url::fromRoute()
 * and skipped when the current account cannot access the route.
 */
final class SupportActionBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Actions for the Help Centre hub and related public entry points.
   *
   * @param array<string, mixed> $support_context
   *   Output of SupportContextBuilder::build().
   * @param bool $include_debug_metadata
   *   When TRUE, each action may include a "reason" key (staff diagnostics only).
   *
   * @return list<array<string, mixed>>
   */
  public function buildFromSupportContext(array $support_context, bool $include_debug_metadata = FALSE): array {
    $account = $this->currentUser->getAccount();
    $audience = (string) ($support_context['audience'] ?? 'public');
    $actions = [];

    $this->tryAddRoute($actions, 'help_home', 'myeventlane_help_centre.home', [], $account, [
      'label' => (string) $this->t('Help home'),
      'audience' => 'public',
      'style' => 'link',
      'weight' => 0,
    ], $include_debug_metadata);

    $this->tryAddRoute($actions, 'help_search', 'myeventlane_help_centre.search', [], $account, [
      'label' => (string) $this->t('Search help'),
      'audience' => 'public',
      'style' => 'link',
      'weight' => 1,
    ], $include_debug_metadata);

    if ($this->moduleHandler->moduleExists('myeventlane_help_assistant')) {
      $cfg = $this->configFactory->get('myeventlane_help_assistant.settings');
      if ((bool) $cfg->get('enabled')) {
        $this->tryAddFragmentRoute($actions, 'help_assistant', 'myeventlane_help_centre.home', [], 'mel-help-assistant', $account, [
          'label' => (string) $this->t('Help Assistant'),
          'audience' => 'public',
          'style' => 'primary',
          'weight' => 2,
        ], $include_debug_metadata);
      }
    }

    $this->tryAddRoute($actions, 'policies', 'myeventlane_help_centre.policies_index', [], $account, [
      'label' => (string) $this->t('Policies and trust'),
      'audience' => 'public',
      'style' => 'link',
      'weight' => 5,
    ], $include_debug_metadata);

    if ($account->hasPermission('view own escalation')) {
      $this->tryAddRoute($actions, 'support_tickets', 'myeventlane_escalations_portal.customer_support_tickets', [], $account, [
        'label' => (string) $this->t('Support tickets'),
        'audience' => 'public',
        'style' => 'secondary',
        'weight' => 8,
      ], $include_debug_metadata);
    }

    if ($audience === 'vendor' || $audience === 'staff') {
      $this->tryAddRoute($actions, 'vendor_console', 'myeventlane_vendor.console.dashboard', [], $account, [
        'label' => (string) $this->t('Vendor dashboard'),
        'audience' => 'vendor',
        'style' => 'link',
        'weight' => 15,
      ], $include_debug_metadata);

      $this->tryAddRoute($actions, 'vendor_support', 'myeventlane_escalations_portal.vendor_list', [], $account, [
        'label' => (string) $this->t('Vendor support'),
        'audience' => 'vendor',
        'style' => 'secondary',
        'weight' => 18,
      ], $include_debug_metadata);

      $this->tryAddRoute($actions, 'vendor_payouts', 'myeventlane_vendor.console.payouts', [], $account, [
        'label' => (string) $this->t('Payouts'),
        'audience' => 'vendor',
        'style' => 'link',
        'weight' => 20,
      ], $include_debug_metadata);
    }

    if ($audience === 'staff') {
      $this->tryAddRoute($actions, 'support_console', 'myeventlane_support_console.dashboard', [], $account, [
        'label' => (string) $this->t('Support Console'),
        'audience' => 'staff',
        'style' => 'primary',
        'weight' => 30,
      ], $include_debug_metadata);

      $this->tryAddRoute($actions, 'escalation_queue', 'entity.escalation.collection', [], $account, [
        'label' => (string) $this->t('Escalations queue'),
        'audience' => 'staff',
        'style' => 'link',
        'weight' => 31,
      ], $include_debug_metadata);

      if ($this->moduleHandler->moduleExists('myeventlane_staff_playbooks')) {
        $this->tryAddRoute($actions, 'governance', 'myeventlane_staff_playbooks.governance_dashboard', [], $account, [
          'label' => (string) $this->t('Governance'),
          'audience' => 'staff',
          'style' => 'link',
          'weight' => 32,
        ], $include_debug_metadata);
      }

      $this->tryAddRoute($actions, 'help_insights', 'myeventlane_help_centre.help_insights_dashboard', [], $account, [
        'label' => (string) $this->t('Help insights'),
        'audience' => 'staff',
        'style' => 'link',
        'weight' => 33,
      ], $include_debug_metadata);

      if ($this->moduleHandler->moduleExists('myeventlane_escalations_capacity')) {
        $this->tryAddRoute($actions, 'capacity', 'myeventlane_escalations_capacity.dashboard', [], $account, [
          'label' => (string) $this->t('Escalation capacity'),
          'audience' => 'staff',
          'style' => 'link',
          'weight' => 34,
        ], $include_debug_metadata);
      }

      if ($this->moduleHandler->moduleExists('myeventlane_escalations_policy')) {
        $this->tryAddRoute($actions, 'escalation_policy', 'myeventlane_escalations_policy.report', [], $account, [
          'label' => (string) $this->t('Escalation policy report'),
          'audience' => 'staff',
          'style' => 'link',
          'weight' => 35,
        ], $include_debug_metadata);
      }
    }

    $ev = $support_context['event_context'] ?? NULL;
    if (is_array($ev) && ($audience === 'vendor' || $audience === 'staff')) {
      $eid = (int) ($ev['id'] ?? 0);
      if ($eid > 0) {
        $node = $this->loadEventNode($eid);
        if ($node instanceof NodeInterface) {
          $this->tryAddRoute($actions, 'hub_event_workspace', 'myeventlane_vendor.console.event_workspace', ['event' => $node], $account, [
            'label' => (string) $this->t('Open event workspace'),
            'audience' => 'vendor',
            'style' => 'primary',
            'weight' => 12,
          ], $include_debug_metadata);

          $this->tryAddRoute($actions, 'hub_event_tickets', 'myeventlane_vendor.console.event_tickets', ['event' => $node], $account, [
            'label' => (string) $this->t('Manage tickets'),
            'audience' => 'vendor',
            'style' => 'link',
            'weight' => 13,
          ], $include_debug_metadata);

          if ($this->moduleHandler->moduleExists('myeventlane_boost')) {
            $this->tryAddRoute($actions, 'hub_event_boost', 'myeventlane_boost.vendor_boost_wizard', ['event' => $node], $account, [
              'label' => (string) $this->t('Boost wizard'),
              'audience' => 'vendor',
              'style' => 'link',
              'weight' => 14,
            ], $include_debug_metadata);
          }
        }
      }
    }

    usort($actions, static fn (array $a, array $b): int => ((int) ($a['weight'] ?? 0)) <=> ((int) ($b['weight'] ?? 0)));

    return $actions;
  }

  /**
   * Normalised actions derived from HelpContextResolver workspace context.
   *
   * Used by the Help Assistant response merge path (label/url compatible).
   *
   * @param array<string, mixed> $workspace_context
   *   HelpContextResolver::resolve() output.
   *
   * @return list<array<string, mixed>>
   */
  public function buildNormalizedForWorkspaceContext(array $workspace_context): array {
    $account = $this->currentUser->getAccount();
    $surface = (string) ($workspace_context['surface'] ?? 'unknown');
    $event = $workspace_context['event'] ?? NULL;
    $actions = [];

    $vendorish = in_array($surface, ['vendor_dashboard', 'event_workspace', 'event_wizard'], TRUE);

    if (is_array($event)) {
      $eid = (int) ($event['id'] ?? 0);
      $status = (string) ($event['status'] ?? '');
      if ($eid > 0) {
        $node = $this->loadEventNode($eid);
        if ($node instanceof NodeInterface && $node->access('view', $account)) {
          if ($status === 'draft') {
            $this->tryAddRoute($actions, 'ws_event_finish', 'myeventlane_vendor.console.event_editor', ['event' => $node], $account, [
              'label' => (string) $this->t('Finish your event'),
              'audience' => 'vendor',
              'style' => 'primary',
              'weight' => 10,
            ], FALSE);
          }
          if ($status === 'published' && $this->moduleHandler->moduleExists('myeventlane_boost')) {
            if ($vendorish) {
              $this->tryAddRoute($actions, 'ws_event_boost', 'myeventlane_boost.vendor_boost_wizard', ['event' => $node], $account, [
                'label' => (string) $this->t('Boost your event'),
                'audience' => 'vendor',
                'style' => 'secondary',
                'weight' => 20,
              ], FALSE);
            }
            else {
              $this->tryAddRoute($actions, 'ws_event_boost_public', 'myeventlane_boost.boost_page', ['node' => $node], $account, [
                'label' => (string) $this->t('Boost your event'),
                'audience' => 'public',
                'style' => 'secondary',
                'weight' => 20,
              ], FALSE);
            }
          }
          $this->tryAddRoute($actions, 'ws_event_workspace', 'myeventlane_vendor.console.event_workspace', ['event' => $node], $account, [
            'label' => (string) $this->t('Open event workspace'),
            'audience' => 'vendor',
            'style' => 'link',
            'weight' => 30,
          ], FALSE);
        }
      }
    }

    if ($surface === 'vendor_dashboard' && $actions === []) {
      $this->tryAddRoute($actions, 'ws_vendor_events', 'myeventlane_vendor.console.events', [], $account, [
        'label' => (string) $this->t('View your events'),
        'audience' => 'vendor',
        'style' => 'link',
        'weight' => 5,
      ], FALSE);
    }

    usort($actions, static fn (array $a, array $b): int => ((int) ($a['weight'] ?? 0)) <=> ((int) ($b['weight'] ?? 0)));

    return $actions;
  }

  /**
   * @param list<array<string, mixed>> $normalized
   *
   * @return array<int, array<string, string>>
   */
  public function toAssistantLabelUrlRows(array $normalized): array {
    $out = [];
    foreach ($normalized as $row) {
      $label = trim((string) ($row['label'] ?? ''));
      $url = trim((string) ($row['url'] ?? ''));
      if ($label !== '' && $url !== '') {
        $out[] = ['label' => $label, 'url' => $url];
      }
    }
    return $out;
  }

  /**
   * Serialises route parameters for JSON-safe drupalSettings (entity → id).
   *
   * @param array<string, mixed> $parameters
   *
   * @return array<string, scalar|list<int|string>>
   */
  public function serialiseRouteParameters(array $parameters): array {
    $out = [];
    foreach ($parameters as $key => $value) {
      if ($value instanceof NodeInterface) {
        $out[$key] = (int) $value->id();
        continue;
      }
      if (is_scalar($value)) {
        $out[$key] = $value;
      }
    }
    return $out;
  }

  /**
   * @param list<array<string, mixed>> $normalized
   *
   * @return list<array<string, mixed>>
   */
  public function prepareForClientSettings(array $normalized): array {
    $copy = [];
    foreach ($normalized as $row) {
      $id = (string) ($row['id'] ?? '');
      if ($id === '') {
        continue;
      }
      $entry = [
        'id' => $id,
        'label' => (string) ($row['label'] ?? ''),
        'url' => (string) ($row['url'] ?? ''),
        'style' => (string) ($row['style'] ?? 'link'),
        'weight' => (int) ($row['weight'] ?? 0),
      ];
      $rn = $row['route_name'] ?? NULL;
      if (is_string($rn) && $rn !== '') {
        $entry['route_name'] = $rn;
      }
      $rp = $row['route_parameters'] ?? [];
      if (is_array($rp)) {
        $entry['route_parameters'] = $this->serialiseRouteParameters($rp);
      }
      $copy[] = $entry;
    }
    return $copy;
  }

  private function loadEventNode(int $nid): ?NodeInterface {
    if ($nid < 1) {
      return NULL;
    }
    try {
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      return $node instanceof NodeInterface && $node->bundle() === 'event' ? $node : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * @param list<array<string, mixed>> $actions
   * @param array<string, mixed> $parameters
   *   Route parameters (may include entity objects).
   */
  private function tryAddRoute(array &$actions, string $id, string $route_name, array $parameters, AccountInterface $account, array $meta, bool $debug): void {
    foreach ($actions as $existing) {
      if (($existing['id'] ?? '') === $id) {
        return;
      }
    }
    try {
      $url = Url::fromRoute($route_name, $parameters);
      if (!$url->access($account)) {
        return;
      }
      $row = [
        'id' => $id,
        'route_name' => $route_name,
        'route_parameters' => $parameters,
        'query' => [],
        'url' => $url->setAbsolute(FALSE)->toString(),
        'label' => (string) ($meta['label'] ?? ''),
        'style' => (string) ($meta['style'] ?? 'link'),
        'audience' => (string) ($meta['audience'] ?? 'public'),
        'permission' => isset($meta['permission']) ? (string) $meta['permission'] : NULL,
        'icon' => isset($meta['icon']) ? (string) $meta['icon'] : NULL,
        'weight' => (int) ($meta['weight'] ?? 0),
      ];
      if ($debug) {
        $row['reason'] = 'access_ok';
      }
      $actions[] = $row;
    }
    catch (\Throwable) {
    }
  }

  /**
   * @param list<array<string, mixed>> $actions
   * @param array<string, mixed> $route_parameters
   */
  private function tryAddFragmentRoute(array &$actions, string $id, string $route_name, array $route_parameters, string $fragment, AccountInterface $account, array $meta, bool $debug): void {
    foreach ($actions as $existing) {
      if (($existing['id'] ?? '') === $id) {
        return;
      }
    }
    try {
      $url = Url::fromRoute($route_name, $route_parameters, ['fragment' => $fragment]);
      if (!$url->access($account)) {
        return;
      }
      $row = [
        'id' => $id,
        'route_name' => $route_name,
        'route_parameters' => $route_parameters,
        'query' => [],
        'url' => $url->setAbsolute(FALSE)->toString(),
        'label' => (string) ($meta['label'] ?? ''),
        'style' => (string) ($meta['style'] ?? 'link'),
        'audience' => (string) ($meta['audience'] ?? 'public'),
        'permission' => NULL,
        'icon' => NULL,
        'weight' => (int) ($meta['weight'] ?? 0),
      ];
      if ($debug) {
        $row['reason'] = 'access_ok';
      }
      $actions[] = $row;
    }
    catch (\Throwable) {
    }
  }

}
