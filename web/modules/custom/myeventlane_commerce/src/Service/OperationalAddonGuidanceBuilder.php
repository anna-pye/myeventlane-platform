<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Read-only customer guidance derived from purchase composition (and optional checkout contract).
 *
 * Does not query Commerce entities or re-classify order lines; consumes documents
 * produced by {@see OperationalPurchaseCompositionManager} and optionally
 * {@see OperationalCheckoutOrchestrationManager}.
 */
final class OperationalAddonGuidanceBuilder {

  use StringTranslationTrait;

  public const CONTRACT_FLAG = 'mel_operational_addon_guidance';

  /**
   * @var list<string>
   */
  public const FORBIDDEN_GUIDANCE_KEYS = [
    'qr_payload',
    'replay_token',
    'scanner_action',
    'scanner_secret',
    'device_fingerprint',
    'payload_sha256',
    'inventory_quantity',
    'stock_count',
    'warehouse_ids',
    'shipment_provider',
    'internal_cost',
    'vendor_margin',
    'private_notes',
  ];

  /**
   * @var list<string>
   */
  private const GROUP_KEYS = [
    'merchandise',
    'hospitality',
    'timed_collection',
    'bundles',
    'parking',
  ];

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $composition
   *   Document from {@see OperationalPurchaseCompositionManager::composeForOrder()}.
   * @param array<string, mixed>|null $checkout_document
   *   Optional checkout contract from orchestration (same shape as #checkout).
   *
   * @return array<string, mixed>
   *   Sanitised guidance document, or [] when there are no operational add-ons.
   */
  public function buildFromComposition(array $composition, ?array $checkout_document = NULL): array {
    $groups = is_array($composition['groups'] ?? NULL) ? $composition['groups'] : [];
    $has_operational = FALSE;
    foreach (self::GROUP_KEYS as $key) {
      if (isset($groups[$key]) && is_array($groups[$key]) && $groups[$key] !== []) {
        $has_operational = TRUE;
        break;
      }
    }
    if (!$has_operational) {
      return [];
    }

    $checkout_extra = $this->extractCheckoutGuidanceBodies($checkout_document);

    $sections = [];
    foreach (self::GROUP_KEYS as $group_key) {
      $lines = is_array($groups[$group_key] ?? NULL) ? $groups[$group_key] : [];
      if ($lines === []) {
        continue;
      }
      $sections[] = $this->buildSection($group_key, $lines, $checkout_extra);
    }

    $out = [
      self::CONTRACT_FLAG => TRUE,
      'schema_version' => 1,
      'page_title' => (string) $this->t('Your extras'),
      'page_intro' => (string) $this->t('Extras linked to this booking are shown below. Collect at the event when the organiser is ready.'),
      'sections' => $sections,
      'footer_note' => (string) $this->t('Show your order confirmation if staff ask. Hospitality access stays linked to this booking — follow organiser instructions on the day.'),
      'support_hint' => (string) $this->t('Shipping and QR wallet collection are not shown here yet; use your confirmation email and organiser updates for the latest details.'),
    ];
    return $this->stripForbiddenRecursive($out);
  }

  /**
   * @param array<string, mixed> $composition
   * @param array<string, mixed>|null $checkout_document
   *
   * @return array<string, mixed>|null
   */
  public function buildRenderArray(OrderInterface $order, array $composition, ?array $checkout_document = NULL): ?array {
    $guidance = $this->buildFromComposition($composition, $checkout_document);
    if ($guidance === []) {
      return NULL;
    }
    return [
      '#theme' => 'mel_operational_addon_guidance',
      '#guidance' => $guidance,
      '#attached' => [
        'library' => ['myeventlane_commerce/operational_addon_guidance'],
      ],
      '#cache' => [
        'tags' => $order->getCacheTags(),
        'contexts' => ['languages:language_interface', 'user'],
      ],
    ];
  }

  /**
   * @param list<string> $checkout_bodies
   * @param list<array<string, mixed>> $lines
   *
   * @return array<string, mixed>
   */
  private function buildSection(string $group_key, array $lines, array $checkout_bodies): array {
    $summaries = [];
    $chips_out = [];
    $seen_chip = [];
    $readiness_modes = [];
    $visibility = '';

    foreach ($lines as $line) {
      if (!is_array($line)) {
        continue;
      }
      $pres = is_array($line['presentation'] ?? NULL) ? $line['presentation'] : [];
      $pres = $this->stripForbiddenRecursive($pres);
      $summary = trim((string) ($pres['operational_summary'] ?? ''));
      if ($summary !== '') {
        $summaries[] = $summary;
      }
      $raw_chips = is_array($pres['operational_chips'] ?? NULL) ? $pres['operational_chips'] : [];
      foreach ($raw_chips as $chip) {
        if (!is_array($chip)) {
          continue;
        }
        $label = trim((string) ($chip['label'] ?? ''));
        if ($label === '' || isset($seen_chip[$label])) {
          continue;
        }
        $seen_chip[$label] = TRUE;
        $tone = strtolower(trim((string) ($chip['tone'] ?? 'muted')));
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $tone)) {
          $tone = 'muted';
        }
        $chips_out[] = [
          'label' => $label,
          'tone' => $tone,
        ];
      }
      $rm = strtolower(trim((string) ($pres['readiness_mode'] ?? '')));
      if ($rm !== '') {
        $readiness_modes[$rm] = TRUE;
      }
      if ($visibility === '' && isset($pres['customer_visibility'])) {
        $visibility = (string) $pres['customer_visibility'];
      }
    }

