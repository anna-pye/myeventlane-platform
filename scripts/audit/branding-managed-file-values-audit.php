<?php

/**
 * @file
 * Audit: trace mel managed-file values reaching ManagedFile / FileWidget.
 *
 * Usage: ddev drush php:script scripts/audit/branding-managed-file-values-audit.php
 */

declare(strict_types=1);

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Element\ManagedFile;
use Drupal\file\Plugin\Field\FieldWidget\FileWidget;
use Drupal\myeventlane_event_studio\Form\EventBrandingForm;
use Drupal\myeventlane_event_studio\Service\EventStudioMelPayloadService;
use Drupal\myeventlane_event_studio\Service\EventStudioWizardMelBaseline;
use Drupal\node\NodeInterface;

/** @var \Drupal\Core\Entity\EntityTypeManagerInterface $etm */
$etm = \Drupal::entityTypeManager();
/** @var \Drupal\Core\Form\FormBuilderInterface $form_builder */
$form_builder = \Drupal::formBuilder();

$nid = (int) ($extra[0] ?? 1381);
$node = $etm->getStorage('node')->load($nid);
if (!$node instanceof NodeInterface) {
  fwrite(STDERR, "Node $nid not found\n");
  exit(1);
}

/** @var EventStudioWizardMelBaseline $baseline_service */
$baseline_service = \Drupal::service('myeventlane_event_studio.wizard_mel_baseline');
$baseline = $baseline_service->getBaselineMel($node);

echo "=== BASELINE mel (wizard merge source) ===\n";
audit_print_path('mel', ['field_event_image' => $baseline['field_event_image'] ?? NULL]);
audit_print_path('mel', ['field_mel_event_gallery' => $baseline['field_mel_event_gallery'] ?? NULL]);

echo "\n=== ManagedFile::valueCallback fids type matrix ===\n";
$element = [
  '#upload_validators' => [],
  '#upload_location' => 'public://',
  '#multiple' => FALSE,
  '#extended' => FALSE,
];
$form_state = new FormState();
foreach ([
  'string fid' => ['fids' => '454'],
  'empty string' => ['fids' => ''],
  'space-separated' => ['fids' => '454 455'],
  'array of ints (baseline legacy)' => ['fids' => [454]],
  'array of strings' => ['fids' => ['454']],
  'nested widget delta only' => ['fids' => ['454'], 'display' => 1],
] as $label => $input) {
  $result = audit_managed_file_value_callback($element, $input, $form_state);
  echo sprintf("  %-32s input=%s => %s\n", $label, audit_type($input['fids']), $result);
}

echo "\n=== FileWidget::massageFormValues fids type matrix ===\n";
$widget = new class extends FileWidget {
  public function __construct() {
    // Skip parent — only calling massageFormValues statically.
  }
};
$ref = new ReflectionMethod(FileWidget::class, 'massageFormValues');
$ref->setAccessible(TRUE);
foreach ([
  'array fids (normal post-valueCallback)' => [['fids' => [454], 'display' => 1]],
  'string fids (raw hidden input shape)' => [['fids' => '454', 'display' => 1]],
  'int fids' => [['fids' => 454, 'display' => 1]],
  'missing fids key' => [['display' => 1]],
] as $label => $values) {
  $out = audit_massage($ref, $values);
  echo sprintf("  %-40s => %s\n", $label, $out);
}

echo "\n=== Form scenarios (node $nid) ===\n";
$scenarios = [
  'empty_hero' => [],
  'uploaded_hero_string_fids' => [
    'field_event_image' => [
      0 => [
        'fids' => '454',
        'display' => 1,
        'image_crop' => [
          'crop_wrapper' => [
            'event_hero' => [
              'crop_container' => [
                'values' => [
                  'crop_applied' => '0',
                  'x' => '100',
                  'y' => '50',
                  'width' => '1200',
                  'height' => '630',
                ],
              ],
            ],
          ],
        ],
      ],
    ],
  ],
  'cropped_hero' => [
    'field_event_image' => [
      0 => [
        'fids' => '454',
        'display' => 1,
        'image_crop' => [
          'crop_wrapper' => [
            'event_hero' => [
              'crop_container' => [
                'values' => [
                  'crop_applied' => '1',
                  'x' => '100',
                  'y' => '50',
                  'width' => '1200',
                  'height' => '630',
                ],
              ],
            ],
          ],
        ],
      ],
    ],
  ],
  'baseline_legacy_array_fids' => [
    'field_event_image' => [454],
  ],
  'array_fids_in_delta' => [
    'field_event_image' => [
      0 => ['fids' => [454], 'display' => 1],
    ],
  ],
  'gallery_selection' => [
    'field_mel_event_gallery' => [
      'selection' => [
        0 => ['target_id' => '10'],
      ],
    ],
  ],
];

