<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Mobile-first projection slices for operational checkout contracts.
 */
final class OperationalCartProjectionBuilder {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $checkout_contract
   *
   * @return array<string, mixed>
   */
  public function buildMobileSections(array $checkout_contract): array {
    $sections = [];
    $checkout_groups = is_array($checkout_contract['checkout_groups'] ?? NULL) ? $checkout_contract['checkout_groups'] : [];
    foreach ($checkout_groups as $group) {
      if (!is_array($group)) {
        continue;
      }
      $lines = is_array($group['lines'] ?? NULL) ? $group['lines'] : [];
      $cards = [];
      foreach ($lines as $line) {
        if (!is_array($line)) {
          continue;
        }
        $pres = is_array($line['presentation'] ?? NULL) ? $line['presentation'] : [];
        $cards[] = [
          'title' => (string) ($pres['operational_summary'] ?? $this->t('Operational package')),
          'chips' => is_array($pres['operational_chips'] ?? NULL) ? $pres['operational_chips'] : [],
          'meta' => [
            'product_id' => (int) ($line['product_id'] ?? 0),
            'quantity' => (string) ($line['quantity'] ?? ''),
          ],
        ];
      }
      $sections[] = [
        'group_key' => (string) ($group['group_key'] ?? ''),
        'label' => (string) ($group['label'] ?? ''),
        'cards' => $cards,
      ];
    }
    return [
      'sections' => $sections,
      'banner_count' => count(is_array($checkout_contract['operational_banners'] ?? NULL) ? $checkout_contract['operational_banners'] : []),
    ];
  }

}
