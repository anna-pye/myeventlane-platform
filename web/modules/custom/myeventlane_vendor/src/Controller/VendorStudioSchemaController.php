<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides schema metadata for Vendor Studio dynamic forms.
 */
final class VendorStudioSchemaController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Deterministic Studio group labels.
   *
   * @var array<string, string>
   */
  private const GROUP_LABELS = [
    'overview' => 'Overview',
    'dates' => 'Dates',
    'settings' => 'Settings',
    'advanced' => 'Advanced',
  ];

  /**
   * Deterministic Studio group field mapping for Event bundle fields.
   *
   * @var array<string, string[]>
   */
  private const GROUP_FIELD_MAP = [
    'overview' => [
      'body',
      'field_event_summary',
      'field_event_intro',
      'field_event_image',
      'field_event_type',
      'field_venue_name',
      'field_venue_address',
      'field_location',
      'field_contact_email',
      'field_contact_phone',
      'field_external_url',
      'field_tags',
      'field_category',
      'field_event_vendor',
    ],
    'dates' => [
      'field_event_start',
      'field_event_end',
      'field_sales_start',
      'field_sales_end',
      'field_promo_expires',
      'field_cancelled_at',
      'field_series_rrule',
      'field_series_timezone',
      'field_series_instance_id',
      'field_series_parent',
    ],
    'settings' => [
      'field_capacity',
      'field_event_capacity_total',
      'field_waitlist_capacity',
      'field_collect_per_ticket',
      'field_reviews_enabled',
      'field_refund_policy',
      'field_age_policy',
      'field_age_policy_note',
      'field_age_restriction',
      'field_featured',
      'field_promoted',
      'field_event_state',
      'field_event_state_override',
      'field_event_setup_complete',
      'field_is_series_template',
    ],
  ];

  /**
   * Creates the schema controller.
   */
  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('logger.channel.myeventlane_vendor'),
    );
  }

  /**
   * Returns non-base Event fields for Studio schema generation.
   */
  public function eventSchema(): JsonResponse {
    try {
      $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'event');
    }
    catch (\Throwable $exception) {
      $this->logger->error('Vendor Studio schema load failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Could not load event field schema.',
      ], 500);
    }

    $fields = [];
    $field_names = [];

    foreach ($definitions as $name => $definition) {
      if ($definition->isComputed()) {
        continue;
      }

      $storage = $definition->getFieldStorageDefinition();
      if ($storage->isBaseField()) {
        continue;
      }

      $field_type = (string) $definition->getType();
      $target_type = (string) ($definition->getSetting('target_type') ?? '');
      $requires_special_handling = $field_type === 'entity_reference_revisions'
        || ($field_type === 'entity_reference' && $target_type === 'paragraph');
      $group_key = $this->resolveGroupKey($name);

      $fields[] = [
        'name' => $name,
        'label' => (string) $definition->getLabel(),
        'description' => trim((string) $definition->getDescription()),
        'type' => $field_type,
        'required' => $definition->isRequired(),
        'cardinality' => $storage->getCardinality(),
        'group' => $group_key,
        'input_mode' => $field_type === 'entity_reference' ? 'readonly' : 'editable',
        'read_only' => $field_type === 'entity_reference',
        'target_type' => $target_type,
        'allowed_values' => $this->extractAllowedValues($definition->getSetting('allowed_values')),
        'supported' => !$requires_special_handling,
        'adapter' => $requires_special_handling ? 'paragraph' : '',
        'adapter_key' => $requires_special_handling ? 'paragraph_' . $name : '',
        'unsupported_reason' => $requires_special_handling ? 'paragraph_reference' : '',
      ];
      $field_names[] = $name;
    }

    return new JsonResponse([
      'success' => TRUE,
      'entity' => 'event',
      'bundle' => 'event',
      'groups' => $this->buildGroups($field_names),
      'fields' => $fields,
    ]);
  }

  /**
   * Maps a field machine name to a Studio group key.
   */
  private function resolveGroupKey(string $field_name): string {
    foreach (self::GROUP_FIELD_MAP as $group_key => $field_list) {
      if (in_array($field_name, $field_list, TRUE)) {
        return $group_key;
      }
    }
    return 'advanced';
  }

  /**
   * Builds deterministic grouping metadata for the schema response.
   *
   * @param string[] $field_names
   *   Field machine names present in this schema response.
   *
   * @return array<int, array{key:string, label:string, fields:string[]}>
   *   Group rows.
   */
  private function buildGroups(array $field_names): array {
    $groups = [];
    $assigned = [];

    foreach (self::GROUP_FIELD_MAP as $group_key => $known_fields) {
      $present = array_values(array_intersect($known_fields, $field_names));
      if ($present === []) {
        continue;
      }
      $groups[] = [
        'key' => $group_key,
        'label' => self::GROUP_LABELS[$group_key],
        'fields' => $present,
      ];
      $assigned = array_merge($assigned, $present);
    }

    $advanced_fields = array_values(array_diff($field_names, array_unique($assigned)));
    sort($advanced_fields);
    $groups[] = [
      'key' => 'advanced',
      'label' => self::GROUP_LABELS['advanced'],
      'fields' => $advanced_fields,
    ];

    return $groups;
  }

  /**
   * Normalizes allowed-values settings for list_string rendering.
   *
   * @param mixed $allowed_values
   *   Field allowed values setting.
   *
   * @return array<int, array{value:string, label:string}>
   *   Normalized options.
   */
  private function extractAllowedValues(mixed $allowed_values): array {
    if (!is_array($allowed_values)) {
      return [];
    }

    $options = [];
    foreach ($allowed_values as $value => $label) {
      $options[] = [
        'value' => (string) $value,
        'label' => (string) $label,
      ];
    }
    return $options;
  }

}
