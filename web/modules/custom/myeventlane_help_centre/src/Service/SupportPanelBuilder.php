<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Url;

/**
 * Builds a themed support panel render array from YAML config.
 */
final class SupportPanelBuilder {

  public function __construct(
    private readonly HelpContentRepository $helpContentRepository,
  ) {}

  /**
   * @return array<string, mixed>
   *   Render array, or empty if the key is missing or invalid.
   */
  public function build(string $key): array {
    $panel = $this->helpContentRepository->getSupportPanel($key);
    if ($panel === NULL) {
      return [];
    }
    $title = trim((string) ($panel['title'] ?? ''));
    $linkPath = trim((string) ($panel['link_path'] ?? ''));
    if ($title === '' || $linkPath === '') {
      return [];
    }

    try {
      $url = Url::fromUserInput($linkPath)->setAbsolute(FALSE)->toString();
    }
    catch (\Throwable) {
      return [];
    }

    return [
      '#theme' => 'mel_support_panel',
      '#title' => $title,
      '#summary' => trim((string) ($panel['summary'] ?? '')),
      '#url' => $url,
      '#cache' => [
        'tags' => ['config:myeventlane_help_centre.help_content'],
      ],
    ];
  }

}
