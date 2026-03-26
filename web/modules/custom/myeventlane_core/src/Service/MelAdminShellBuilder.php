<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Psr\Log\LoggerInterface;

/**
 * Wraps MEL admin page content in the shared studio layout (sidebar + main + panel).
 *
 * Two modes:
 * - Studio: full layout including optional right panel (PCC overview / platform).
 * - Standard: same theme hook with an empty right column for other /admin/myeventlane pages.
 *
 * Sidebar uses the mel_admin_sidebar_nav block when the providing module is enabled.
 */
final class MelAdminShellBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly BlockManagerInterface $blockManager,
    private readonly LoggerInterface $logger,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * PCC-style shell with right-hand operational panel.
   *
   * @param array $main
   *   Existing page render array (unchanged business output).
   * @param array $right_panel
   *   Render array for the studio right column.
   * @param list<string> $extra_libraries
   *   Additional libraries to attach (e.g. platform_control_centre).
   */
  public function wrapStudio(array $main, string|\Stringable $title, string|\Stringable $subtitle, array $right_panel, array $extra_libraries = []): array {
    $libraries = array_values(array_unique(array_merge(['myeventlane_core/studio_layout'], $extra_libraries)));
    $shell = [
      '#theme' => 'myeventlane_studio_layout',
      '#title' => $title,
      '#subtitle' => $subtitle,
      '#left_nav' => $this->buildSidebarNav(),
      '#main' => $main,
      '#right_panel' => $right_panel,
      '#attached' => [
        'library' => $libraries,
      ],
    ];
    $bubble = BubbleableMetadata::createFromRenderArray($main);
    $bubble->merge(BubbleableMetadata::createFromRenderArray($right_panel));
    $bubble->applyTo($shell);
    return $shell;
  }

  /**
   * Standard shell: sidebar + main, no right panel content.
   */
  public function wrapStandard(array $main, string|\Stringable $title, string|\Stringable $subtitle = ''): array {
    return $this->wrapStudio($main, $title, $subtitle, []);
  }

  /**
   * Builds the MEL admin sidebar block render array, or a safe fallback.
   */
  private function buildSidebarNav(): array {
    if (!$this->blockManager->hasDefinition('mel_admin_sidebar_nav')) {
      return [];
    }
    try {
      return $this->blockManager->createInstance('mel_admin_sidebar_nav')->build();
    }
    catch (\Throwable $e) {
      $this->logger->error('MEL admin shell: sidebar navigation failed: @message', ['@message' => $e->getMessage()]);
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-admin-shell__fallback']],
        'message' => [
          '#markup' => '<p>' . $this->t('Sidebar navigation is temporarily unavailable.') . '</p>',
        ],
      ];
    }
  }

}
