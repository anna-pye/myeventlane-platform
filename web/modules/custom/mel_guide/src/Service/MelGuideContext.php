<?php

declare(strict_types=1);

namespace Drupal\mel_guide\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
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
    private readonly StateInterface $state,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly AccountProxyInterface $currentUser,
    private readonly ?object $journeyLinks = NULL,
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

    $image = $this->resolveImage($state);
    if ($image === NULL) {
      return NULL;
    }

    return [
      'state' => $state,
      'booking_help' => $this->isBookingHelp(),
      'help_topics' => $this->resolveBookingHelpTopics(),
      'message' => $this->isBookingHelp() ? (string) new \Drupal\Core\StringTranslation\TranslatableMarkup('Need help with this booking? Choose a topic to find your next step.') : $message,
      'image_url' => $image['url'],
      'cache_tags' => $image['cache_tags'],
      'image_alt' => $this->resolveImageAlt($state),
      'actions' => $this->resolveActions(),
      'appearance_delay' => (int) $config->get('appearance_delay'),
      'max_messages_per_session' => (int) $config->get('max_messages_per_session'),
      'hide_days_after_dismiss' => (int) $config->get('hide_days_after_dismiss'),
      'debug_force_display' => (bool) $config->get('debug_force_display'),
      'position' => $this->resolvePosition(),
    ];
  }

  /**
   * Resolves booking help topics when the optional Help Centre service exists.
   *
   * @return array<string, mixed>
   *   Help topic definitions keyed by topic machine name.
   */
  private function resolveBookingHelpTopics(): array {
    if (!$this->isBookingHelp()
      || $this->journeyLinks === NULL
      || !method_exists($this->journeyLinks, 'topics')) {
      return [];
    }

    $topics = $this->journeyLinks->topics();
    if (!is_array($topics)) {
      return [];
    }

    return array_intersect_key($topics, array_flip([
      'tickets',
      'guests',
      'refunds',
      'contact',
    ]));
  }

  /**
   * The pilot uses only the route name, never private booking data.
   */
  private function isBookingHelp(): bool {
    return $this->routeMatch->getRouteName() === 'myeventlane_checkout_flow.order_detail';
  }

  /**
   * Resolves safe, route-backed guide actions for the current visitor.
   *
   * @return array<string, string>
   *   Action URLs keyed by action name.
   */
  private function resolveActions(): array {
    $actions = [];

    try {
      $events_url = Url::fromRoute('view.upcoming_events.page_events');
      if ($events_url->access($this->currentUser)) {
        $actions['events'] = $events_url->toString();
      }
    }
    catch (\Throwable) {
      // Fail closed when the configured discovery route is unavailable.
    }

    if (!$this->moduleHandler->moduleExists('myeventlane_help_centre')
      || !$this->moduleHandler->moduleExists('myeventlane_help_assistant')
      || !(bool) $this->configFactory->get('myeventlane_help_assistant.settings')->get('enabled')) {
      return $actions;
    }

    try {
      $help_url = Url::fromRoute('myeventlane_help_centre.home', [], [
        'fragment' => 'mel-help-assistant',
      ]);
      if ($help_url->access($this->currentUser)) {
        $actions['help'] = $help_url->toString();
      }
    }
    catch (\Throwable) {
      // Fail closed rather than render an inaccessible Help Assistant link.
    }

    return $actions;
  }

  /**
   * Resolves the public image URL and cache tags for a character state.
   *
   * @return array{url: string, cache_tags: string[]}|null
   *   The resolved image data, or NULL when no source is available.
   */
  private function resolveImage(string $state): ?array {
    if (!in_array($state, self::ASSET_KEYS, TRUE)) {
      return NULL;
    }

    $config = $this->configFactory->get('mel_guide.settings');
    $media_uuids = $this->state->get(MelGuideAssetMediaManager::STATE_KEY, []);
    $media_uuid = is_array($media_uuids) ? trim((string) ($media_uuids[$state] ?? '')) : '';
    if ($media_uuid !== '') {
      $media = $this->entityRepository->loadEntityByUuid('media', $media_uuid);
      if ($media instanceof MediaInterface && $media->bundle() === 'image') {
        $source_field = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
        $file = $source_field !== '' && $media->hasField($source_field)
          ? $media->get($source_field)->entity
          : NULL;
        if ($file instanceof FileInterface && $this->fileExists($file)) {
          return [
            'url' => $this->fileUrlGenerator->generateString($file->getFileUri()),
            'cache_tags' => array_merge($media->getCacheTags(), $file->getCacheTags()),
          ];
        }
      }
    }

    $fids = $config->get('asset_fids') ?? [];
    if (!is_array($fids)) {
      $fids = [];
    }
    $fid = (int) ($fids[$state] ?? 0);

    if ($fid > 0) {
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if ($file instanceof FileInterface && $this->fileExists($file)) {
        return [
          'url' => $this->fileUrlGenerator->generateString($file->getFileUri()),
          'cache_tags' => $file->getCacheTags(),
        ];
      }
    }

    $assets = $config->get('assets') ?? [];
    if (!is_array($assets)) {
      $assets = [];
    }
    $asset_path = $assets[$state] ?? '';
    if ($asset_path === '') {
      return NULL;
    }

    $request = $this->requestStack->getCurrentRequest();
    $base_path = $request?->getBasePath() ?? '';
    return [
      'url' => $base_path . '/' . ltrim($asset_path, '/'),
      'cache_tags' => [],
    ];
  }

  /**
   * Confirms a managed file still exists in storage.
   */
  private function fileExists(FileInterface $file): bool {
    $realpath = $this->fileSystem->realpath($file->getFileUri());
    return $realpath !== FALSE && is_file($realpath);
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
