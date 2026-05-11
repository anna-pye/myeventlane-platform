<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\myeventlane_questions\Entity\VendorQuestionInterface;
use Drupal\myeventlane_questions\Service\QuestionTemplateCloner;
use Drupal\myeventlane_venue\Entity\Venue;
use Drupal\myeventlane_venue\Service\VenueManager;
use Drupal\myeventlane_vendor\Service\PaidPublishStripeGate;
use Drupal\myeventlane_vendor\Service\VendorPublishRequirementsGate;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Psr\Log\LoggerInterface;

/**
 * Canonical orchestrator for vendor-originated event node edits from Event Studio.
 *
 * - Owns persistence for the Event Studio form (title, schedule, venue/address,
 *   coordinates, publish flag on full save).
 * - Vendor shell JSON that mutates the same scalar fields must delegate here
 *   (see patchOverviewBasics) so save/revision behaviour stays consistent.
 * - Legacy step-wizard and admin node forms are out of scope; they must not be
 *   linked from vendor surfaces (see VendorLegacyWizardRedirectSubscriber).
 */
final class EventStudioSaveService {

  private const ATTENDEE_QUESTION_LIMIT = 5;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VenueManager $venueManager,
    private readonly LoggerInterface $logger,
    private readonly EventHighlightHelper $eventHighlightHelper,
    private readonly PaidPublishStripeGate $paidPublishStripeGate,
    private readonly VendorPublishRequirementsGate $publishRequirementsGate,
    private readonly EventReadinessService $eventReadiness,
    private readonly ?QuestionTemplateCloner $questionTemplateCloner = NULL,
  ) {}

  /**
   * @param array<string, mixed> $payload
   *   Normalised values from form or request.
   * @param \Drupal\node\NodeInterface|null $node
   *   Existing event or NULL to create.
   *
   * @return array{node: ?\Drupal\node\NodeInterface, errors: list<string>}
   */
  public function save(array $payload, ?NodeInterface $node, AccountInterface $account, bool $draft = FALSE): array {
    $effectiveEventType = isset($payload['field_event_type'])
      ? (string) $payload['field_event_type']
      : ($node instanceof NodeInterface && $node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()
        ? (string) $node->get('field_event_type')->value
        : 'rsvp');

    $willPublish = FALSE;
    if (!$draft) {
      if ($node === NULL) {
        $willPublish = (bool) ($payload['status'] ?? FALSE);
      }
      else {
        $willPublish = (bool) ($payload['status'] ?? TRUE);
      }
    }

    if (!$draft && $willPublish) {
      $denials = $this->publishRequirementsGate->getLivePublishDenialReasons($account);
      if ($denials !== []) {
        return ['node' => NULL, 'errors' => $denials];
      }
    }

    if (!$draft && $willPublish && in_array($effectiveEventType, ['paid', 'both'], TRUE)) {
      $stripeMsg = $this->paidPublishStripeGate->validatePaidPublishAllowed(
        $account,
        $node instanceof NodeInterface ? (int) $node->id() : NULL,
      );
      if ($stripeMsg !== NULL) {
        return ['node' => NULL, 'errors' => [$stripeMsg]];
      }
    }

    $storage = $this->entityTypeManager->getStorage('node');
    if ($node === NULL) {
      $node = $storage->create([
        'type' => 'event',
        'title' => trim((string) ($payload['title'] ?? 'Untitled event')),
        'uid' => (int) $account->id(),
        'status' => $draft ? 0 : ($willPublish ? 1 : 0),
      ]);
    }
    else {
      if ($node->bundle() !== 'event') {
        return ['node' => NULL, 'errors' => ['Invalid event.']];
      }
      $node->setTitle(trim((string) ($payload['title'] ?? $node->label())));
      if (!$draft) {
        $node->setPublished((bool) ($payload['status'] ?? TRUE));
      }
    }

    // Editorial workflow: `draft` is not a published moderation state. Content Moderation's
    // presave syncs the entity published flag to that state, undoing setPublished(TRUE).
    // When the vendor intends to publish, transition to `published` so presave stays aligned.
    if (!$draft && $willPublish && $node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()
        && (string) $node->get('moderation_state')->value === 'draft') {
      $node->set('moderation_state', 'published');
    }

    if ($node->hasField('field_event_summary') && isset($payload['summary'])) {
      $s = trim((string) $payload['summary']);
      if ($s !== '') {
        $node->set('field_event_summary', $s);
      }
    }

    $this->applyBodyPayload($node, $payload);
    $this->applyEventIntroPayload($node, $payload);
    $this->applyDiscoveryTaxonomy($node, $payload);
    $this->applyContactChannelsPayload($node, $payload);

    if ($node->hasField('field_event_start') && !empty($payload['field_event_start'])) {
      $node->set('field_event_start', [['value' => (string) $payload['field_event_start']]]);
    }
    if ($node->hasField('field_event_end') && !empty($payload['field_event_end'])) {
      $node->set('field_event_end', [['value' => (string) $payload['field_event_end']]]);
    }

    $this->applySalesWindowPayload($node, $payload);
    $this->applyAgeRefundPolicyPayload($node, $payload);
    $this->applyAccessibilityTextPayload($node, $payload);

    if ($node->hasField('field_event_type') && isset($payload['field_event_type'])) {
      $node->set('field_event_type', (string) $payload['field_event_type']);
    }

    $event_type = (string) ($payload['field_event_type'] ?? ($node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()
      ? (string) $node->get('field_event_type')->value
      : 'rsvp'));

    $ticket_errors = $this->applyTicketPayload($node, $payload, $event_type, $draft);
    if ($ticket_errors !== []) {
      return ['node' => NULL, 'errors' => $ticket_errors];
    }

    $donationEnabled = !empty($payload['enable_donations']) || !empty($payload['donation_enabled']);
    if ($node->hasField('field_enable_donations')) {
      $node->set('field_enable_donations', $donationEnabled);
    }
    if ($node->hasField('field_rsvp_donation_enabled')) {
      $node->set('field_rsvp_donation_enabled', $donationEnabled);
    }
    if ($node->hasField('field_donation_suggested_amount')) {
      $amount = $payload['donation_amount'] ?? NULL;
      if ($amount === '' || $amount === NULL) {
        $node->set('field_donation_suggested_amount', NULL);
      }
      else {
        $node->set('field_donation_suggested_amount', (string) $amount);
      }
    }
    if ($node->hasField('field_donation_default')) {
      $amount = $payload['donation_amount'] ?? NULL;
      if ($amount === '' || $amount === NULL) {
        $node->set('field_donation_default', NULL);
      }
      else {
        $node->set('field_donation_default', (string) $amount);
      }
    }
    if ($node->hasField('field_donation_options')) {
      $rawOptions = trim((string) ($payload['donation_options'] ?? ''));
      if ($rawOptions === '') {
        $node->set('field_donation_options', NULL);
      }
      else {
        $parts = array_values(array_filter(array_map('trim', explode(',', $rawOptions)), static fn($v) => $v !== ''));
        $normalized = [];
        foreach ($parts as $part) {
          if (is_numeric($part) && (float) $part > 0) {
            $normalized[] = (float) $part;
          }
        }
        $node->set('field_donation_options', $normalized === [] ? NULL : json_encode($normalized));
      }
    }
    if ($node->hasField('field_donation_label')) {
      $label = trim((string) ($payload['donation_label'] ?? 'Support this event'));
      $node->set('field_donation_label', $label !== '' ? $label : 'Support this event');
    }

    $choice = (string) ($payload['venue_choice'] ?? 'one_off');
    $location_values = NULL;

    $skip_venue_location = $draft && (
      ($choice === 'saved' && (int) ($payload['venue_id'] ?? 0) < 1)
      || ($choice === 'create' && trim((string) ($payload['new_venue_name'] ?? '')) === '')
    );

    if ($skip_venue_location) {
      $location_values = NULL;
    }
    elseif ($choice === 'saved') {
      $vid = (int) ($payload['venue_id'] ?? 0);
      if ($vid < 1) {
        return ['node' => NULL, 'errors' => ['Select a saved venue.']];
      }
      $venue = $this->entityTypeManager->getStorage('myeventlane_venue')->load($vid);
      if (!$venue instanceof Venue) {
        return ['node' => NULL, 'errors' => ['Venue not found.']];
      }
      $node->set('field_venue', ['target_id' => $vid]);
      $primary = $this->venueManager->getPrimaryLocation($venue);
      $location_values = $primary ? [$this->addressFromVenueLocation($primary)] : [];
    }
    elseif ($choice === 'create') {
      $name = trim((string) ($payload['new_venue_name'] ?? ''));
      if ($name === '') {
        return ['node' => NULL, 'errors' => ['Venue name is required.']];
      }
      $row = $this->normalizeAddressRow($payload['field_location'] ?? []);
      if ($row === NULL) {
        return ['node' => NULL, 'errors' => ['Enter an address for the new venue.']];
      }
      try {
        $venue = $this->venueManager->createVenueWithLocation(
          ['name' => $name, 'visibility' => Venue::VISIBILITY_SHARED, 'description' => ''],
          [
            'title' => $name,
            'address_text' => $this->formatAddressText($row),
            'lat' => $payload['field_location_latitude'] ?? NULL,
            'lng' => $payload['field_location_longitude'] ?? NULL,
          ],
          (int) $account->id()
        );
      }
      catch (\Throwable $e) {
        $this->logger->error('Studio venue create failed: @m', ['@m' => $e->getMessage()]);
        return ['node' => NULL, 'errors' => ['Could not create venue.']];
      }
      $node->set('field_venue', ['target_id' => $venue->id()]);
      $location_values = [$row];
    }
    else {
      if ($node->hasField('field_venue')) {
        $node->set('field_venue', NULL);
      }
      $row = $this->normalizeAddressRow($payload['field_location'] ?? []);
      if (!$draft && $row === NULL) {
        return ['node' => NULL, 'errors' => ['Location is required.']];
      }
      $location_values = $row !== NULL ? [$row] : [];
    }

    if ($node->hasField('field_location') && $location_values !== NULL) {
      $node->set('field_location', $location_values);
    }

    $this->applyOptionalCoordinates($node, $payload);

    $image_errors = $this->applyHeroImagePayload($node, $payload, $draft);
    if ($image_errors !== []) {
      return ['node' => NULL, 'errors' => $image_errors];
    }

    if (array_key_exists('event_highlights_items_state', $payload)) {
      $highlight_errors = $this->eventHighlightHelper->validateHighlightItemsStateJson((string) ($payload['event_highlights_items_state'] ?? ''));
      if ($highlight_errors !== []) {
        return ['node' => NULL, 'errors' => $highlight_errors];
      }
    }

    try {
      $this->syncEventHighlights($node, $payload);
    }
    catch (\Throwable $e) {
      $this->logger->error('Studio event highlights sync failed: @m', ['@m' => $e->getMessage()]);
      return ['node' => NULL, 'errors' => ['Could not save event highlights.']];
    }

    $attendee_errors = $this->syncAttendeeQuestions($node, $payload, $account);
    if ($attendee_errors !== []) {
      return ['node' => NULL, 'errors' => $attendee_errors];
    }

    if (!$draft && $willPublish) {
      $readiness = $this->eventReadiness->evaluate($node, $account);
      if (!$readiness->ready) {
        return ['node' => NULL, 'errors' => $readiness->errors];
      }
    }

    EventNodeRevisionSave::prepare($node, $draft ? 'Event Studio draft.' : 'Event Studio save.');
    try {
      $node->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Studio event save failed: @m', ['@m' => $e->getMessage()]);
      return ['node' => NULL, 'errors' => ['Save failed.']];
    }

    return ['node' => $node, 'errors' => []];
  }

  /**
   * Persists Overview tab fields from the vendor Studio shell (title, summary, type).
   *
   * Does not change location or tickets; those use save() from the Studio UI.
   *
   * @param array<string, mixed> $payload
   *   Decoded JSON body (title, field_event_summary, field_event_type).
   *
   * @return array{ok: bool, http_status: int, message: string}
   */
  public function patchOverviewBasics(NodeInterface $node, AccountInterface $account, array $payload): array {
    if ($node->bundle() !== 'event') {
      return ['ok' => FALSE, 'http_status' => 422, 'message' => 'Invalid event.'];
    }

    $title = isset($payload['title']) ? trim((string) $payload['title']) : '';
    $summary = isset($payload['field_event_summary']) ? trim((string) $payload['field_event_summary']) : '';
    $event_type = isset($payload['field_event_type']) ? trim((string) $payload['field_event_type']) : '';

    if ($title === '') {
      return ['ok' => FALSE, 'http_status' => 422, 'message' => 'Event name is required.'];
    }

    if (strlen($summary) > 255) {
      return ['ok' => FALSE, 'http_status' => 422, 'message' => 'Summary must be 255 characters or less.'];
    }

    $allowed_event_types = [];
    if ($node->hasField('field_event_type')) {
      $allowed = (array) $node->getFieldDefinition('field_event_type')->getSetting('allowed_values');
      $allowed_event_types = array_keys($allowed);
    }

    if ($event_type === '' || !in_array($event_type, $allowed_event_types, TRUE)) {
      return ['ok' => FALSE, 'http_status' => 422, 'message' => 'Invalid event type.'];
    }

    try {
      $has_changes = FALSE;

      if ($node->label() !== $title) {
        $node->setTitle($title);
        $has_changes = TRUE;
      }

      if ($node->hasField('field_event_summary')) {
        $current_summary = (string) ($node->get('field_event_summary')->value ?? '');
        if ($current_summary !== $summary) {
          $node->set('field_event_summary', $summary);
          $has_changes = TRUE;
        }
      }

      if ($node->hasField('field_event_type')) {
        $current_type = (string) ($node->get('field_event_type')->value ?? '');
        if ($current_type !== $event_type) {
          $node->set('field_event_type', $event_type);
          $has_changes = TRUE;
        }
      }

      if ($has_changes) {
        EventNodeRevisionSave::prepare($node, 'Updated Overview fields from Vendor Studio.');
        if ($node->getEntityType()->isRevisionable()) {
          $node->setRevisionUserId((int) $account->id());
        }
        $node->save();
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Studio overview patch failed: @m', ['@m' => $e->getMessage()]);
      return ['ok' => FALSE, 'http_status' => 500, 'message' => 'Could not save changes.'];
    }

    return ['ok' => TRUE, 'http_status' => 200, 'message' => 'Overview saved.'];
  }

  /**
   * Sets published flag for vendor list bulk actions (single orchestrated path).
   */
  public function setNodePublishedState(NodeInterface $node, AccountInterface $account, bool $published, string $revision_log = 'Vendor events list publish action.'): void {
    if ($node->bundle() !== 'event') {
      throw new \InvalidArgumentException('Expected event node.');
    }
    if ($published) {
      $denials = $this->publishRequirementsGate->getLivePublishDenialReasons($account);
      if ($denials !== []) {
        throw new \InvalidArgumentException(implode(' ', $denials));
      }
      $eventType = $node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()
        ? (string) $node->get('field_event_type')->value
        : 'rsvp';
      if (in_array($eventType, ['paid', 'both'], TRUE)) {
        $stripeMsg = $this->paidPublishStripeGate->validatePaidPublishAllowed($account, (int) $node->id());
        if ($stripeMsg !== NULL) {
          throw new \InvalidArgumentException($stripeMsg);
        }
      }
      $readiness = $this->eventReadiness->evaluate($node, $account);
      if (!$readiness->ready) {
        throw new \InvalidArgumentException(implode(' ', $readiness->errors));
      }
    }
    $node->setPublished($published);
    EventNodeRevisionSave::prepare($node, $revision_log);
    if ($node->getEntityType()->isRevisionable()) {
      $node->setRevisionUserId((int) $account->id());
    }
    try {
      $node->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Studio orchestrated publish save failed: @m', ['@m' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * @param mixed $raw
   *   Address field value shape, JSON string from Event Studio hidden field, or empty.
   *
   * @return array<string, string>|null
   */
  private function normalizeAddressRow(mixed $raw): ?array {
    $row = $raw;
    if (is_string($raw)) {
      $trimmed = trim($raw);
      if ($trimmed === '') {
        return NULL;
      }
      $decoded = json_decode($trimmed, TRUE);
      $row = is_array($decoded) ? $decoded : [];
    }
    elseif (!is_array($raw)) {
      return NULL;
    }
    if (isset($row[0]) && is_array($row[0])) {
      $first = $row[0];
      $row = isset($first['address']) && is_array($first['address']) ? $first['address'] : $first;
    }
    if (!is_array($row)) {
      return NULL;
    }
    $line1 = trim((string) ($row['address_line1'] ?? ''));
    $locality = trim((string) ($row['locality'] ?? ''));
    if ($line1 === '' && $locality === '') {
      return NULL;
    }
    return [
      'country_code' => trim((string) ($row['country_code'] ?? 'AU')) ?: 'AU',
      'address_line1' => $line1,
      'address_line2' => trim((string) ($row['address_line2'] ?? '')),
      'locality' => $locality,
      'administrative_area' => trim((string) ($row['administrative_area'] ?? '')),
      'postal_code' => trim((string) ($row['postal_code'] ?? '')),
    ];
  }

  /**
   * @return array<string, string>
   */
  private function addressFromVenueLocation(object $location): array {
    $text = method_exists($location, 'getAddressText') ? trim($location->getAddressText()) : '';
    return [
      'country_code' => 'AU',
      'address_line1' => $text,
      'address_line2' => '',
      'locality' => '',
      'administrative_area' => '',
      'postal_code' => '',
    ];
  }

  /**
   * @param array<string, string> $address_row
   */
  private function formatAddressText(array $address_row): string {
    return implode(', ', array_filter([
      $address_row['address_line1'] ?? '',
      $address_row['locality'] ?? '',
      $address_row['administrative_area'] ?? '',
      $address_row['postal_code'] ?? '',
    ], static fn ($p) => $p !== ''));
  }

  /**
   * Maps Studio booking payload to event-level fields.
   *
   * @param array<string, mixed> $payload
   *
   * @return list<string>
   */
  private function applyTicketPayload(NodeInterface $node, array $payload, string $event_type, bool $draft): array {
    if ($node->hasField('field_capacity')) {
      if ($event_type === 'rsvp' && array_key_exists('capacity', $payload)) {
        $raw = $payload['capacity'];
        if ($raw === NULL || $raw === '') {
          $node->get('field_capacity')->setValue([]);
        }
        else {
          $cap = (int) $raw;
          $node->set('field_capacity', $cap > 0 ? $cap : NULL);
        }
      }
      elseif (!$draft && $event_type !== 'rsvp') {
        $node->get('field_capacity')->setValue([]);
      }
    }

    if ($node->hasField('field_external_url')) {
      if ($event_type === 'external') {
        $url = trim((string) ($payload['external_url'] ?? ''));
        if ($url !== '' && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
          $url = 'https://' . $url;
        }
        if (!$draft && $url === '') {
          return ['Booking URL is required for external events.'];
        }
        if ($url !== '') {
          $node->set('field_external_url', [['uri' => $url, 'title' => '']]);
        }
      }
      else {
        $node->set('field_external_url', []);
      }
    }

    if ($node->hasField('field_collect_per_ticket')) {
      if (in_array($event_type, ['paid', 'rsvp'], TRUE) && (array_key_exists('collect_per_ticket', $payload) || array_key_exists('collect_attendee_questions', $payload))) {
        $collectPerTicket = !empty($payload['collect_per_ticket']) || !empty($payload['collect_attendee_questions']);
        $node->set('field_collect_per_ticket', $collectPerTicket);
      }
      elseif (!in_array($event_type, ['paid', 'rsvp'], TRUE)) {
        $node->set('field_collect_per_ticket', FALSE);
      }
    }

    if ($node->hasField('field_product_target')) {
      if ($event_type === 'paid') {
        // Preserve field_product_target unless we successfully set a new valid product id.
        // Missing key / empty / zero from POST must NOT clear an existing link: conditional ticket UI,
        // autosave fragments, and multi-step wizard saves often omit the autocomplete while still paid.
        // Switching booking mode away from paid clears in the branch below.
        $has_explicit = array_key_exists('field_product_target', $payload);
        $raw_pid = $has_explicit ? $payload['field_product_target'] : NULL;
        $pid = ($raw_pid !== NULL && $raw_pid !== '' && (is_int($raw_pid) || is_numeric($raw_pid)))
          ? (int) $raw_pid
          : 0;

        if ($pid > 0) {
          $product = $this->entityTypeManager->getStorage('commerce_product')->load($pid);
          if ($product && $product->bundle() === 'ticket') {
            $node->set('field_product_target', ['target_id' => $pid]);
          }
          else {
            $this->logger->warning('Studio save ignored invalid ticket product id @id for nid @nid', [
              '@id' => (string) $pid,
              '@nid' => (string) $node->id(),
            ]);
            if ($node->get('field_product_target')->isEmpty()) {
              $node->set('field_product_target', NULL);
            }
          }
        }
      }
      else {
        $node->set('field_product_target', NULL);
      }
    }

    if (!$draft && $event_type === 'paid' && $node->hasField('field_product_target') && $node->get('field_product_target')->isEmpty()) {
      return ['Paid events need a ticket product. Link one above or open the Advanced ticket manager from Event Studio.'];
    }

    return [];
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function applyBodyPayload(NodeInterface $node, array $payload): void {
    if (!$node->hasField('body') || !array_key_exists('body', $payload)) {
      return;
    }
    $text = trim((string) $payload['body']);
    $format = 'plain_text';
    $definition = $node->getFieldDefinition('body');
    $allowed = $definition->getSetting('allowed_formats');
    if (is_array($allowed) && $allowed !== []) {
      $format = (string) reset($allowed);
    }
    if ($text === '') {
      $node->set('body', NULL);
      return;
    }
    $node->set('body', [
      [
        'value' => $text,
        'format' => $format,
        'summary' => '',
      ],
    ]);
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function applyDiscoveryTaxonomy(NodeInterface $node, array $payload): void {
    if ($node->hasField('field_category') && array_key_exists('field_category', $payload) && is_array($payload['field_category'])) {
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $rows = [];
      foreach ($payload['field_category'] as $tid) {
        $tid = (int) $tid;
        if ($tid < 1) {
          continue;
        }
        $term = $term_storage->load($tid);
        if ($term && $term->bundle() === 'categories') {
          $rows[] = ['target_id' => $tid];
        }
      }
      $node->set('field_category', $rows);
    }

    if ($node->hasField('field_tags') && array_key_exists('field_tags', $payload) && is_array($payload['field_tags'])) {
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $rows = [];
      foreach ($payload['field_tags'] as $tid) {
        $tid = (int) $tid;
        if ($tid < 1) {
          continue;
        }
        $term = $term_storage->load($tid);
        if ($term && $term->bundle() === 'tags') {
          $rows[] = ['target_id' => $tid];
        }
      }
      $node->set('field_tags', $rows);
    }

    if ($node->hasField('field_accessibility') && array_key_exists('field_accessibility', $payload) && is_array($payload['field_accessibility'])) {
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $rows = [];
      foreach ($payload['field_accessibility'] as $tid) {
        $tid = (int) $tid;
        if ($tid < 1) {
          continue;
        }
        $term = $term_storage->load($tid);
        if ($term && $term->bundle() === 'accessibility') {
          $rows[] = ['target_id' => $tid];
        }
      }
      $node->set('field_accessibility', $rows);
    }
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function applyEventIntroPayload(NodeInterface $node, array $payload): void {
    if (!$node->hasField('field_event_intro') || !array_key_exists('field_event_intro', $payload)) {
      return;
    }
    $text = trim((string) $payload['field_event_intro']);
    $definition = $node->getFieldDefinition('field_event_intro');
    $format = 'plain_text';
    $allowed = $definition->getSetting('allowed_formats');
    if (is_array($allowed) && $allowed !== []) {
      $format = (string) reset($allowed);
    }
    if ($text === '') {
      $node->set('field_event_intro', NULL);
      return;
    }
    $node->set('field_event_intro', [
      [
        'value' => $text,
        'format' => $format,
      ],
    ]);
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function applyContactChannelsPayload(NodeInterface $node, array $payload): void {
    if ($node->hasField('field_contact_email') && array_key_exists('field_contact_email', $payload)) {
      $raw = trim((string) $payload['field_contact_email']);
      $node->set('field_contact_email', $raw === '' ? NULL : $raw);
    }
    if ($node->hasField('field_contact_phone') && array_key_exists('field_contact_phone', $payload)) {
      $raw = trim((string) $payload['field_contact_phone']);
      $node->set('field_contact_phone', $raw === '' ? NULL : $raw);
    }
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function applySalesWindowPayload(NodeInterface $node, array $payload): void {
    foreach (['field_sales_start', 'field_sales_end'] as $field_name) {
      if (!$node->hasField($field_name) || !array_key_exists($field_name, $payload)) {
        continue;
      }
      $raw = $payload[$field_name];
      if ($raw === NULL || $raw === '') {
        $node->set($field_name, []);
        continue;
      }
      $node->set($field_name, [['value' => (string) $raw]]);
    }
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function applyAgeRefundPolicyPayload(NodeInterface $node, array $payload): void {
    if ($node->hasField('field_age_policy') && array_key_exists('field_age_policy', $payload)) {
      $v = trim((string) $payload['field_age_policy']);
      $allowed = $this->listStringValueKeys($node->getFieldDefinition('field_age_policy'));
      if ($allowed === []) {
        $this->logger->warning('Studio save: field_age_policy allowed values missing; skipping field_age_policy set (nid @nid).', [
          '@nid' => (string) ($node->id() ?? 'new'),
        ]);
      }
      else {
        if ($v === '' || !in_array($v, $allowed, TRUE)) {
          $v = in_array('all_ages', $allowed, TRUE) ? 'all_ages' : (string) reset($allowed);
        }
        $node->set('field_age_policy', $v);
      }
    }

    if ($node->hasField('field_age_policy_note') && array_key_exists('field_age_policy_note', $payload)) {
      $note = trim((string) $payload['field_age_policy_note']);
      if (strlen($note) > 255) {
        $note = substr($note, 0, 255);
      }
      $node->set('field_age_policy_note', $note === '' ? NULL : $note);
    }

    if ($node->hasField('field_age_restriction') && array_key_exists('field_age_restriction', $payload)) {
      $v = trim((string) $payload['field_age_restriction']);
      if ($v === '') {
        $node->set('field_age_restriction', NULL);
      }
      else {
        $allowed = $this->listStringValueKeys($node->getFieldDefinition('field_age_restriction'));
        $node->set('field_age_restriction', in_array($v, $allowed, TRUE) ? $v : NULL);
      }
    }

    if ($node->hasField('field_refund_policy') && array_key_exists('field_refund_policy', $payload)) {
      $v = trim((string) $payload['field_refund_policy']);
      if ($v === '') {
        $node->set('field_refund_policy', NULL);
      }
      else {
        $allowed = $this->listStringValueKeys($node->getFieldDefinition('field_refund_policy'));
        $node->set('field_refund_policy', in_array($v, $allowed, TRUE) ? $v : NULL);
      }
    }
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function applyAccessibilityTextPayload(NodeInterface $node, array $payload): void {
    foreach (['field_accessibility_contact', 'field_accessibility_directions', 'field_accessibility_entry', 'field_accessibility_parking'] as $field_name) {
      if (!$node->hasField($field_name) || !array_key_exists($field_name, $payload)) {
        continue;
      }
      $text = trim((string) $payload[$field_name]);
      if ($text === '') {
        $node->set($field_name, NULL);
        continue;
      }
      $definition = $node->getFieldDefinition($field_name);
      $format = 'plain_text';
      $allowed = $definition->getSetting('allowed_formats');
      if (is_array($allowed) && $allowed !== []) {
        $format = (string) reset($allowed);
      }
      $node->set($field_name, [
        [
          'value' => $text,
          'format' => $format,
        ],
      ]);
    }
  }

  /**
   * @return list<string>
   */
  private function listStringValueKeys(FieldDefinitionInterface $definition): array {
    if ($definition->getType() !== 'list_string') {
      return [];
    }
    $allowed = $definition->getSetting('allowed_values');
    if (!is_array($allowed)) {
      return [];
    }
    $keys = [];
    foreach ($allowed as $key => $item) {
      if (is_array($item) && isset($item['value'])) {
        $keys[] = (string) $item['value'];
      }
      elseif (!is_array($item) && !is_int($key)) {
        $keys[] = (string) $key;
      }
    }
    return $keys;
  }

  /**
   * @param array<string, mixed> $payload
   *
   * @return list<string>
   */
  private function applyHeroImagePayload(NodeInterface $node, array $payload, bool $draft): array {
    if (!$node->hasField('field_event_image') || !array_key_exists('field_event_image', $payload)) {
      return [];
    }
    $fid = (int) $payload['field_event_image'];
    $alt = trim((string) ($payload['field_event_image_alt'] ?? ''));

    if ($fid < 1) {
      $node->set('field_event_image', []);
      return [];
    }

    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      $this->logger->warning('Studio save: missing file @fid for event image', ['@fid' => (string) $fid]);
      return ['The uploaded image could not be loaded. Try uploading again.'];
    }
    if ($alt === '' && !$draft) {
      return ['Alt text is required for the cover image.'];
    }

    if ($file->isTemporary()) {
      $file->setPermanent();
      $file->save();
    }

    $node->set('field_event_image', [
      [
        'target_id' => $fid,
        'alt' => $alt,
        'title' => '',
      ],
    ]);

    return [];
  }

  private function applyOptionalCoordinates(NodeInterface $node, array $payload): void {
    $lat = $payload['field_location_latitude'] ?? NULL;
    $lng = $payload['field_location_longitude'] ?? NULL;
    if ($node->hasField('field_location_latitude') && $lat !== NULL && $lat !== '') {
      $node->set('field_location_latitude', (string) $lat);
    }
    if ($node->hasField('field_location_longitude') && $lng !== NULL && $lng !== '') {
      $node->set('field_location_longitude', (string) $lng);
    }
  }

  /**
   * Replaces `field_event_highlights` with paragraph entities in submitted order.
   *
   * Reuses existing paragraph entities by delta when possible; removes extras;
   * creates new paragraphs when the list grows. Paragraphs dropped from the
   * field are deleted to avoid orphans (same pattern as other MEL inline
   * paragraph writers).
   *
   * @param array<string, mixed> $payload
   */
  private function syncEventHighlights(NodeInterface $node, array $payload): void {
    if (!$node->hasField('field_event_highlights')) {
      return;
    }
    if (!array_key_exists('event_highlights', $payload) || !is_array($payload['event_highlights'])) {
      return;
    }

    $allowed_icons = $this->eventHighlightHelper->getAllowedIconKeys();
    $normalized = $this->eventHighlightHelper->normalizeHighlights($payload['event_highlights'], $allowed_icons);

    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    $old_entities = array_values($node->get('field_event_highlights')->referencedEntities());
    $refs = [];

    if ($normalized === []) {
      $node->set('field_event_highlights', []);
      foreach ($old_entities as $entity) {
        if ($entity instanceof Paragraph && $entity->bundle() === 'event_highlight') {
          $entity->delete();
        }
      }
      return;
    }

    foreach ($normalized as $i => $item) {
      if (isset($old_entities[$i]) && $old_entities[$i] instanceof Paragraph && $old_entities[$i]->bundle() === 'event_highlight') {
        $paragraph = $old_entities[$i];
      }
      else {
        $paragraph = $paragraph_storage->create(['type' => 'event_highlight']);
      }
      $this->applyHighlightParagraphValues($paragraph, $item['text'], $item['icon']);
      $paragraph->save();
      $refs[] = [
        'target_id' => (int) $paragraph->id(),
        'target_revision_id' => (int) $paragraph->getRevisionId(),
      ];
    }

    for ($j = count($normalized); $j < count($old_entities); $j++) {
      $drop = $old_entities[$j];
      if ($drop instanceof Paragraph && $drop->bundle() === 'event_highlight') {
        $drop->delete();
      }
    }

    $node->set('field_event_highlights', $refs);
  }

  /**
   * Sets highlight text/icon respecting the configured field types on the bundle.
   */
  private function applyHighlightParagraphValues(Paragraph $paragraph, string $text, string $icon): void {
    $text_def = $paragraph->getFieldDefinition('field_highlight_text');
    if ($text_def->getType() === 'text') {
      $format = 'plain_text';
      $allowed = $text_def->getSetting('allowed_formats');
      if (is_array($allowed) && $allowed !== []) {
        $format = (string) reset($allowed);
      }
      $paragraph->set('field_highlight_text', [
        'value' => $text,
        'format' => $format,
      ]);
    }
    elseif ($text_def->getType() === 'string_long') {
      $paragraph->set('field_highlight_text', ['value' => $text]);
    }
    else {
      $paragraph->set('field_highlight_text', $text);
    }

    if ($icon === '') {
      $paragraph->set('field_highlight_icon', NULL);
    }
    else {
      $paragraph->set('field_highlight_icon', $icon);
    }
  }

  /**
   * Persists attendee question templates (field_attendee_questions paragraphs).
   *
   * @param array<string, mixed> $payload
   *
   * @return list<string>
   */
  private function syncAttendeeQuestions(NodeInterface $node, array $payload, AccountInterface $account): array {
    if (!$node->hasField('field_attendee_questions')) {
      return [];
    }

    $event_type = (string) ($payload['field_event_type'] ?? ($node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()
      ? (string) $node->get('field_event_type')->value
      : 'rsvp'));

    if (!in_array($event_type, ['paid', 'rsvp', 'both'], TRUE)) {
      $node->set('field_attendee_questions', []);
      return [];
    }

    if (!array_key_exists('attendee_questions', $payload)) {
      return [];
    }

    $items = $payload['attendee_questions'];
    if (!is_array($items)) {
      return ['Attendee questions data was invalid. Reload and try again.'];
    }
    if (count($items) > self::ATTENDEE_QUESTION_LIMIT) {
      return ['Add at most 5 attendee questions. More questions may reduce bookings.'];
    }

    $field_map = $this->resolveAttendeeQuestionFieldMap();
    if ($field_map === NULL) {
      $this->logger->error('Event Studio: attendee questions save failed — paragraph field map missing for attendee_extra_field.');
      return ['Attendee questions are not available on this site configuration.'];
    }

    $paragraph_bundle = $this->entityTypeManager->getStorage('paragraphs_type')->load('attendee_extra_field');
    if ($paragraph_bundle === NULL) {
      $this->logger->error('Event Studio: attendee questions save failed — paragraph bundle attendee_extra_field missing.');
      return ['Attendee questions could not be saved.'];
    }

    try {
      $node->set('field_attendee_questions', []);
      $references = [];

      foreach ($items as $index => $question) {
        if (!is_array($question)) {
          return [sprintf('Attendee question at index %d was invalid.', (int) $index)];
        }

        $vendor_qid = isset($question['vendor_question_id']) ? (int) $question['vendor_question_id'] : 0;
        if ($vendor_qid > 0) {
          if ($this->questionTemplateCloner === NULL) {
            $this->logger->error('Event Studio: cannot clone vendor_question @id — QuestionTemplateCloner service not injected.', [
              '@id' => (string) $vendor_qid,
            ]);
            return ['Organiser question library is temporarily unavailable. Reload the page or contact support.'];
          }
          $storage = $this->entityTypeManager->getStorage('vendor_question');
          $template = $storage->load($vendor_qid);
          if ($template instanceof VendorQuestionInterface && $this->vendorQuestionAccessible($template, $account)) {
            $paragraph = $this->questionTemplateCloner->cloneToParagraph($template);
            $references[] = [
              'target_id' => (int) $paragraph->id(),
              'target_revision_id' => (int) $paragraph->getRevisionId(),
            ];
          }
          continue;
        }

        $label = trim((string) ($question['label'] ?? ''));
        $type = trim((string) ($question['type'] ?? 'textfield'));
        $required = !empty($question['required']);

        if ($label === '') {
          return [sprintf('Each attendee question needs a label (row %d).', (int) $index + 1)];
        }

        $paragraph = Paragraph::create([
          'type' => 'attendee_extra_field',
        ]);
        $paragraph->set($field_map['label'], $label);
        $paragraph->set($field_map['type'], $this->normalizeAttendeeQuestionTypeValue($type));
        $paragraph->set($field_map['required'], $required ? 1 : 0);

        $normalized_type = $this->normalizeAttendeeQuestionTypeValue($type);
        if ($paragraph->hasField('field_question_options')) {
          $needs_opts = in_array($normalized_type, ['select', 'checkboxes', 'radios'], TRUE);
          if ($needs_opts) {
            $lines = [];
            if (isset($question['options']) && is_array($question['options'])) {
              foreach ($question['options'] as $opt_line) {
                $t = trim((string) $opt_line);
                if ($t !== '') {
                  $lines[] = $t;
                }
              }
            }
            if ($lines === []) {
              return [sprintf('Add at least one choice for question %d.', (int) $index + 1)];
            }
            $paragraph->set('field_question_options', ['value' => implode("\n", $lines)]);
          }
          else {
            $paragraph->set('field_question_options', NULL);
          }
        }

        if ($paragraph->hasField('field_question_machine_name')) {
          $machine = trim((string) ($question['machine_name'] ?? ''));
          if ($machine === '') {
            $machine = $this->machineNameFromLabel($label);
          }
          $paragraph->set('field_question_machine_name', $machine);
        }

        if (!empty($question['save_to_library'])) {
          $paragraph->save_to_library = TRUE;
        }

        $paragraph->save();

        $references[] = [
          'target_id' => (int) $paragraph->id(),
          'target_revision_id' => (int) $paragraph->getRevisionId(),
        ];
      }

      $node->set('field_attendee_questions', $references);

      if ($references !== [] && $node->hasField('field_collect_per_ticket')) {
        $node->set('field_collect_per_ticket', TRUE);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio: sync attendee questions failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return ['Could not save attendee questions.'];
    }

    return [];
  }

  /**
   * @return array{label: string, type: string, required: string}|null
   */
  private function resolveAttendeeQuestionFieldMap(): ?array {
    $mapping = [
      'label' => NULL,
      'type' => NULL,
      'required' => NULL,
    ];

    $label_candidates = ['field_label', 'field_question_label'];
    $type_candidates = ['field_type', 'field_question_type'];
    $required_candidates = ['field_required', 'field_question_required'];

    foreach ($label_candidates as $candidate) {
      if ($this->entityTypeManager->getStorage('field_config')->load("paragraph.attendee_extra_field.$candidate")) {
        $mapping['label'] = $candidate;
        break;
      }
    }
    foreach ($type_candidates as $candidate) {
      if ($this->entityTypeManager->getStorage('field_config')->load("paragraph.attendee_extra_field.$candidate")) {
        $mapping['type'] = $candidate;
        break;
      }
    }
    foreach ($required_candidates as $candidate) {
      if ($this->entityTypeManager->getStorage('field_config')->load("paragraph.attendee_extra_field.$candidate")) {
        $mapping['required'] = $candidate;
        break;
      }
    }

    if ($mapping['label'] === NULL || $mapping['type'] === NULL || $mapping['required'] === NULL) {
      return NULL;
    }

    return [
      'label' => $mapping['label'],
      'type' => $mapping['type'],
      'required' => $mapping['required'],
    ];
  }

  private function normalizeAttendeeQuestionTypeValue(string $type): string {
    $type = trim($type);
    return match ($type) {
      'text', 'textfield' => 'textfield',
      'textarea' => 'textarea',
      'select' => 'select',
      'checkbox' => 'checkboxes',
      'checkboxes' => 'checkboxes',
      'radio' => 'radios',
      'radios' => 'radios',
      'email' => 'email',
      'tel' => 'tel',
      default => 'textfield',
    };
  }

  private function machineNameFromLabel(string $label): string {
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $label));
    $slug = trim((string) preg_replace('/_+/', '_', $slug), '_');
    return $slug !== '' ? $slug : 'question_' . substr(hash('sha256', $label), 0, 10);
  }

  private function vendorQuestionAccessible(VendorQuestionInterface $question, AccountInterface $account): bool {
    if ($account->hasPermission('administer site configuration')) {
      return TRUE;
    }
    $store = $question->getStore();
    if ($store === NULL) {
      return FALSE;
    }
    $vendors = $this->entityTypeManager->getStorage('myeventlane_vendor')->loadByProperties([
      'uid' => $account->id(),
    ]);
    $vendor = reset($vendors);
    if (!$vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      return FALSE;
    }
    $vendor_store = $vendor->get('field_vendor_store')->entity;
    return $vendor_store && (int) $vendor_store->id() === (int) $store->id();
  }

}
