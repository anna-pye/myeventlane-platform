<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Validates an event for publish (final submit only).
 *
 * Validation runs only when the user clicks Publish on the Review step.
 * Human-readable messages only; no field machine names exposed.
 */
final class EventWizardPublishValidator {

  private const MIN_TITLE_LENGTH = 1;

  /**
   * Constructs the validator.
   */
  public function __construct(
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns the logger for this module.
   */
  private function logger(): \Psr\Log\LoggerInterface {
    return $this->loggerFactory->get('myeventlane_event');
  }

  /**
   * Validates the event for publish using entity + form state.
   *
   * Uses form_state for current form values and entity for saved values
   * so validation reflects what would be saved on Publish.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event entity (may contain stale data; form_state wins for fields).
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state (values from the wizard).
   *
   * @return string[]
   *   List of human-readable error messages. Empty if valid.
   */
  public function validate(NodeInterface $event, FormStateInterface $form_state): array {
    $this->logger()->debug('EventWizardPublishValidator: validating event @id for publish', [
      '@id' => $event->id(),
    ]);

    $errors = [];

    $title = $this->resolveTitle($event, $form_state);
    if ($title === '' || strlen(trim($title)) < self::MIN_TITLE_LENGTH) {
      $errors[] = (string) t('Please enter an event title.');
    }

    $start = $this->resolveValue($event, $form_state, 'field_event_start');
    if (empty($start)) {
      $errors[] = (string) t('Please set the event start date and time.');
    }

    $end = $this->resolveValue($event, $form_state, 'field_event_end');
    if (!empty($start) && !empty($end)) {
      $startTs = is_numeric($start) ? (int) $start : strtotime($start);
      $endTs = is_numeric($end) ? (int) $end : strtotime($end);
      if ($endTs <= $startTs) {
        $errors[] = (string) t('The end date and time must be after the start.');
      }
    }

    $hasLocation = $this->resolveHasLocation($event, $form_state);
    $externalUrl = $this->resolveExternalUrl($event, $form_state);
    if (!$hasLocation && empty($externalUrl)) {
      $errors[] = (string) t('Please add a venue address or an external event link.');
    }

    $joinType = $this->resolveJoinType($event, $form_state);
    if (empty($joinType) || !in_array($joinType, ['rsvp', 'paid', 'both', 'external'], TRUE)) {
      $errors[] = (string) t('Please choose how people can join (RSVP, paid tickets, or external link).');
    }

    if ($joinType === 'external') {
      if (empty($externalUrl)) {
        $errors[] = (string) t('External events need an event link.');
      }
    }
    elseif (in_array($joinType, ['paid', 'both'], TRUE)) {
      $productTarget = $this->resolveProductTarget($event, $form_state);
      if (empty($productTarget)) {
        $errors[] = (string) t('Paid events need at least one ticket or product linked.');
      }
    }
    elseif ($joinType === 'rsvp') {
      if ($event->hasField('field_rsvp_target') && $this->resolveRsvpTarget($event, $form_state) === NULL) {
        $errors[] = (string) t('RSVP events need an RSVP target set.');
      }
    }

    return $errors;
  }

  /**
   * Resolves title from form state or entity.
   */
  private function resolveTitle(NodeInterface $event, FormStateInterface $form_state): string {
    $v = $form_state->getValue('title');
    if ($v !== NULL) {
      $s = is_array($v) ? ($v[0]['value'] ?? '') : (string) $v;
      return trim($s);
    }
    return trim((string) $event->label());
  }

  /**
   * Resolves a datetime field value (stored value or widget path).
   */
  private function resolveValue(NodeInterface $event, FormStateInterface $form_state, string $field_name): mixed {
    $v = $form_state->getValue([$field_name, 0, 'value']);
    if ($v !== NULL && $v !== '') {
      return $v;
    }
    if ($event->hasField($field_name) && !$event->get($field_name)->isEmpty()) {
      return $event->get($field_name)->value;
    }
    return NULL;
  }

  /**
   * Resolves whether a location/address is set.
   */
  private function resolveHasLocation(NodeInterface $event, FormStateInterface $form_state): bool {
    $venue = $form_state->getValue(['field_venue_address', 0, 'address'])
      ?? $form_state->getValue(['field_location', 0, 'address']);
    if (is_array($venue)) {
      $line1 = trim($venue['address_line1'] ?? '');
      $locality = trim($venue['locality'] ?? '');
      $country = trim($venue['country_code'] ?? '');
      if ($line1 !== '' || ($locality !== '' && $country !== '')) {
        return TRUE;
      }
    }
    if ($event->hasField('field_venue_address') && !$event->get('field_venue_address')->isEmpty()) {
      $addr = $event->get('field_venue_address')->first();
      if ($addr && ($addr->address_line1 !== '' || $addr->locality !== '')) {
        return TRUE;
      }
    }
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $addr = $event->get('field_location')->first();
      if ($addr && ($addr->address_line1 !== '' || $addr->locality !== '')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Resolves external URL from form or entity.
   */
  private function resolveExternalUrl(NodeInterface $event, FormStateInterface $form_state): ?string {
    $v = $form_state->getValue(['field_external_url', 0, 'uri']);
    if ($v !== NULL && trim((string) $v) !== '') {
      return trim((string) $v);
    }
    if ($event->hasField('field_external_url') && !$event->get('field_external_url')->isEmpty()) {
      $link = $event->get('field_external_url')->first();
      return $link ? trim((string) $link->uri) : NULL;
    }
    return NULL;
  }

  /**
   * Resolves join type (event type) from form or entity.
   */
  private function resolveJoinType(NodeInterface $event, FormStateInterface $form_state): ?string {
    $v = $form_state->getValue(['field_event_type', 0, 'value'])
      ?? $form_state->getValue('field_event_type');
    if ($v !== NULL) {
      $s = is_array($v) ? ($v[0]['value'] ?? $v['value'] ?? '') : (string) $v;
      if ($s !== '') {
        return $s;
      }
    }
    if ($event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
      return $event->get('field_event_type')->value;
    }
    return NULL;
  }

  /**
   * Resolves product target (paid/both) from form or entity.
   */
  private function resolveProductTarget(NodeInterface $event, FormStateInterface $form_state): mixed {
    $v = $form_state->getValue(['field_product_target', 0, 'target_id']);
    if ($v !== NULL && $v !== '') {
      return $v;
    }
    if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
      return $event->get('field_product_target')->target_id;
    }
    return NULL;
  }

  /**
   * Resolves RSVP target from form or entity.
   */
  private function resolveRsvpTarget(NodeInterface $event, FormStateInterface $form_state): mixed {
    $v = $form_state->getValue(['field_rsvp_target', 0, 'target_id']);
    if ($v !== NULL && $v !== '') {
      return $v;
    }
    if ($event->hasField('field_rsvp_target') && !$event->get('field_rsvp_target')->isEmpty()) {
      return $event->get('field_rsvp_target')->target_id;
    }
    return NULL;
  }

}