foreach ($scenarios as $name => $mel_input) {
  echo "\n--- Scenario: $name ---\n";
  audit_scenario($node, $mel_input, $baseline);
}

echo "\n=== mergeMel(baseline, scenario) field_event_image ===\n";
$merge_ref = new ReflectionMethod(\Drupal\myeventlane_event_studio\Form\EventStudioBaseForm::class, 'mergeMel');
// Use anonymous subclass to call protected mergeMel.
$merger = new class extends \Drupal\myeventlane_event_studio\Form\EventStudioBaseForm {
  public function __construct() {}
  public function exposeMergeMel(array $base, array $override): array {
    return $this->mergeMel($base, $override);
  }
};
foreach (['uploaded_hero_string_fids', 'baseline_legacy_array_fids', 'array_fids_in_delta'] as $key) {
  $merged = $merger->exposeMergeMel($baseline, $scenarios[$key]);
  audit_print_path('merged', ['field_event_image' => $merged['field_event_image'] ?? NULL]);
  $hero = EventStudioMelPayloadService::normalizeHeroFromMelFragment($merged);
  echo "  normalizeHero fid={$hero['fid']}\n";
}

echo "\n=== OFFENDING FIELD SUMMARY ===\n";
echo "ManagedFile explode TypeError: mel[field_event_image][…][fids] as PHP array (e.g. [454]) instead of string \"454\".\n";
echo "FileWidget foreach TypeError: mel[field_event_image][0][fids] as string \"454\" passed to massageFormValues (expects array after valueCallback).\n";
echo "Primary legacy source: EventStudioMelDefaultsProvider baseline field_event_image => [fid, …] from managed_file #default_value.\n";

/**
 * @param mixed $value
 */
function audit_type(mixed $value): string {
  if (is_array($value)) {
    return 'array(' . json_encode($value) . ')';
  }
  return gettype($value) . '(' . json_encode($value) . ')';
}

/**
 * @param array<string, mixed> $input
 */
function audit_managed_file_value_callback(array $element, array $input, FormStateInterface $form_state): string {
  try {
    $out = ManagedFile::valueCallback($element, $input, $form_state);
    if (!is_array($out)) {
      return 'non-array return';
    }
    return 'OK fids=' . audit_type($out['fids'] ?? NULL);
  }
  catch (\Throwable $e) {
    return 'FAIL ' . get_class($e) . ': ' . $e->getMessage();
  }
}

/**
 * @param ReflectionMethod $ref
 * @param array<int, array<string, mixed>> $values
 */
function audit_massage(ReflectionMethod $ref, array $values): string {
  try {
    $out = $ref->invoke(new FileWidget([], '', '', [], []), $values, [], new FormState());
    return 'OK ' . json_encode($out);
  }
  catch (\Throwable $e) {
    return 'FAIL ' . get_class($e) . ': ' . $e->getMessage();
  }
}

/**
 * @param array<string, mixed> $mel_input
 * @param array<string, mixed> $baseline
 */
