<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

use Drupal\Core\Render\BubbleableMetadata;

/**
 * Surface-aware data presentation governance (presentation only — no queries).
 */
final class MelDataPresentationManager {

  public function __construct(
    private readonly SurfaceResolver $surfaceResolver,
    private readonly MelDataPresentationRegistry $dataPresentationRegistry,
    private readonly MelComponentManager $componentManager,
    private readonly MelDataTableHelper $dataTableHelper,
    private readonly MelMetricHelper $metricHelper,
    private readonly MelAnalyticsHelper $analyticsHelper,
  ) {}

  /**
   * Page-level context for Twig shells and builders.
   *
   * @return array<string, mixed>
   */
  public function getPageContext(): array {
    $surface = $this->surfaceResolver->resolve();
    return [
      'surface' => $surface->value,
      'layout_profile' => $this->getLayoutProfile($surface),
      'density' => $this->componentManager->getDensity($surface),
      'table_modifiers' => $this->dataTableHelper->getTableModifierClasses($surface),
      'metric_card_modifier' => $this->metricHelper->getMetricCardModifier($surface),
      'analytics_frame_modifiers' => $this->analyticsHelper->getFrameClasses($surface),
    ];
  }

  /**
   * Operational layout profile per product shell.
   */
  public function getLayoutProfile(MelSurfaceId $surface): string {
    return match ($surface) {
      MelSurfaceId::Customer => 'customer_simplified',
      MelSurfaceId::Vendor => 'vendor_operational',
      MelSurfaceId::Staff => 'staff_utilitarian',
      MelSurfaceId::Public => 'public_minimal',
      MelSurfaceId::Auth => 'auth_compact',
    };
  }

  public function getDefinition(string $machineName): ?DataPresentationDefinition {
    return $this->dataPresentationRegistry->get($machineName);
  }

  /**
   * @return array<string, DataPresentationDefinition>
   */
  public function definitions(): array {
    return $this->dataPresentationRegistry->all();
  }

  /**
   * Wraps already-rendered table body (e.g. Views) in governed presentation shell.
   *
   * @param array<string, mixed> $table_body
   *   Render array for table markup only.
   * @param array<string, mixed> $options
   *   Optional keys: toolbar, status, actions, empty, pagination, contract,
   *   responsive_stack, scroll_region_label.
   *
   * @return array<string, mixed>
   */
  public function buildDataTableShell(array $table_body, array $options = []): array {
    $build = [
      '#theme' => 'mel_data_table',
      'content' => $table_body,
      'toolbar' => $options['toolbar'] ?? NULL,
      'status' => $options['status'] ?? NULL,
      'actions' => $options['actions'] ?? NULL,
      'empty' => $options['empty'] ?? NULL,
      'pagination' => $options['pagination'] ?? NULL,
      'contract' => $options['contract'] ?? NULL,
      'responsive_stack' => (bool) ($options['responsive_stack'] ?? FALSE),
      'scroll_region_label' => (string) ($options['scroll_region_label'] ?? ''),
      'caption_id' => (string) ($options['caption_id'] ?? ''),
      '#cache' => [
        'contexts' => ['route', 'user.permissions'],
      ],
    ];
    $meta = BubbleableMetadata::createFromRenderArray($build);
    if (!empty($table_body['#cache'])) {
      $nested = ['#cache' => $table_body['#cache']];
      $meta->merge(BubbleableMetadata::createFromRenderArray($nested));
    }
    $meta->applyTo($build);
    return $build;
  }

  /**
   * Merges cache metadata required for surface-sensitive presentation.
   *
   * @param array<string, mixed> $build
   */
  public function applyCacheMetadata(array &$build): void {
    $meta = BubbleableMetadata::createFromRenderArray($build);
    $meta->addCacheContexts(['route', 'user.permissions']);
    $meta->applyTo($build);
  }

  /**
   * Decorates vendor KPI strip rows with data-presentation and metric shell metadata.
   *
   * @param list<array<string, mixed>> $kpis
   *
   * @return list<array<string, mixed>>
   */
  public function decorateVendorDashboardMetricStrip(array $kpis): array {
    static $contractByKey = [
      'revenue' => MelDataPresentationRegistry::METRIC_SALES,
      'tickets_sold' => MelDataPresentationRegistry::METRIC_SALES,
      'rsvps' => MelDataPresentationRegistry::METRIC_RSVP,
      'upcoming_events' => MelDataPresentationRegistry::METRIC_DASHBOARD_SUMMARY,
    ];
    $out = [];
    foreach ($kpis as $kpi) {
      $key = (string) ($kpi['key'] ?? '');
      $contract = $contractByKey[$key] ?? MelDataPresentationRegistry::METRIC_DASHBOARD_SUMMARY;
      $kpi['mel_data_presentation_contract'] = $contract;
      $kpi['mel_component_contract'] = 'mel_card_section';
      $definition = $this->dataPresentationRegistry->get($contract);
      if ($definition instanceof DataPresentationDefinition) {
        $kpi['mel_data_presentation_description'] = $definition->description;
      }
      $kpi['metric_presentation'] = $this->metricHelper->normalizeMetricPresentation([
        'label' => (string) ($kpi['label'] ?? ''),
        'value' => (string) ($kpi['value'] ?? ''),
        'status_variant' => (string) ($kpi['severity'] ?? ''),
      ]);
      $out[] = $kpi;
    }
    return $out;
  }

}
