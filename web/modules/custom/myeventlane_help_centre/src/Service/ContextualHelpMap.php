<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Loads editorial contextual help links (path + label) from configuration.
 */
final class ContextualHelpMap {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array<string, array{text: string, path: string}>
   *   Map of context keys to link data.
   */
  public function getMap(): array {
    $raw = $this->configFactory->get('myeventlane_help_centre.contextual_help')->get('links');
    if (!is_array($raw)) {
      return [];
    }
    $out = [];
    foreach ($raw as $key => $item) {
      if (!is_string($key) || !is_array($item)) {
        continue;
      }
      $text = trim((string) ($item['text'] ?? ''));
      $path = trim((string) ($item['path'] ?? ''));
      if ($text === '' || $path === '') {
        continue;
      }
      if (!str_starts_with($path, '/') && !str_starts_with($path, 'http://') && !str_starts_with($path, 'https://')) {
        continue;
      }
      $out[$key] = [
        'text' => $text,
        'path' => $path,
      ];
    }
    return $out;
  }

  /**
   * @return array{text: string, path: string}|null
   */
  public function getLink(string $key): ?array {
    $map = $this->getMap();
    return $map[$key] ?? NULL;
  }

}
