<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Loads YAML-backed help seeds and support panel definitions from config.
 */
final class HelpContentRepository {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array<string, array<string, mixed>>
   *   Keyed help article seed definitions (see myeventlane_help_centre.help_content).
   */
  public function getHelpArticleSeeds(): array {
    $raw = $this->configFactory->get('myeventlane_help_centre.help_content')->get('help_articles');
    return is_array($raw) ? $raw : [];
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  public function getSupportPanels(): array {
    $raw = $this->configFactory->get('myeventlane_help_centre.help_content')->get('support_panels');
    return is_array($raw) ? $raw : [];
  }

  /**
   * @return array<string, mixed>|null
   */
  public function getSupportPanel(string $key): ?array {
    $panels = $this->getSupportPanels();
    $item = $panels[$key] ?? NULL;
    return is_array($item) ? $item : NULL;
  }

}
