<?php

/**
 * @file
 * Audit-only runtime trace for Event Studio branding save on node 1567.
 *
 * Usage: ddev drush php:script scripts/audit/branding-save-pipeline-trace-1567.php
 *
 * Does NOT persist changes — uses node clone for save-path tracing.
 */

declare(strict_types=1);

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\field\Plugin\Field\FieldWidget\WidgetBase;
use Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget;
use Drupal\myeventlane_event_studio\Form\EventBrandingForm;
use Drupal\myeventlane_event_studio\Service\EventPageStyleResolver;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\myeventlane_event_studio\Service\EventStudioWizardMelBaseline;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;

const AUDIT_NID = 1567;
const AUDIT_UID = 1;

/** @var \Drupal\Core\Entity\EntityTypeManagerInterface $etm */
$etm = \Drupal::entityTypeManager();
/** @var AccountSwitcherInterface $account_switcher */
$account_switcher = \Drupal::service('account_switcher');
/** @var \Drupal\Core\Form\FormBuilderInterface $form_builder */
$form_builder = \Drupal::formBuilder();

$node = $etm->getStorage('node')->load(AUDIT_NID);
if (!$node instanceof NodeInterface) {
  fwrite(STDERR, "Node " . AUDIT_NID . " not found.\n");
  exit(1);
}

$user = User::load(AUDIT_UID);
if ($user === NULL) {
  fwrite(STDERR, "User " . AUDIT_UID . " not found.\n");
  exit(1);
}

$account_switcher->switchTo($user);

