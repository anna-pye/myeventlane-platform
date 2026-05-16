<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Deterministic customer guidance for operational checkout orchestration.
 */
final class OperationalCustomerGuidanceBuilder {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $checkout_contract
   *
   * @return list<array<string, string>>
   */
  public function buildCustomerGuidance(array $checkout_contract): array {
    $guidance = [];
    $gov = is_array($checkout_contract['governance'] ?? NULL) ? $checkout_contract['governance'] : [];
    if (($gov['severity'] ?? '') === 'elevated') {
      $guidance[] = [
        'type' => 'readiness',
        'body' => (string) $this->t('Some packages may need attention before the event. Review the summaries below and follow organiser instructions at the venue.'),
      ];
    }
    else {
      $guidance[] = [
        'type' => 'readiness',
        'body' => (string) $this->t('Operational packages are described for planning only. Final pickup and hospitality rules are confirmed by the organiser at the venue.'),
      ];
    }

    $pickup = is_array($checkout_contract['pickup_groups'] ?? NULL) ? $checkout_contract['pickup_groups'] : [];
    if ($pickup !== []) {
      $guidance[] = [
        'type' => 'pickup',
        'body' => (string) $this->t('Pickup items may require venue check-in first. Arrive with your booking confirmation ready.'),
      ];
    }

    $hospitality = is_array($checkout_contract['hospitality_groups'] ?? NULL) ? $checkout_contract['hospitality_groups'] : [];
    if ($hospitality !== []) {
      $guidance[] = [
        'type' => 'hospitality',
        'body' => (string) $this->t('Hospitality benefits activate according to organiser instructions—usually after admission.'),
      ];
    }

    $timed = is_array($checkout_contract['timed_collection_groups'] ?? NULL) ? $checkout_contract['timed_collection_groups'] : [];
    if ($timed !== []) {
      $guidance[] = [
        'type' => 'timed_collection',
        'body' => (string) $this->t('Timed collection windows are shown for planning. Follow on-site signage and staff directions for the final schedule.'),
      ];
    }

    $continuity = is_array($checkout_contract['continuity_groups'] ?? NULL) ? $checkout_contract['continuity_groups'] : [];
    if ($continuity !== []) {
      $guidance[] = [
        'type' => 'continuity',
        'body' => (string) $this->t('Keep this page handy: continuity reminders summarise how packages fit together for your visit.'),
      ];
    }

    return $guidance;
  }

  /**
   * @param array<string, mixed> $checkout_contract
   *
   * @return list<array<string, string>>
   */
  public function buildOperationalBanners(array $checkout_contract): array {
    $banners = [];
    $gov = is_array($checkout_contract['governance'] ?? NULL) ? $checkout_contract['governance'] : [];
    $timed = is_array($gov['timed_collection_conflicts'] ?? NULL) ? $gov['timed_collection_conflicts'] : [];
    if (((int) ($timed['conflict_row_count'] ?? 0)) > 0) {
      $banners[] = [
        'tone' => 'warning',
        'label' => (string) $this->t('Timed collection'),
        'body' => (string) $this->t('Multiple timed windows detected—confirm the latest organiser messaging before travel.'),
      ];
    }
    $hospitality = is_array($gov['hospitality_degradation'] ?? NULL) ? $gov['hospitality_degradation'] : [];
    if (((int) ($hospitality['hidden_row_count'] ?? 0)) > 0) {
      $banners[] = [
        'tone' => 'info',
        'label' => (string) $this->t('Hospitality'),
        'body' => (string) $this->t('Some hospitality rows are hidden from the public event page until after purchase.'),
      ];
    }
    $readiness = is_array($gov['incomplete_readiness'] ?? NULL) ? $gov['incomplete_readiness'] : [];
    if (((int) ($readiness['blocked_count'] ?? 0)) > 0) {
      $banners[] = [
        'tone' => 'warning',
        'label' => (string) $this->t('Readiness'),
        'body' => (string) $this->t('One or more operational packages show blocked readiness in authoring—expect on-site clarification.'),
      ];
    }
    return $banners;
  }

}
