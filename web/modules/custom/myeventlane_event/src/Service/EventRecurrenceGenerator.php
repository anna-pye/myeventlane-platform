<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use RRule\RRule;

/**
 * Generates recurring event instances from a series template.
 *
 * Given a template event with field_is_series_template and field_series_rrule,
 * computes instance dates via RRULE and creates/updates child Event nodes.
 * Does NOT duplicate event save logic—uses node storage directly.
 */
final class EventRecurrenceGenerator {

  /**
   * Fields to copy from template to instances (safe, non-date fields).
   *
   * @var array<string>
   */
  private const COPY_FIELDS = [
    'body',
    'field_category',
    'field_event_image',
    'field_event_intro',
    'field_event_type',
    'field_location',
    'field_venue_name',
    'field_capacity',
    'field_refund_policy',
    'field_age_restriction',
    'field_accessibility',
    'field_accessibility_contact',
    'field_accessibility_directions',
    'field_accessibility_entry',
    'field_accessibility_parking',
    'field_attendee_questions',
    'field_ticket_types',
    'field_event_vendor',
    'field_event_store',
  ];

  /**
   * Constructs EventRecurrenceGenerator.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Generates instance events from a series template.
   *
   * @param \Drupal\node\NodeInterface $template
   *   The series template event (field_is_series_template = TRUE).
   *
   * @return array{created: int, updated: int, errors: array}
   *   Counts of created/updated instances and any errors.
   */
  public function generateInstances(NodeInterface $template): array {
    if (!$this->isSeriesTemplate($template)) {
      return ['created' => 0, 'updated' => 0, 'errors' => ['Not a series template.']];
    }

    $rrule_str = $this->getRruleString($template);
    if ($rrule_str === '' || $rrule_str === NULL) {
      return ['created' => 0, 'updated' => 0, 'errors' => ['RRULE is empty.']];
    }

    $dtstart = $this->getDtstart($template);
    if (!$dtstart) {
      return ['created' => 0, 'updated' => 0, 'errors' => ['Event start date is required.']];
    }

    try {
      $rrule = new RRule($rrule_str, $dtstart);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'Invalid RRULE for template @id: @message',
        ['@id' => $template->id(), '@message' => $e->getMessage()]
      );
      return ['created' => 0, 'updated' => 0, 'errors' => [$e->getMessage()]];
    }

    $duration_seconds = $this->getDurationSeconds($template);
    $existing = $this->loadExistingInstances($template->id());

    $created = 0;
    $updated = 0;
    $errors = [];

    foreach ($rrule as $occurrence) {
      /** @var \DateTime $occurrence */
      $instance_id = $occurrence->format('Ymd\THi');
      $start_str = $occurrence->format('Y-m-d\TH:i:s');
      $end_dt = clone $occurrence;
      $end_dt->modify('+' . $duration_seconds . ' seconds');
      $end_str = $end_dt->format('Y-m-d\TH:i:s');

      if (isset($existing[$instance_id])) {
        $node = $existing[$instance_id];
        $this->updateInstanceDates($node, $start_str, $end_str);
        $node->save();
        $updated++;
        unset($existing[$instance_id]);
      }
      else {
        $node = $this->createInstance($template, $instance_id, $start_str, $end_str);
        if ($node) {
          $node->save();
          $created++;
        }
        else {
          $errors[] = "Failed to create instance $instance_id";
        }
      }
    }

    if (!empty($errors)) {
      $this->loggerFactory->get('myeventlane_event')->warning(
        'Series generation had errors for template @id: @errors',
        ['@id' => $template->id(), '@errors' => implode('; ', $errors)]
      );
    }

