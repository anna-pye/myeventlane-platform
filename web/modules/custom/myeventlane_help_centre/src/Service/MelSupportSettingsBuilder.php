<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds drupalSettings.melSupport and related escalation payloads for the UI layer.
 *
 * Single source for Help Centre hub, vendor shell, and admin shell attachments.
 */
final class MelSupportSettingsBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly SupportContextBuilder $supportContextBuilder,
    private readonly SupportActionBuilder $supportActionBuilder,
    private readonly RequestStack $requestStack,
    private readonly AccountProxyInterface $currentUser,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * @return array<string, mixed>
   *   Payload for drupalSettings.melSupport (no HTML).
   */
  public function buildDrupalSettings(string $variant, bool $evaluateDebugQuery): array {
    $request = $this->requestStack->getCurrentRequest();
    $supportContext = $this->supportContextBuilder->build();
    $debug = $evaluateDebugQuery
      && $request?->query->get('mel_support_debug') === '1'
      && $this->currentUser->hasPermission('view mel help insights');

    $supportActions = $supportContext['suggested_actions'] ?? [];
    if ($debug) {
      $supportActions = $this->supportActionBuilder->buildFromSupportContext($supportContext, TRUE);
    }

    $payload = [
      'variant' => $variant,
      'context' => $this->supportContextBuilder->buildClientSafeSubset($supportContext),
      'actions' => $this->supportActionBuilder->prepareForClientSettings($supportActions),
      'escalation' => $this->buildEscalationUrls(),
      'strings' => $this->buildUiStrings(),
      'panel_collapsed_default' => in_array($variant, ['compact', 'admin'], TRUE),
    ];

    if ($debug) {
      $payload['debug'] = [
        'route_name' => $supportContext['route_name'] ?? '',
        'current_surface' => $supportContext['current_surface'] ?? '',
        'action_ids' => array_values(array_filter(array_map(
          static fn (array $row): string => (string) ($row['id'] ?? ''),
          $supportActions,
        ))),
      ];
    }

    return $payload;
  }

  /**
   * @return array<string, string>
   *   Safe relative URLs for escalation UI (Twig + JS).
   */
  public function buildEscalationUrls(): array {
    $out = [
      'contact_url' => _myeventlane_help_centre_contact_support_url(),
      'assistant_url' => $this->resolveAssistantUrl(),
      'help_search_url' => $this->safeRouteUrl('myeventlane_help_centre.search'),
      'help_home_url' => $this->safeRouteUrl('myeventlane_help_centre.home'),
      'articles_jump_id' => 'mel-popular-articles-title',
    ];
    return $out;
  }

  private function resolveAssistantUrl(): string {
    if (!$this->moduleHandler->moduleExists('myeventlane_help_assistant')) {
      return '';
    }
    $settings = $this->configFactory->get('myeventlane_help_assistant.settings');
    if (!(bool) $settings->get('enabled')) {
      return '';
    }
    try {
      $url = Url::fromRoute('myeventlane_help_centre.home', [], ['fragment' => 'mel-help-assistant']);
      if (!$url->access($this->currentUser->getAccount())) {
        return '';
      }
      return $url->setAbsolute(FALSE)->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

  private function safeRouteUrl(string $route): string {
    try {
      $url = Url::fromRoute($route);
      if (!$url->access($this->currentUser->getAccount())) {
        return '';
      }
      return $url->setAbsolute(FALSE)->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * @return array<string, string>
   */
  private function buildUiStrings(): array {
    return [
      'actions_heading' => (string) $this->t('Suggested next steps'),
      'intro_hub' => (string) $this->t('Use these shortcuts to find answers faster or reach our team.'),
      'intro_compact' => (string) $this->t('Quick links to help and support.'),
      'articles_jump' => (string) $this->t('Browse featured articles below'),
      'contact_support' => (string) $this->t('Contact support'),
      'open_assistant' => (string) $this->t('Open Help Assistant'),
      'help_centre' => (string) $this->t('Help Centre'),
      'search_help' => (string) $this->t('Search help'),
      'debug_heading' => (string) $this->t('Support context (debug)'),
      'panel_toggle_show' => (string) $this->t('Show support shortcuts'),
      'panel_toggle_hide' => (string) $this->t('Hide support shortcuts'),
    ];
  }

}
