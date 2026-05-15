<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Staff workspace and customer strips for fulfillment execution orchestration.
 */
final class OperationalFulfillmentExecutionProjectionBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly ?OperationalIntegrityInspector $operationalIntegrityInspector,
    private readonly OperationalFulfillmentExecutionManager $operationalFulfillmentExecutionManager,
    private readonly OperationalFulfillmentExecutionGovernanceManager $operationalFulfillmentExecutionGovernanceManager,
    private readonly TimeInterface $time,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  public function buildWorkspaceExecutionGovernanceSections(array $merged, int $requestTime): array {
    $bundle = $this->operationalFulfillmentExecutionManager->composeExecutionBundleFromMerged($merged, $requestTime);
    $gov = $this->operationalFulfillmentExecutionGovernanceManager->projectGovernance($bundle);
    $rollup = is_array($bundle['rollup'] ?? NULL) ? $bundle['rollup'] : [];
    $tone = ($gov['severity'] ?? 'nominal') === 'elevated' ? 'elevated' : (($gov['severity'] ?? '') === 'watch' ? 'elevated' : 'low');

    $execution_cards = [];
    foreach (is_array($bundle['executions'] ?? NULL) ? $bundle['executions'] : [] as $row) {
      if (!is_array($row)) {
        continue;
      }
      $execution_cards[] = [
        'label' => (string) ($row['execution_type'] ?? '') . ' / ' . (string) ($row['fulfillment_type'] ?? ''),
        'value' => (string) ($row['operational_summary'] ?? ''),
      ];
    }
    if ($execution_cards === []) {
      $execution_cards[] = [
        'label' => 'Execution coverage',
        'value' => 'no_sampled_execution_rows',
      ];
    }

    $collection_cards = [];
    foreach (is_array($bundle['executions'] ?? NULL) ? $bundle['executions'] : [] as $row) {
      if (!is_array($row)) {
        continue;
      }
      $collection_cards[] = [
        'label' => 'Collection — ' . (string) ($row['execution_type'] ?? ''),
        'value' => (string) ($row['collection_state'] ?? ''),
      ];
    }
    if ($collection_cards === []) {
      $collection_cards[] = [
        'label' => 'Collection visibility',
        'value' => 'no_rows',
      ];
    }

    $readiness_cards = [
      [
        'label' => 'Readiness descriptor (rollup)',
        'value' => (string) ($rollup['readiness_descriptor'] ?? ''),
      ],
      [
        'label' => 'Completion descriptor (rollup)',
        'value' => (string) ($rollup['completion_descriptor'] ?? ''),
      ],
    ];
    foreach (is_array($bundle['executions'] ?? NULL) ? $bundle['executions'] : [] as $row) {
      if (!is_array($row)) {
        continue;
      }
      $readiness_cards[] = [
        'label' => 'Execution readiness — ' . (string) ($row['execution_type'] ?? ''),
        'value' => (string) ($row['execution_readiness'] ?? ''),
      ];
    }

    return [
      [
        'id' => 'execution_governance_summary',
        'title' => 'Execution governance summary',
        'section_tone' => $tone,
        'cards' => [
          [
            'label' => 'Governance severity',
            'value' => (string) ($gov['severity_label'] ?? ''),
          ],
          [
            'label' => 'Stalled executions (early lifecycle)',
            'value' => (string) (int) ($gov['stalled_executions'] ?? 0),
          ],
          [
            'label' => 'Degraded visibility',
            'value' => !empty($gov['degraded_visibility']) ? 'yes' : 'no',
          ],
          [
            'label' => 'Continuity conflict signal',
            'value' => !empty($gov['continuity_conflict']) ? 'yes' : 'no',
          ],
          [
            'label' => 'Partial completion rows',
            'value' => (string) (int) ($gov['partial_completion_rows'] ?? 0),
          ],
        ],
      ],
      [
        'id' => 'fulfillment_execution_cards',
        'title' => 'Fulfillment execution cards',
        'section_tone' => $tone,
        'cards' => $execution_cards,
      ],
      [
        'id' => 'operational_collection_visibility',
        'title' => 'Operational collection visibility',
        'section_tone' => $tone,
        'cards' => $collection_cards,
      ],
      [
        'id' => 'execution_readiness_visibility',
        'title' => 'Execution readiness visibility',
        'section_tone' => $tone,
        'cards' => $readiness_cards,
      ],
    ];
  }

  /**
   * Customer-safe render array for post-purchase surfaces.
   *
   * @return array<string, mixed>|null
   */
  public function buildCustomerRenderArray(OrderInterface $order): ?array {
    if ($this->operationalIntegrityInspector === NULL) {
      return NULL;
    }
    $diag = $this->operationalIntegrityInspector->inspectOrder($order);
    $merged = OperationalFulfillmentExecutionMergedDiagnostics::toMergedInspectionShape($diag);
    $bundle = $this->operationalFulfillmentExecutionManager->composeExecutionBundleFromMerged(
      $merged,
      (int) $this->time->getRequestTime(),
    );
    if (!empty($bundle['empty'])) {
      return NULL;
    }
    $presentation = $this->buildCustomerPresentation($bundle);
    $tags = ['commerce_order:' . $order->id()];
    return [
      '#theme' => 'mel_operational_fulfillment_execution_customer',
      '#document' => $bundle,
      '#presentation' => $presentation,
      '#cache' => [
        'tags' => $tags,
        'contexts' => ['languages:language_interface'],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $bundle
   *
   * @return array<string, mixed>
   */
  private function buildCustomerPresentation(array $bundle): array {
    $chips = [];
    foreach (is_array($bundle['executions'] ?? NULL) ? $bundle['executions'] : [] as $row) {
      if (!is_array($row)) {
        continue;
      }
      foreach (is_array($row['execution_chips'] ?? NULL) ? $row['execution_chips'] : [] as $chip) {
        if (is_array($chip) && isset($chip['label'])) {
          $chips[] = $chip;
        }
      }
    }
    $chips = $this->dedupeChips($chips);

    $lines = [];
    foreach (is_array($bundle['executions'] ?? NULL) ? $bundle['executions'] : [] as $row) {
      if (!is_array($row)) {
        continue;
      }
      $lines[] = (string) ($row['operational_summary'] ?? '');
    }
    $lines = array_values(array_filter(array_map('trim', $lines)));

    return [
      'continuity_chips' => $chips,
      'what_next' => (string) $this->t('At the venue, follow organiser signage — execution hints update as your visit progresses.'),
      'summaries' => $lines,
    ];
  }

  /**
   * @param list<array{label: string, tone: string}> $chips
   *
   * @return list<array{label: string, tone: string}>
   */
  private function dedupeChips(array $chips): array {
    $seen = [];
    $out = [];
    foreach ($chips as $chip) {
      $label = trim((string) ($chip['label'] ?? ''));
      if ($label === '' || isset($seen[$label])) {
        continue;
      }
      $seen[$label] = TRUE;
      $out[] = [
        'label' => $label,
        'tone' => (string) ($chip['tone'] ?? 'muted'),
      ];
    }
    return $out;
  }

}