function audit_scenario(NodeInterface $node, array $mel_input, array $baseline): void {
  $form_object = EventBrandingForm::create(\Drupal::getContainer());
  $form_state = new FormState();
  $form_state->addBuildInfo('args', [$node]);
  $form_state->setValues([
    'nid' => (int) $node->id(),
    'mel' => $mel_input,
  ]);
  $form_state->setUserInput([
    'nid' => (string) $node->id(),
    'mel' => audit_mel_for_user_input($mel_input),
  ]);

  $form = $form_builder->buildForm($form_object, $form_state);
  $mel_values = $form_state->getValue('mel');
  if (!is_array($mel_values)) {
    $mel_values = [];
  }

  echo "form_state->getValue(mel) after buildForm:\n";
  audit_print_mel_fids($mel_values);

  // Locate managed_file fids element in built form.
  $fids_element = audit_find_fids_element($form['mel'] ?? []);
  if ($fids_element !== NULL) {
    echo "built form fids element #value: " . audit_type($fids_element['#value']['fids'] ?? $fids_element['#value'] ?? NULL) . "\n";
  }

  // Simulate pre-extract merge as EventBrandingForm::persistWizardMel.
  $merger = new class extends \Drupal\myeventlane_event_studio\Form\EventStudioBaseForm {
    public function __construct() {}
    public function exposeMergeMel(array $base, array $override): array {
      return $this->mergeMel($base, $override);
    }
  };
  $merged = $merger->exposeMergeMel($baseline, $mel_values);
  echo "mergeMel(baseline, form_state mel) field_event_image:\n";
  audit_print_path('  ', ['field_event_image' => $merged['field_event_image'] ?? NULL]);

  // Test extract path if hero widget present.
  if (isset($form['mel']['field_event_image'])) {
    audit_test_extract($node, $form['mel'], $form_state, $mel_values);
  }
}

/**
 * @param array<string, mixed> $mel
 *
 * @return array<string, mixed>
 */
function audit_mel_for_user_input(array $mel): array {
  // User input mirrors POST: fids stay strings when submitted from browser.
  return $mel;
}

/**
 * @param array<string, mixed> $mel
 */
function audit_print_mel_fids(array $mel): void {
  audit_print_path('mel', ['field_event_image' => $mel['field_event_image'] ?? NULL]);
  if (isset($mel['field_mel_event_gallery'])) {
    audit_print_path('mel', ['field_mel_event_gallery' => $mel['field_mel_event_gallery']]);
  }
}

/**
 * @param array<string, mixed> $root
 */
function audit_print_path(string $prefix, array $root): void {
  foreach ($root as $key => $value) {
    $path = $prefix . "[$key]";
    if ($key === 'fids' || $key === 'field_event_image' || $key === 'field_mel_event_gallery' || is_int($key)) {
      echo "  $path => " . audit_type($value) . "\n";
      if (is_array($value)) {
        foreach ($value as $k => $v) {
          if ($k === 'fids' || $k === 'selection' || is_int($k)) {
            echo "    $path[$k] => " . audit_type($v) . "\n";
          }
        }
      }
    }
  }
}

/**
 * @param array<string, mixed> $mel_branch
 *
 * @return array<string, mixed>|null
 */
function audit_find_fids_element(array $mel_branch): ?array {
  if (isset($mel_branch['widget'][0]['fids']) && is_array($mel_branch['widget'][0]['fids'])) {
    return $mel_branch['widget'][0]['fids'];
  }
  return NULL;
}

/**
 * @param array<string, mixed> $mel_structure
 * @param array<string, mixed> $mel_values
 */
function audit_test_extract(NodeInterface $node, array $mel_structure, FormStateInterface $form_state, array $mel_values): void {
  $display = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load('node.event.studio_branding');
  if ($display === NULL) {
    return;
  }
  $widget = $display->getRenderer('field_event_image');
  if ($widget === NULL) {
    return;
  }
  $clone = clone $node;
  $items = $clone->get('field_event_image');
  try {
    $widget->extractFormValues($items, $mel_structure, $form_state);
    $val = $items->getValue();
    echo "extractFormValues OK: " . json_encode($val) . "\n";
  }
  catch (\Throwable $e) {
    echo "extractFormValues FAIL: " . get_class($e) . ': ' . $e->getMessage() . "\n";
  }

  // massageFormValues on raw mel delta (what breaks FileWidget).
  $delta = EventStudioMelPayloadService::imageWidgetDeltaFromRaw($mel_values['field_event_image'] ?? []);
  if (isset($delta['fids'])) {
    echo "mel delta fids before massage: " . audit_type($delta['fids']) . "\n";
    $ref = new ReflectionMethod(FileWidget::class, 'massageFormValues');
    $ref->setAccessible(TRUE);
    echo "massageFormValues on mel delta: " . audit_massage($ref, [$delta]) . "\n";
  }
}
