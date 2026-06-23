<?php

declare(strict_types=1);

namespace Drupal\mel_guide\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves guide character state, message, and asset for the current page.
 */
final class MelGuideContext {

  /**
   * Character asset keys (state machine names).
   *
   * @var list<string>
   */
  private const ASSET_KEYS = [
    'wave',
    'think',
    'celebrate',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly MelGuideVisibility $visibility,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Builds render variables for the mel_guide theme hook.
   *
   * @return array<string, mixed>|null
   *   Render variables, or NULL when no guide context applies.
   */
  public function buildVariables(): ?array {
    $config = $this->configFactory->get('mel_guide.settings');
    $state = $this->resolveState();
    $message_key = $this->resolveMessageKey($state);
    $messages = $config->get('messages') ?? [];
    $message = $messages[$message_key] ?? '';

    if ($message === '') {
      return NULL;
    }

    $image_url = $this->resolveImageUrl($state);
    if ($image_url === NULL) {
      return NULL;
    }

    return [
      'state' => $state,
      'message' => $message,
      'image_url' => $image_url,
      'image_alt' => $this->resolveImageAlt($state),
      'appearance_delay' => (int) $config->get('appearance_delay'),
      'max_messages_per_session' => (int) $config->get('max_messages_per_session'),
      'hide_days_after_dismiss' => (int) $config->get('hide_days_after_dismiss'),
      'debug_force_display' => (bool) $config->get('debug_force_display'),
      'position' => $this->resolvePosition(),
    ];
  }

  /**
   * Resolves the public image URL for a character state.
   */
  private function resolveImageUrl(string $state): ?string {
    if (!in_array($state, self::ASSET_KEYS, TRUE)) {
      return NULL;
    }

    $config = $this->configFactory->get('mel_guide.settings');
    $fids = $config->get('asset_fids') ?? [];
    $fid = (int) ($fids[$state] ?? 0);

    if ($fid > 0) {
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if ($file instanceof FileInterface) {
        return $this->fileUrlGenerator->generateString($file->getFileUri());
      }
    }

    $assets = $config->get('assets') ?? [];
    $asset_path = $assets[$state] ?? '';
    if ($asset_path === '') {
      return NULL;
    }

    $request = $this->requestStack->getCurrentRequest();
    $base_path = $request?->getBasePath() ?? '';
    return $base_path . '/' . ltrim($asset_path, '/');
  }

  /**
   * Resolves the character state for the current request.
   */
  private function resolveState(): string {
    $config = $this->configFactory->get('mel_guide.settings');
    if ($config->get('debug_force_display')) {
      $override = (string) ($config->get('debug_context') ?? '');
      $state = match ($override) {
        'homepage' => 'wave',
        'event' => 'think',
        'complete' => 'celebrate',
        default => NULL,
      };
      if ($state !== NULL) {
        return $state;
      }
    }

    return $this->resolveStateFromRoute();
  }

  /**
   * Resolves character state from the active route.
   */
  private function resolveStateFromRoute(): string {
    if ($this->visibility->isCheckoutCompleteStep()) {
      return 'celebrate';
    }

    $route_name = $this->routeMatch->getRouteName() ?? '';

    if ($this->isHomepage($route_name)) {
      return 'wave';
    }

    if ($this->isEventPage()) {
      return 'think';
    }

    return 'think';
  }

  /**
   * Maps character state to configured message key.
   */
  private function resolveMessageKey(string $state): string {
    $debug_event = FALSE;
    $config = $this->configFactory->get('mel_guide.settings');
    if ($config->get('debug_force_display') && ($config->get('debug_context') ?? '') === 'event') {
      $debug_event = TRUE;
    }

    return match ($state) {
      'wave' => 'welcome',
      'think' => ($this->isEventPage() || $debug_event) ? 'discover' : 'thinking',
      'celebrate' => 'celebrate',
      default => 'thinking',
    };
  }

  /**
   * Resolves the configured screen position.
   */
  private function resolvePosition(): string {
    $position = (string) ($this->configFactory->get('mel_guide.settings')->get('position') ?? 'bottom_right');
    return in_array($position, ['bottom_right', 'bottom_left'], TRUE) ? $position : 'bottom_right';
  }

  /**
   * Whether the current route is the public homepage.
   */
  private function isHomepage(string $route_name): bool {
    return in_array($route_name, [
      'system.front_page',
      'view.frontpage.page_1',
    ], TRUE);
  }

  /**
   * Whether the current route is a published event detail page.
   */
  private function isEventPage(): bool {
    if ($this->routeMatch->getRouteName() !== 'entity.node.canonical') {
      return FALSE;
    }

    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      return $node->bundle() === 'event' && $node->isPublished();
    }

    return FALSE;
  }

  /**
   * Accessible alt text for the active character state.
   */
  private function resolveImageAlt(string $state): string {
    return match ($state) {
      'wave' => 'MEL waving hello',
      'think' => 'MEL thinking',
      'celebrate' => 'MEL celebrating',
      default => 'MEL guide',
    };
  }

}