    return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
  }

  /**
   * Checks if an event is a series template.
   */
  public function isSeriesTemplate(NodeInterface $event): bool {
    if (!$event->hasField('field_is_series_template') || $event->get('field_is_series_template')->isEmpty()) {
      return FALSE;
    }
    return (bool) $event->get('field_is_series_template')->value;
  }

  /**
   * Loads existing instance nodes for a template.
   *
   * @return array<string, \Drupal\node\NodeInterface>
   *   Keyed by field_series_instance_id value.
   */
  public function loadExistingInstances(int $template_id): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('field_series_parent', $template_id);

    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    $nodes = $storage->loadMultiple($ids);
    $result = [];
    foreach ($nodes as $node) {
      $id_val = $node->get('field_series_instance_id')->value ?? '';
      if ($id_val !== '') {
        $result[$id_val] = $node;
      }
    }
    return $result;
  }

  /**
   * Gets the RRULE string from the template.
   */
  private function getRruleString(NodeInterface $template): ?string {
    if (!$template->hasField('field_series_rrule')) {
      return NULL;
    }
    $val = $template->get('field_series_rrule')->value;
    return is_string($val) ? trim($val) : NULL;
  }

  /**
   * Gets DTSTART as DateTime from template's field_event_start.
   */
  private function getDtstart(NodeInterface $template): ?\DateTime {
    if (!$template->hasField('field_event_start') || $template->get('field_event_start')->isEmpty()) {
      return NULL;
    }
    $val = $template->get('field_event_start')->value;
    if (!$val) {
      return NULL;
    }
    $tz = $this->getTemplateTimezone($template);
    try {
      return new \DateTime($val, $tz);
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  /**
   * Gets template timezone for RRULE interpretation.
   */
  private function getTemplateTimezone(NodeInterface $template): \DateTimeZone {
    if ($template->hasField('field_series_timezone') && !$template->get('field_series_timezone')->isEmpty()) {
      $tz_name = $template->get('field_series_timezone')->value;
      if (is_string($tz_name) && $tz_name !== '') {
        try {
          return new \DateTimeZone($tz_name);
        }
        catch (\Throwable $e) {
          // Fall through to default.
        }
      }
    }
    return new \DateTimeZone('UTC');
  }

  /**
   * Gets duration in seconds (start to end) from template.
   */
  private function getDurationSeconds(NodeInterface $template): int {
    if (!$template->hasField('field_event_start') || $template->get('field_event_start')->isEmpty()) {
      return 0;
    }
    $start_val = $template->get('field_event_start')->value;
    if (!$template->hasField('field_event_end') || $template->get('field_event_end')->isEmpty()) {
      return 7200;
    }
    $end_val = $template->get('field_event_end')->value;
    if (!$start_val || !$end_val) {
      return 7200;
    }
    try {
      $start = new \DateTime($start_val);
      $end = new \DateTime($end_val);
      return (int) $end->getTimestamp() - $start->getTimestamp();
    }
    catch (\Throwable $e) {
      return 7200;
    }
  }

  /**
   * Creates a new instance node from the template.
   */
  private function createInstance(NodeInterface $template, string $instance_id, string $start_str, string $end_str): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $node = $storage->create([
      'type' => 'event',
      'title' => $template->label() . ' — ' . (new \DateTime($start_str))->format('M j, Y'),
      'uid' => $template->getOwnerId(),
      'status' => $template->isPublished() ? 1 : 0,
    ]);

    $node->set('field_series_parent', $template->id());
    $node->set('field_series_instance_id', $instance_id);
    $node->set('field_event_start', $start_str);
    $node->set('field_event_end', $end_str);

    foreach (self::COPY_FIELDS as $field_name) {
      if ($template->hasField($field_name) && $node->hasField($field_name) && !$template->get($field_name)->isEmpty()) {
        $node->set($field_name, $template->get($field_name)->getValue());
      }
    }

    return $node;
  }

  /**
   * Updates instance date fields only (for regeneration).
   */
  private function updateInstanceDates(NodeInterface $node, string $start_str, string $end_str): void {
    $node->set('field_event_start', $start_str);
    $node->set('field_event_end', $end_str);
  }

}