    $title = match ($group_key) {
      'merchandise' => (string) $this->t('Merch & pickup'),
      'hospitality' => (string) $this->t('Hospitality'),
      'timed_collection' => (string) $this->t('Timed collection'),
      'bundles' => (string) $this->t('Packages'),
      'parking' => (string) $this->t('Parking & access'),
      default => (string) $this->t('Add-ons'),
    };

    $next_step = $this->nextStepForGroup($group_key, $checkout_bodies);
    $collection_hint = (string) $this->t('Collect at the event when the organiser opens pickup or check-in.');

    $readiness = '';
    if (count($readiness_modes) === 1) {
      $readiness = array_key_first($readiness_modes);
    }
    elseif ($readiness_modes !== []) {
      $readiness = 'mixed';
    }

    return [
      'group_key' => $group_key,
      'title' => $title,
      'summary_lines' => $summaries,
      'chips' => $chips_out,
      'next_step' => $next_step,
      'collection_hint' => $collection_hint,
      'visibility' => $visibility,
      'readiness' => $readiness,
    ];
  }

  /**
   * @return list<string>
   */
  private function extractCheckoutGuidanceBodies(?array $checkout_document): array {
    if ($checkout_document === NULL) {
      return [];
    }
    $rows = is_array($checkout_document['customer_guidance'] ?? NULL) ? $checkout_document['customer_guidance'] : [];
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $body = trim((string) ($row['body'] ?? ''));
      if ($body !== '') {
        $out[] = $body;
      }
    }
    return $out;
  }

  /**
   * @param list<string> $checkout_bodies
   */
  private function nextStepForGroup(string $group_key, array $checkout_bodies): string {
    $base = match ($group_key) {
      'hospitality' => (string) $this->t('Hospitality access is linked to this booking — bring your confirmation and follow organiser instructions.'),
      'timed_collection' => (string) $this->t('Timed collection windows are for planning; final times are confirmed on site.'),
      'parking' => (string) $this->t('Parking add-ons follow venue access rules — arrive with this booking visible on your phone or email.'),
      'bundles' => (string) $this->t('Package items are fulfilled together at the event when the organiser is ready.'),
      default => (string) $this->t('Bring this booking confirmation — staff may ask before handing over items.'),
    };
    $extra = $this->pickCheckoutBodyForGroup($group_key, $checkout_bodies);
    if ($extra === '') {
      return $base;
    }
    return $base . ' ' . $extra;
  }

  /**
   * @param list<string> $bodies
   */
  private function pickCheckoutBodyForGroup(string $group_key, array $bodies): string {
    $want = match ($group_key) {
      'hospitality' => 'hospitality',
      'timed_collection' => 'timed_collection',
      'merchandise', 'bundles', 'parking' => 'pickup',
      default => '',
    };
    if ($want === '' || $bodies === []) {
      return '';
    }
    foreach ($bodies as $body) {
      $lower = strtolower($body);
      if ($want === 'hospitality' && str_contains($lower, 'hospitality')) {
        return $body;
      }
      if ($want === 'timed_collection' && str_contains($lower, 'timed')) {
        return $body;
      }
      if ($want === 'pickup' && str_contains($lower, 'pickup')) {
        return $body;
      }
    }
    return '';
  }

  /**
   * @param array<int|string, mixed> $data
   *
   * @return array<int|string, mixed>
   */
  public function stripForbiddenRecursive(array $data): array {
    if (array_is_list($data)) {
      $out = [];
      foreach ($data as $item) {
        if (is_array($item)) {
          $out[] = $this->stripForbiddenRecursive($item);
        }
        elseif (is_scalar($item) || $item === NULL) {
          $out[] = $item;
        }
      }
      return $out;
    }
    $out = [];
    foreach ($data as $key => $value) {
      if (!is_string($key) || in_array($key, self::FORBIDDEN_GUIDANCE_KEYS, TRUE)) {
        continue;
      }
      if (is_array($value)) {
        $out[$key] = $this->stripForbiddenRecursive($value);
      }
      elseif (is_scalar($value) || $value === NULL) {
        $out[$key] = $value;
      }
    }
    return $out;
  }

}