echo "=== PHASE 1 — Event owner / account state ===\n";
echo json_encode(audit_phase1($node, $user), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

/** @var EventStudioWizardMelBaseline $baseline_service */
$baseline_service = \Drupal::service('myeventlane_event_studio.wizard_mel_baseline');
$baseline = $baseline_service->getBaselineMel($node);

echo "=== Baseline mel branding keys ===\n";
echo "field_mel_page_style: " . json_encode($baseline['field_mel_page_style'] ?? 'ABSENT') . "\n";
echo "field_mel_theme_colour: " . json_encode($baseline['field_mel_theme_colour'] ?? 'ABSENT') . "\n";
echo "field_mel_event_gallery: " . json_encode($baseline['field_mel_event_gallery'] ?? 'ABSENT') . "\n\n";

echo "=== PHASE 2/3 — Form build + save-path trace (clone, no persist) ===\n";
$scenarios = [
  'style_immersive_purple_gallery_14_only_values' => [
    'description' => 'Immersive/purple in form_state values; gallery selection [14] only (repro wid 937933 shape)',
    'mel_values' => [
      'field_mel_page_style' => EventPageStyleResolver::STYLE_IMMERSIVE,
      'field_mel_theme_colour' => EventPageStyleResolver::COLOUR_PURPLE,
      'field_mel_event_gallery' => [
        'selection' => [
          ['target_id' => '14', 'weight' => 0],
        ],
      ],
    ],
    'mel_user_input' => NULL,
  ],
  'style_immersive_purple_in_user_input_only' => [
    'description' => 'Style in user_input only (values omit style keys); gallery selection in user_input only',
    'mel_values' => [
      'field_mel_event_gallery' => [
        'selection' => [
          ['target_id' => '14', 'weight' => 0],
        ],
      ],
    ],
    'mel_user_input' => [
      'field_mel_page_style' => EventPageStyleResolver::STYLE_IMMERSIVE,
      'field_mel_theme_colour' => EventPageStyleResolver::COLOUR_PURPLE,
      'field_mel_event_gallery' => [
        'selection' => [
          ['target_id' => '17', 'weight' => 0],
        ],
      ],
    ],
  ],
  'gallery_stale_values_new_user_input' => [
    'description' => 'Stale values [14,15,16]; user_input selection [17] only (media library AJAX hypothesis)',
    'mel_values' => [
      'field_mel_page_style' => EventPageStyleResolver::STYLE_IMMERSIVE,
      'field_mel_theme_colour' => EventPageStyleResolver::COLOUR_PURPLE,
      'field_mel_event_gallery' => [
        'selection' => [
          ['target_id' => '14', 'weight' => 0],
          ['target_id' => '15', 'weight' => 1],
          ['target_id' => '16', 'weight' => 2],
        ],
      ],
    ],
    'mel_user_input' => [
      'field_mel_page_style' => EventPageStyleResolver::STYLE_IMMERSIVE,
      'field_mel_theme_colour' => EventPageStyleResolver::COLOUR_PURPLE,
      'field_mel_event_gallery' => [
        'selection' => [
          ['target_id' => '17', 'weight' => 0],
        ],
      ],
    ],
  ],
  'gallery_no_selection_key_in_values' => [
    'description' => 'Values missing selection key; user_input has new gallery (syncBrandingGallerySubmittedValues path)',
    'mel_values' => [
      'field_mel_page_style' => EventPageStyleResolver::STYLE_IMMERSIVE,
      'field_mel_theme_colour' => EventPageStyleResolver::COLOUR_PURPLE,
      'field_mel_event_gallery' => [
        'media_library_selection' => '17',
      ],
    ],
    'mel_user_input' => [
      'field_mel_page_style' => EventPageStyleResolver::STYLE_IMMERSIVE,
      'field_mel_theme_colour' => EventPageStyleResolver::COLOUR_PURPLE,
      'field_mel_event_gallery' => [
        'selection' => [
          ['target_id' => '17', 'weight' => 0],
        ],
      ],
    ],
  ],
];

foreach ($scenarios as $key => $scenario) {
  echo "--- Scenario: {$key} ---\n";
  echo $scenario['description'] . "\n";
  echo json_encode(audit_trace_save_path($node, $baseline, $scenario['mel_values'], $scenario['mel_user_input']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
}

echo "=== Watchdog gallery saves (node 1567, recent) ===\n";
$rows = \Drupal::database()->select('watchdog', 'w')
  ->fields('w', ['wid', 'message', 'variables', 'timestamp'])
  ->condition('message', 'Branding gallery save for node @nid:%', 'LIKE')
  ->orderBy('wid', 'DESC')
  ->range(0, 6)
  ->execute()
  ->fetchAll();
foreach ($rows as $row) {
  $vars = @unserialize($row->variables, ['allowed_classes' => FALSE]);
  if (is_array($vars) && ($vars['@nid'] ?? '') === (string) AUDIT_NID) {
    echo "wid={$row->wid} " . date('c', (int) $row->timestamp) . " before={$vars['@before']} submitted={$vars['@submitted']} final={$vars['@final']}\n";
  }
}

$account_switcher->switchBack();
echo "\nDone.\n";

/**
 * @return array<string, mixed>
 */
function audit_phase1(NodeInterface $node, User $user): array {
  $style_access = \Drupal::service('myeventlane_event_studio.event_style_access');
  $pro = \Drupal::service('myeventlane_pro.pro_access');
  $resolver = \Drupal::service('myeventlane_event_studio.event_page_style_resolver');

  $store_id = NULL;
  $store_label = NULL;
  if (\Drupal::moduleHandler()->moduleExists('commerce_store')) {
    $stores = \Drupal::entityTypeManager()->getStorage('commerce_store')->loadByProperties(['uid' => (int) $user->id()]);
    $store = $stores ? reset($stores) : NULL;
    if ($store !== NULL && $store instanceof \Drupal\Core\Entity\EntityInterface) {
      $store_id = (int) $store->id();
      $store_label = (string) $store->label();
    }
  }

  return [
    'event_nid' => (int) $node->id(),
    'event_owner_uid' => (int) $node->getOwnerId(),
    'logged_in_uid' => (int) $user->id(),
    'logged_in_roles' => $user->getRoles(),
    'vendor_store' => $store_id !== NULL ? ['id' => $store_id, 'label' => $store_label] : NULL,
    'pro_status' => $pro->getProStatus($user),
    'canCustomizeEventPage_logged_in' => $style_access->canCustomizeEventPage($user),
    'canCustomizeEventPage_owner' => $style_access->canCustomizeEventPage((int) $node->getOwnerId()),
    'canEventCustomize_node' => $resolver->canEventCustomize($node),
    'node_field_values' => [
      'field_mel_page_style' => $node->hasField('field_mel_page_style') && !$node->get('field_mel_page_style')->isEmpty()
        ? (string) $node->get('field_mel_page_style')->value : NULL,
      'field_mel_theme_colour' => $node->hasField('field_mel_theme_colour') && !$node->get('field_mel_theme_colour')->isEmpty()
        ? (string) $node->get('field_mel_theme_colour')->value : NULL,
      'field_mel_event_gallery' => audit_gallery_ids($node),
      'field_event_image' => $node->hasField('field_event_image') && !$node->get('field_event_image')->isEmpty()
        ? (int) $node->get('field_event_image')->target_id : NULL,
    ],
  ];
}

/**
 * @param array<string, mixed> $mel_values
 * @param array<string, mixed>|null $mel_user_input_extra
 *
 * @return array<string, mixed>
 */
function audit_trace_save_path(
  NodeInterface $source,
  array $baseline,
  array $mel_values,
  ?array $mel_user_input_extra,
): array {
  /** @var \Drupal\Core\Form\FormBuilderInterface $form_builder */
  $form_builder = \Drupal::formBuilder();
  $form_object = EventBrandingForm::create(\Drupal::getContainer());
  $form_state = new FormState();
  $form_state->addBuildInfo('args', [$source]);
  $form_state->setValues([
    'nid' => (int) $source->id(),
    'mel' => $mel_values,
  ]);

  $user_input = [
    'nid' => (string) $source->id(),
    'mel' => $mel_values,
  ];
  if ($mel_user_input_extra !== NULL) {
    $user_input['mel'] = array_replace_recursive($user_input['mel'], $mel_user_input_extra);
  }
  $form_state->setUserInput($user_input);

  $form = $form_builder->buildForm($form_object, $form_state);
  $built_mel = is_array($form['mel'] ?? NULL) ? $form['mel'] : [];

  $trace = [
    'A_form_state_getValue_mel' => audit_fragment($form_state->getValue('mel') ?? []),
    'B_form_state_getUserInput_mel' => audit_fragment($form_state->getUserInput()['mel'] ?? []),
    'C_before_ids' => audit_gallery_ids($source),
  ];

  // Mirror persistWizardMel merge (style keys only in merged if present in submitted).
  $submitted = $form_state->getValue('mel') ?? [];
  if (!is_array($submitted)) {
    $submitted = [];
  }
  $merge_ref = new ReflectionMethod(\Drupal\myeventlane_event_studio\Form\EventStudioBaseForm::class, 'mergeMel');
  $merge_ref->setAccessible(TRUE);
  $merged = $merge_ref->invoke(EventBrandingForm::create(\Drupal::getContainer()), $baseline, $submitted);
  $trace['merged_mel_style_keys'] = [
    'field_mel_page_style' => $merged['field_mel_page_style'] ?? 'ABSENT',
    'field_mel_theme_colour' => $merged['field_mel_theme_colour'] ?? 'ABSENT',
  ];

  // Gallery pipeline via reflection (matches EventStudioSaveService private methods).
  $save_service = \Drupal::service('myeventlane_event_studio.save');
  $clone = Node::create([
    'type' => 'event',
    'title' => 'audit-clone',
  ]);
  $clone->set('field_mel_event_gallery', $source->get('field_mel_event_gallery')->getValue());
  $clone->set('field_mel_page_style', $source->get('field_mel_page_style')->getValue());
  $clone->set('field_mel_theme_colour', $source->get('field_mel_theme_colour')->getValue());

  $gallery_trace = audit_trace_gallery_pipeline($save_service, $clone, $built_mel, $form_state);
  $trace = array_merge($trace, $gallery_trace);

  // Style pipeline.
  $mel_for_style = $form_state->getValue('mel') ?? [];
  if (!is_array($mel_for_style)) {
    $mel_for_style = [];
  }
  $style_access = \Drupal::service('myeventlane_event_studio.event_style_access');
  $resolver = \Drupal::service('myeventlane_event_studio.event_page_style_resolver');
  $account = \Drupal::currentUser();

  $trace['style'] = [
    'A_submitted_style' => $mel_for_style['field_mel_page_style'] ?? 'ABSENT',
    'B_submitted_colour' => $mel_for_style['field_mel_theme_colour'] ?? 'ABSENT',
    'C_canCustomizeEventPage' => $style_access->canCustomizeEventPage($account),
    'D_resolved' => $resolver->resolveForPersistence($clone, $mel_for_style, $account),
    'E_would_set_style' => array_key_exists('field_mel_page_style', $mel_for_style),
    'E_would_set_colour' => array_key_exists('field_mel_theme_colour', $mel_for_style),
    'F_node_before' => [
      'style' => audit_field_value($clone, 'field_mel_page_style'),
      'colour' => audit_field_value($clone, 'field_mel_theme_colour'),
    ],
  ];

  // Apply style fields on clone without node->save().
  $ref = new ReflectionMethod(EventStudioSaveService::class, 'applyBrandingPageStyleFields');
  $ref->setAccessible(TRUE);
  $ref->invoke($save_service, $clone, $mel_for_style);
  $trace['style']['G_node_after_apply'] = [
    'style' => audit_field_value($clone, 'field_mel_page_style'),
    'colour' => audit_field_value($clone, 'field_mel_theme_colour'),
  ];

  return $trace;
}

/**
 * @return array<string, mixed>
 */
function audit_trace_gallery_pipeline(
  EventStudioSaveService $save_service,
  NodeInterface $node,
  array $mel_structure,
  FormStateInterface $form_state,
): array {
  $ref_sync = new ReflectionMethod(EventStudioSaveService::class, 'syncBrandingGallerySubmittedValues');
  $ref_sync->setAccessible(TRUE);
  $ref_extract = new ReflectionMethod(EventStudioSaveService::class, 'extractBrandingGalleryTargetIdsFromSubmittedMel');
  $ref_extract->setAccessible(TRUE);
  $ref_normalize = new ReflectionMethod(EventStudioSaveService::class, 'normalizeBrandingMelFormStructure');
  $ref_normalize->setAccessible(TRUE);
  $ref_ids = new ReflectionMethod(EventStudioSaveService::class, 'brandingGalleryMediaIdsFromField');
  $ref_ids->setAccessible(TRUE);

  $before_ids = $ref_ids->invoke($save_service, $node->get('field_mel_event_gallery')->getValue());

  $values_before_sync = NestedArray::getValue($form_state->getValues(), ['mel', 'field_mel_event_gallery']);
  $input_before_sync = NestedArray::getValue($form_state->getUserInput(), ['mel', 'field_mel_event_gallery']);

  $fs_clone = clone $form_state;
  $ref_sync->invoke($save_service, $fs_clone);

  $values_after_sync = NestedArray::getValue($fs_clone->getValues(), ['mel', 'field_mel_event_gallery']);
  $input_after_sync = NestedArray::getValue($fs_clone->getUserInput(), ['mel', 'field_mel_event_gallery']);

  $display = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load('node.event.studio_branding');
  $widget = $display?->getRenderer('field_mel_event_gallery');
  $mel_form = $ref_normalize->invoke($save_service, $mel_structure);

  $submitted_ids = NULL;
  if ($widget instanceof MediaLibraryWidget) {
    $submitted_ids = $ref_extract->invoke($save_service, $fs_clone, $widget, $mel_form);
  }

  $items = $node->get('field_mel_event_gallery');
  if ($submitted_ids !== NULL) {
    $items->setValue(array_map(
      static fn(int $media_id): array => ['target_id' => $media_id],
      $submitted_ids,
    ));
  }
  elseif ($widget !== NULL) {
    $widget->extractFormValues($items, $mel_form, $fs_clone);
  }
  $final_value = $items->getValue();
  $final_ids = $ref_ids->invoke($save_service, $final_value);

  return [
    'D_submitted_ids' => $submitted_ids,
    'E_final_value' => $final_value,
    'F_final_ids' => $final_ids,
    'gallery_values_before_sync' => $values_before_sync,
    'gallery_input_before_sync' => $input_before_sync,
    'gallery_values_after_sync' => $values_after_sync,
    'gallery_input_after_sync' => $input_after_sync,
    'sync_skipped_because_values_had_selection' => is_array($values_before_sync) && array_key_exists('selection', $values_before_sync),
  ];
}

/**
 * @return list<int>
 */
function audit_gallery_ids(NodeInterface $node): array {
  if (!$node->hasField('field_mel_event_gallery')) {
    return [];
  }
  $ids = [];
  foreach ($node->get('field_mel_event_gallery')->getValue() as $row) {
    $id = (int) ($row['target_id'] ?? 0);
    if ($id > 0) {
      $ids[] = $id;
    }
  }
  return $ids;
}

/**
 * @param array<string, mixed> $mel
 *
 * @return array<string, mixed>
 */
function audit_fragment(array $mel): array {
  return [
    'field_mel_page_style' => $mel['field_mel_page_style'] ?? 'ABSENT',
    'field_mel_theme_colour' => $mel['field_mel_theme_colour'] ?? 'ABSENT',
    'field_mel_event_gallery' => $mel['field_mel_event_gallery'] ?? 'ABSENT',
  ];
}

function audit_field_value(NodeInterface $node, string $field): ?string {
  if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
    return NULL;
  }
  return (string) $node->get($field)->value;
}
