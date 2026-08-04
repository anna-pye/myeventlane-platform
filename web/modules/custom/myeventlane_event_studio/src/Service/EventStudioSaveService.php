<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Field\WidgetInterface;
use Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\crop\Entity\Crop;
use Drupal\crop\CropInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\focal_point\FocalPointManagerInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;
use Drupal\myeventlane_event\Service\EventPasscodeAccess;
use Drupal\myeventlane_event\Service\PublicEventVisibility;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\myeventlane_event_studio\Service\EventStudioQuestionTemplateManager;
use Drupal\myeventlane_vendor\Service\OrganiserMediaAccess;
use Drupal\myeventlane_venue\Entity\Venue;
use Drupal\myeventlane_venue\Service\VenueManager;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

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

  /**
   * Warn when the saved hero is meaningfully smaller than MEL hero derivatives.
   */
  private const BRANDING_HERO_WARN_WIDTH_LT = 1280;

  private const BRANDING_HERO_WARN_HEIGHT_LT = 720;

  private const BRANDING_HERO_CROP_TYPE = 'event_hero';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VenueManager $venueManager,
    private readonly LoggerInterface $logger,
    private readonly EventHighlightHelper $eventHighlightHelper,
    private readonly PublishEligibilityEvaluator $publishEligibilityEvaluator,
    private readonly QuestionFieldTypeRegistry $fieldTypeRegistry,
    private readonly ImageFactory $imageFactory,
    private readonly TranslationInterface $stringTranslation,
    private readonly FileSystemInterface $fileSystem,
    private readonly FocalPointManagerInterface $focalPointManager,
    private readonly ?OperationalCapabilityStudioManager $operationalCapabilityStudioManager = NULL,
    private readonly ?EventPageStyleResolver $eventPageStyleResolver = NULL,
    private readonly ?EventPasscodeAccess $passcodeAccess = NULL,
    private readonly ?EventStudioQuestionTemplateManager $questionTemplateManager = NULL,
    private readonly ?RequestStack $requestStack = NULL,
    private readonly ?FileRepositoryInterface $fileRepository = NULL,
    private readonly ?OrganiserMediaAccess $organiserMediaAccess = NULL,
    private readonly ?EventCoverMediaManager $eventCoverMediaManager = NULL,
  ) {}

  /**
   * Copies a reusable Media Library image into an event-specific cover file.
   *
   * Event hero crops are keyed by file URI. Copying prevents one event's crop
   * from changing the framing of another event that reused the same media item.
   *
   * @return array{node: ?\Drupal\node\NodeInterface, errors: list<string>}
   */
  public function applyBrandingCoverMedia(NodeInterface $node, MediaInterface $media): array {
    if (
      !$node->hasField('field_event_image')
      || !$node->hasField('field_mel_event_cover_media')
      || !$this->fileRepository instanceof FileRepositoryInterface
    ) {
      return ['node' => NULL, 'errors' => ['Saved image selection is not available.']];
    }
    if ($this->organiserMediaAccess instanceof OrganiserMediaAccess
      && !$this->organiserMediaAccess->canSelect($media)) {
      return ['node' => NULL, 'errors' => ['Choose an image uploaded by your organiser account.']];
    }

    $source_field = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    $source_item = $source_field !== '' && $media->hasField($source_field)
      ? $media->get($source_field)->first()
      : NULL;
    $source_fid = (int) ($source_item?->target_id ?? 0);
    $source_file = $source_fid > 0
      ? $this->entityTypeManager->getStorage('file')->load($source_fid)
      : NULL;
    if (!$source_file instanceof FileInterface || !$this->isHeroFileRenderable($source_file)) {
      return ['node' => NULL, 'errors' => ['The selected Media Library image is missing or unreadable.']];
    }

    $source_image = $this->imageFactory->get($source_file->getFileUri());
    if (!$source_image->isValid() || $source_image->getWidth() < 400 || $source_image->getHeight() < 200) {
      return ['node' => NULL, 'errors' => ['Choose an image that is at least 400×200 pixels.']];
    }

    $directory = 'public://events/' . date('Y-m');
    if (!$this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    )) {
      return ['node' => NULL, 'errors' => ['The event image directory could not be prepared.']];
    }

    try {
      $copied_file = $this->fileRepository->copy(
        $source_file,
        $directory . '/' . basename($source_file->getFileUri()),
        FileSystemInterface::EXISTS_RENAME,
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Branding saved-cover copy failed for event @nid media @media: @message', [
        '@nid' => (string) $node->id(),
        '@media' => (string) $media->id(),
        '@message' => $e->getMessage(),
      ]);
      return ['node' => NULL, 'errors' => ['The selected image could not be copied into this event.']];
    }

    $image = $this->imageFactory->get($copied_file->getFileUri());
    if (!$image->isValid() || $image->getWidth() < 1 || $image->getHeight() < 1) {
      return ['node' => NULL, 'errors' => ['The selected image could not be opened for framing.']];
    }

    $image_width = $image->getWidth();
    $image_height = $image->getHeight();
    if (($image_width / $image_height) >= (16 / 9)) {
      $crop_height = $image_height;
      $crop_width = (int) floor($crop_height * 16 / 9);
    }
    else {
      $crop_width = $image_width;
      $crop_height = (int) floor($crop_width * 9 / 16);
    }

    $crop = Crop::create([
      'type' => self::BRANDING_HERO_CROP_TYPE,
      'entity_id' => $copied_file->id(),
      'entity_type' => 'file',
      'uri' => $copied_file->getFileUri(),
    ]);
    $crop->setPosition((int) floor($image_width / 2), (int) floor($image_height / 2));
    $crop->setSize($crop_width, $crop_height);
    $crop->save();

    $alt = trim((string) ($source_item?->alt ?? ''));
    if ($alt === '') {
      $alt = trim((string) $media->label());
    }
    $previous_fid = $this->brandingHeroFidFromNode($node);
    $node->set('field_event_image', [[
      'target_id' => (int) $copied_file->id(),
      'alt' => $alt,
      'title' => '',
      'width' => $image_width,
      'height' => $image_height,
      'focal_point' => '50,50',
    ]]);
    $node->set('field_mel_event_cover_media', [['target_id' => (int) $media->id()]]);

    EventNodeRevisionSave::prepare($node, 'Event Studio Media Library cover selected.');
    try {
      $node->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Branding saved-cover event save failed for event @nid media @media: @message', [
        '@nid' => (string) $node->id(),
        '@media' => (string) $media->id(),
        '@message' => $e->getMessage(),
      ]);
      return ['node' => NULL, 'errors' => ['The selected image could not be saved to this event.']];
    }

    $this->flushBrandingHeroImageStylesAfterSave($copied_file, $previous_fid, (string) $node->id());
    return ['node' => $node, 'errors' => []];
  }

  /**
   * @param array<string, mixed> $payload
   *   Normalised values from form or request.
   * @param \Drupal\node\NodeInterface|null $node
   *   Existing event or NULL to create.
   *
   * @return array{node: ?\Drupal\node\NodeInterface, errors: list<string>}
   */
  public function save(array $payload, ?NodeInterface $node, AccountInterface $account, bool $draft = FALSE): array {
    $payload = $this->resolvePayloadTitle($payload, $node);

    $willPublish = FALSE;
    if (!$draft) {
      if ($node === NULL) {
        $willPublish = (bool) ($payload['status'] ?? FALSE);
      }
      else {
        $willPublish = (bool) ($payload['status'] ?? TRUE);
      }
    }

    $storage = $this->entityTypeManager->getStorage('node');
    if ($node === NULL) {
      $node = $storage->create([
        'type' => 'event',
        'title' => trim((string) ($payload['title'] ?? '')) ?: 'Untitled event',
        'uid' => (int) $account->id(),
        'status' => $draft ? 0 : ($willPublish ? 1 : 0),
      ]);
    }
    else {
      if ($node->bundle() !== 'event') {
        return ['node' => NULL, 'errors' => ['Invalid event.']];
      }
      $node->setTitle((string) ($payload['title'] ?? $node->label()));
      if (!$draft) {
        $node->setPublished((bool) ($payload['status'] ?? TRUE));
      }
    }

    // Keep Content Moderation aligned with the requested published flag.
    // Unpublishing a live event returns organisers to draft so update access and
    // Event Studio remain available (archived blocks vendor edit transitions).
    if (!$draft && $node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()) {
      $moderationState = (string) $node->get('moderation_state')->value;
      if ($willPublish && $moderationState !== 'published') {
        $node->set('moderation_state', 'published');
      }
      if (!$willPublish && $moderationState === 'published') {
        $node->set('moderation_state', 'draft');
      }
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
      $submitted_event_type = trim((string) $payload['field_event_type']);
      if ($submitted_event_type !== '') {
        $node->set('field_event_type', $submitted_event_type);
      }
    }

    $event_type = trim((string) ($payload['field_event_type'] ?? ''));
    if ($event_type === '' && $node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()) {
      $event_type = (string) $node->get('field_event_type')->value;
    }
    if ($event_type === '') {
      $event_type = 'rsvp';
    }

    $ticket_errors = $this->applyTicketPayload($node, $payload, $event_type, $draft);
    if ($ticket_errors !== []) {
      return $this->abortSectionScopedSave($ticket_errors, $payload);
    }

    $this->applyDonationPayload($node, $payload);

    $choice = (string) ($payload['venue_choice'] ?? 'one_off');
    $location_values = NULL;
    $venue_for_display = NULL;
    $create_venue_name = NULL;
    $address_row_for_display = NULL;

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
        return $this->abortSectionScopedSave(['Select a saved venue.'], $payload);
      }
      $venue_for_display = $this->entityTypeManager->getStorage('myeventlane_venue')->load($vid);
      if (!$venue_for_display instanceof Venue) {
        return $this->abortSectionScopedSave(['Venue not found.'], $payload);
      }
      $node->set('field_venue', ['target_id' => $vid]);
      $primary = $this->venueManager->getPrimaryLocation($venue_for_display);
      $location_values = $primary ? [$this->addressFromVenueLocation($primary)] : [];
      $address_row_for_display = $location_values[0] ?? NULL;
    }
    elseif ($choice === 'create') {
      $create_venue_name = trim((string) ($payload['new_venue_name'] ?? ''));
      if ($create_venue_name === '') {
        return $this->abortSectionScopedSave(['Venue name is required.'], $payload);
      }
      $row = $this->normalizeAddressRow($payload['field_location'] ?? []);
      if ($row === NULL) {
        return $this->abortSectionScopedSave(['Enter an address for the new venue.'], $payload);
      }
      try {
        $venue_for_display = $this->venueManager->createVenueWithLocation(
          ['name' => $create_venue_name, 'visibility' => Venue::VISIBILITY_SHARED, 'description' => ''],
          [
            'title' => $create_venue_name,
            'address_text' => $this->formatAddressText($row),
            'lat' => $payload['field_location_latitude'] ?? NULL,
            'lng' => $payload['field_location_longitude'] ?? NULL,
          ],
          (int) $account->id()
        );
      }
      catch (\Throwable $e) {
        $this->logger->error('Studio venue create failed: @m', ['@m' => $e->getMessage()]);
        return $this->abortSectionScopedSave(['Could not create venue.'], $payload);
      }
      $node->set('field_venue', ['target_id' => $venue_for_display->id()]);
      $location_values = [$row];
      $address_row_for_display = $row;
    }
    else {
      if ($node->hasField('field_venue')) {
        $node->set('field_venue', NULL);
      }
      $row = $this->normalizeAddressRow($payload['field_location'] ?? []);
      if (!$draft && $row === NULL) {
        return $this->abortSectionScopedSave(['Location is required.'], $payload);
      }
      $location_values = $row !== NULL ? [$row] : [];
      $address_row_for_display = $row;
    }

    if ($node->hasField('field_location') && $location_values !== NULL) {
      $node->set('field_location', $location_values);
    }

    if (!$skip_venue_location) {
      $this->applyVenueDisplayName($node, $choice, $address_row_for_display, $venue_for_display, $create_venue_name);
    }

    $this->applyOptionalCoordinates($node, $payload);

    $cover_media_sync = NULL;
    if ($this->shouldApplyHeroImagePayload($payload)) {
      $previous_hero_fid = $this->brandingHeroFidFromNode($node);
      $previous_cover_media_id = $node->hasField('field_mel_event_cover_media')
        ? (int) ($node->get('field_mel_event_cover_media')->target_id ?? 0)
        : 0;
      $image_errors = $this->applyHeroImagePayload($node, $payload, $draft);
      if ($image_errors !== []) {
        return $this->abortSectionScopedSave($image_errors, $payload);
      }
      $cover_media_sync = [$previous_hero_fid, $previous_cover_media_id];
    }

    if (array_key_exists('event_highlights_items_state', $payload)) {
      $highlight_errors = $this->eventHighlightHelper->validateHighlightItemsStateJson((string) ($payload['event_highlights_items_state'] ?? ''));
      if ($highlight_errors !== []) {
        return $this->abortSectionScopedSave($highlight_errors, $payload);
      }
    }

    try {
      $this->syncEventHighlights($node, $payload);
    }
    catch (\Throwable $e) {
      $this->logger->error('Studio event highlights sync failed: @m', ['@m' => $e->getMessage()]);
      return $this->abortSectionScopedSave(['Could not save event highlights.'], $payload);
    }

    $attendee_errors = $this->syncAttendeeQuestions($node, $payload, $account);
    if ($attendee_errors !== []) {
      return $this->abortSectionScopedSave($attendee_errors, $payload);
    }

    $visibility_errors = $this->applyVisibilityPayload($node, $payload);
    if ($visibility_errors !== []) {
      return $this->abortSectionScopedSave($visibility_errors, $payload);
    }

    $capability_errors = $this->applyOperationalCapabilitiesPayload($node, $payload);
    if ($capability_errors !== []) {
      return $this->abortSectionScopedSave($capability_errors, $payload);
    }

    if (!$draft && $willPublish) {
      $eligibility = $this->publishEligibilityEvaluator->evaluate($node, $account);
      if (!$eligibility['allowed']) {
        return $this->abortSectionScopedSave($eligibility['messages'], $payload);
      }
    }

    if ($cover_media_sync !== NULL) {
      $cover_media_errors = $this->captureDirectCoverMedia(
        $node,
        $cover_media_sync[0],
        $cover_media_sync[1],
      );
      if ($cover_media_errors !== []) {
        return $this->abortSectionScopedSave($cover_media_errors, $payload);
      }
    }

    EventNodeRevisionSave::prepare($node, $draft ? 'Event Studio draft.' : 'Event Studio save.');
    try {
      $node->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Studio event save failed: @m', ['@m' => $e->getMessage()]);
      return $this->abortSectionScopedSave(['Save failed.'], $payload);
    }

    return ['node' => $node, 'errors' => []];
  }

  /**
   * Persists RSVP donation configuration fields only (no full wizard save).
   *
   * Used by Event Studio operational ticket save so donation settings do not run
   * attendee questions, venue, or title handling from save().
   *
   * @param array<string, mixed> $payload
   *   enable_donations|donation_enabled, donation_amount, donation_options,
   *   donation_label.
   *
   * @return array{node: ?\Drupal\node\NodeInterface, errors: list<string>}
   */
  public function saveDonationSettings(NodeInterface $node, array $payload, AccountInterface $account): array {
    if ($node->bundle() !== 'event') {
      return ['node' => NULL, 'errors' => ['Invalid event.']];
    }

    $nid = (int) $node->id();
    if ($nid < 1) {
      return ['node' => NULL, 'errors' => ['Invalid event.']];
    }

    $loaded = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$loaded instanceof NodeInterface || $loaded->bundle() !== 'event') {
      return ['node' => NULL, 'errors' => ['Invalid event.']];
    }

    $this->applyDonationPayload($loaded, $payload);

    try {
      EventNodeRevisionSave::prepare($loaded, 'Event Studio donation settings save.');
      if ($loaded->getEntityType()->isRevisionable()) {
        $loaded->setRevisionUserId((int) $account->id());
      }
      $loaded->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio donation settings save failed for event @nid: @message', [
        '@nid' => (string) $nid,
        '@message' => $e->getMessage(),
      ]);
      return ['node' => NULL, 'errors' => ['Donation settings could not be saved. Try again.']];
    }

    return ['node' => $loaded, 'errors' => []];
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function applyDonationPayload(NodeInterface $node, array $payload): void {
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
      $eligibility = $this->publishEligibilityEvaluator->evaluate($node, $account);
      if (!$eligibility['allowed']) {
        throw new \InvalidArgumentException(implode(' ', $eligibility['messages']));
      }
    }
    $node->setPublished($published);
    if ($node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()) {
      $moderationState = (string) $node->get('moderation_state')->value;
      if ($published && $moderationState !== 'published') {
        $node->set('moderation_state', 'published');
      }
      if (!$published && $moderationState === 'published') {
        $node->set('moderation_state', 'draft');
      }
    }
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
   * Writes field_venue_name for hero/card location summaries.
   *
   * One-off events have no venue entity; reuse the saved address row so public
   * heroes and cards can show a location line without a separate venue profile.
   * Clears the field when location processing yields no display label.
   */
  private function applyVenueDisplayName(
    NodeInterface $node,
    string $choice,
    ?array $address_row,
    ?Venue $venue = NULL,
    ?string $create_name = NULL,
  ): void {
    if (!$node->hasField('field_venue_name')) {
      return;
    }

    $display_name = '';
    if ($choice === 'saved' && $venue instanceof Venue) {
      $display_name = trim((string) $venue->label());
    }
    elseif ($choice === 'create' && $create_name !== NULL) {
      $display_name = trim($create_name);
    }
    elseif ($choice === 'one_off' && is_array($address_row)) {
      $display_name = $this->deriveVenueDisplayNameFromAddressRow($address_row);
    }

    if ($display_name === '') {
      $node->set('field_venue_name', NULL);
      return;
    }

    $node->set('field_venue_name', $display_name);
  }

  /**
   * @param array<string, string> $address_row
   */
  private function deriveVenueDisplayNameFromAddressRow(array $address_row): string {
    $locality = trim((string) ($address_row['locality'] ?? ''));
    $administrative_area = trim((string) ($address_row['administrative_area'] ?? ''));
    if ($locality !== '') {
      return $administrative_area !== '' ? $locality . ', ' . $administrative_area : $locality;
    }

    return trim((string) ($address_row['address_line1'] ?? ''));
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
      if (in_array($event_type, ['paid', 'rsvp', 'both'], TRUE) && (array_key_exists('collect_per_ticket', $payload) || array_key_exists('collect_attendee_questions', $payload))) {
        $collectPerTicket = !empty($payload['collect_per_ticket']) || !empty($payload['collect_attendee_questions']);
        $node->set('field_collect_per_ticket', $collectPerTicket);
      }
      elseif (!in_array($event_type, ['paid', 'rsvp', 'both'], TRUE)) {
        $node->set('field_collect_per_ticket', FALSE);
      }
    }

    if ($node->hasField('field_product_target')) {
      if (in_array($event_type, ['paid', 'both'], TRUE)) {
        // Preserve field_product_target unless we successfully set a new valid product id.
        // Missing key / empty / zero from POST must NOT clear an existing link: conditional ticket UI,
        // autosave fragments, and multi-step wizard saves often omit the autocomplete while still paid.
        // Switching booking mode away from paid/both clears in the branch below.
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

    if (!$draft && in_array($event_type, ['paid', 'both'], TRUE) && $node->hasField('field_product_target') && $node->get('field_product_target')->isEmpty()) {
      return ['Paid events need a ticket. Link one above or open Advanced ticket tools from Event Studio.'];
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

    if ($node->hasField('field_social_proof_visibility') && array_key_exists('field_social_proof_visibility', $payload)) {
      $v = trim((string) $payload['field_social_proof_visibility']);
      $allowed = $this->listStringValueKeys($node->getFieldDefinition('field_social_proof_visibility'));
      if ($allowed === []) {
        $this->logger->warning('Studio save: field_social_proof_visibility allowed values missing; skipping set (nid @nid).', [
          '@nid' => (string) ($node->id() ?? 'new'),
        ]);
      }
      else {
        if ($v === '' || !in_array($v, $allowed, TRUE)) {
          $v = in_array('auto', $allowed, TRUE) ? 'auto' : (string) reset($allowed);
        }
        $node->set('field_social_proof_visibility', $v);
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
   * Sets field_event_visibility and manages field_event_passcode_hash.
   *
   * @param array<string, mixed> $payload
   *
   * @return list<string>
   */
  private function applyVisibilityPayload(NodeInterface $node, array $payload): array {
    if (!array_key_exists('field_event_visibility', $payload)) {
      return [];
    }

    $visibility = trim((string) $payload['field_event_visibility']);
    if ($visibility === '') {
      return [];
    }

    $visibility = PublicEventVisibility::normalizeVisibilityValue($visibility);

    if ($node->hasField('field_event_visibility')) {
      $node->set('field_event_visibility', $visibility);
    }

    if (!$node->hasField('field_event_passcode_hash')) {
      return [];
    }

    if ($visibility === PublicEventVisibility::VISIBILITY_PASSCODE) {
      $new_passcode = trim((string) ($payload['event_passcode'] ?? ''));
      if ($new_passcode !== '') {
        if ($this->passcodeAccess === NULL) {
          $this->logger->error('Event Studio: cannot hash passcode — EventPasscodeAccess service not injected for event @nid.', [
            '@nid' => (string) ($node->id() ?? 'new'),
          ]);
          return ['Passcode service is temporarily unavailable.'];
        }
        $node->set('field_event_passcode_hash', $this->passcodeAccess->hashPasscode($new_passcode));
      }
    }
    else {
      $node->set('field_event_passcode_hash', NULL);
    }

    return [];
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
   * Whether the event hero references a file record that is missing on disk.
   */
  public function isBrokenHeroImageReference(NodeInterface $node): bool {
    if (!$node->hasField('field_event_image') || $node->get('field_event_image')->isEmpty()) {
      return FALSE;
    }
    $file = $node->get('field_event_image')->entity;
    if (!$file instanceof FileInterface) {
      return TRUE;
    }
    return !$this->isHeroFileRenderable($file);
  }

  /**
   * Persists branding hero image via the studio_branding field widget.
   *
   * Uses widget extraction so crop / focal point values from the image widget
   * reach the entity before save.
   *
   * @param array<string, mixed> $mel_subform
   *   The `mel` form fragment containing `field_event_image`.
   *
   * @return array{node: ?\Drupal\node\NodeInterface, errors: list<string>, warnings: list<string>}
   */
  public function saveBrandingHero(NodeInterface $node, array $mel_subform, FormStateInterface $form_state, bool $draft = FALSE): array {
    if (!$node->hasField('field_event_image')) {
      return ['node' => $node, 'errors' => [], 'warnings' => []];
    }

    $display = $this->entityTypeManager->getStorage('entity_form_display')->load('node.event.studio_branding');
    if (!$display instanceof EntityFormDisplay) {
      $this->logger->error('Branding save: missing form display node.event.studio_branding for node @nid.', ['@nid' => (string) $node->id()]);
      return ['node' => NULL, 'errors' => ['Hero image editor is not available.'], 'warnings' => []];
    }

    $mel_structure = $form_state->getCompleteForm()['mel'] ?? $mel_subform;
    if (!is_array($mel_structure) || !isset($mel_structure['field_event_image'])) {
      return ['node' => NULL, 'errors' => ['Hero image field is missing from the form.'], 'warnings' => []];
    }

    $widget = $display->getRenderer('field_event_image');
    if (!$widget instanceof WidgetInterface) {
      $this->logger->error('Branding save: missing field_event_image widget for node @nid.', ['@nid' => (string) $node->id()]);
      return ['node' => NULL, 'errors' => ['Hero image widget is not configured.'], 'warnings' => []];
    }

    $previous_hero_fid = $this->brandingHeroFidFromNode($node);

    $this->syncBrandingHeroSubmittedValues($form_state);

    $mel_values = $form_state->getValue('mel') ?? [];
    if (!is_array($mel_values)) {
      $mel_values = [];
    }

    $user_input = $form_state->getUserInput();
    $input_fragment = is_array($user_input['mel'] ?? NULL)
      ? ($user_input['mel']['field_event_image'] ?? NULL)
      : NULL;
    $values_fragment = $mel_values['field_event_image'] ?? NULL;
    $request_fragment = $this->brandingHeroMelFieldFragmentFromRequest();
    $synced_hero_fid = EventStudioMelPayloadService::normalizeHeroFromMelFragment($mel_values)['fid'];
    $input_fid = is_array($input_fragment) ? $this->brandingHeroFidFromMelFieldFragment($input_fragment) : 0;
    $request_fid = is_array($request_fragment) ? $this->brandingHeroFidFromMelFieldFragment($request_fragment) : 0;
    $this->logger->info('Branding hero save diagnostic for node @nid: existing_target=@existing input_fid=@input_fid request_fid=@request_fid values_fid=@values_fid resolved_fid=@resolved input=@input values=@values request=@request', [
      '@nid' => (string) $node->id(),
      '@existing' => (string) $previous_hero_fid,
      '@input_fid' => (string) $input_fid,
      '@request_fid' => (string) $request_fid,
      '@values_fid' => (string) (is_array($values_fragment) ? $this->brandingHeroFidFromMelFieldFragment($values_fragment) : 0),
      '@resolved' => (string) $synced_hero_fid,
      '@input' => $this->brandingHeroSyncSnapshot($input_fragment),
      '@values' => $this->brandingHeroSyncSnapshot($values_fragment),
      '@request' => $this->brandingHeroSyncSnapshot($request_fragment),
    ]);

    $mel_structure = $this->normalizeBrandingMelFormStructure(
      is_array($mel_structure) ? $mel_structure : [],
    );

    $items = $node->get('field_event_image');
    try {
      $widget->extractFormValues($items, $mel_structure, $form_state);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Branding save: extractFormValues failed for node @nid, using mel fragment fallback: @message', [
        '@nid' => (string) $node->id(),
        '@message' => $e->getMessage(),
      ]);
    }

    $mel_values = $form_state->getValue('mel') ?? [];
    if (!is_array($mel_values)) {
      $mel_values = [];
    }

    $synced_hero_fid_after_extract = EventStudioMelPayloadService::normalizeHeroFromMelFragment($mel_values)['fid'];
    if ($synced_hero_fid_after_extract > 0 && $synced_hero_fid_after_extract !== $previous_hero_fid) {
      $replacement = $this->entityTypeManager->getStorage('file')->load($synced_hero_fid_after_extract);
      if ($replacement instanceof FileInterface) {
        $this->pruneInvalidEventHeroCrop($replacement, (string) $node->id());
      }
    }

    $fid = 0;
    $alt = '';
    if (!$items->isEmpty()) {
      $value = $items->first()?->getValue() ?? [];
      $fid = (int) ($value['target_id'] ?? 0);
      $alt = trim((string) ($value['alt'] ?? ''));
    }

    $hero = EventStudioMelPayloadService::normalizeHeroFromMelFragment($mel_values);
    if ($fid < 1) {
      $fid = $hero['fid'];
    }
    if ($fid < 1) {
      $fid = $this->resolveBrandingHeroFidFromFormState($form_state);
    }
    if ($alt === '' && $hero['alt'] !== '') {
      $alt = $hero['alt'];
    }
    if ($alt === '' && is_array($user_input['mel'] ?? NULL)) {
      $alt = trim((string) ($user_input['mel']['field_event_image_alt'] ?? ''));
    }

    $warnings = [];
    $saved_hero_file = NULL;
    $hero_file_status_before = NULL;

    if ($fid < 1) {
      $input_fid = is_array($input_fragment)
        ? $this->brandingHeroFidFromMelFieldFragment($input_fragment)
        : 0;
      if ($input_fid < 1) {
        $input_fid = $request_fid;
      }
      if ($input_fid > 0) {
        return [
          'node' => NULL,
          'errors' => [$this->brandingHeroUnsavedUploadErrorMessage()],
          'warnings' => [],
        ];
      }
      if ($previous_hero_fid > 0 && !$this->brandingHeroExplicitRemovalRequested($input_fragment)) {
        $this->applyBrandingHeroAltToExistingNode($node, $mel_values);
      }
      else {
        $node->set('field_event_image', []);
      }
    }
    else {
      if ($alt === '' && !$draft) {
        return ['node' => NULL, 'errors' => ['Alt text is required for the cover image.'], 'warnings' => []];
      }
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if (!$file instanceof FileInterface) {
        $this->logger->warning('Branding save: missing hero file @fid on node @nid.', [
          '@fid' => (string) $fid,
          '@nid' => (string) $node->id(),
        ]);
        return ['node' => NULL, 'errors' => [$this->brandingHeroUnsavedUploadErrorMessage()], 'warnings' => []];
      }
      $hero_file_status_before = (int) $file->isPermanent();
      if (!$this->isHeroFileRenderable($file)) {
        $this->logger->warning('Branding save: hero file @fid is not renderable for node @nid.', [
          '@fid' => (string) $fid,
          '@nid' => (string) $node->id(),
        ]);
        return ['node' => NULL, 'errors' => [$this->brokenHeroImageErrorMessage()], 'warnings' => []];
      }
      else {
        if ($file->isTemporary()) {
          $file->setPermanent();
          $file->save();
        }
        $values = $items->isEmpty() ? [] : $items->getValue();
        $row = [];
        if ($values !== [] && isset($values[0]) && is_array($values[0])) {
          $row = $values[0];
        }
        else {
          $row = [
            'target_id' => $fid,
            'alt' => $alt,
            'title' => '',
          ];
        }
        $from_mel = EventStudioMelPayloadService::buildHeroFieldItemFromMelFragment($mel_values);
        if ($from_mel !== NULL) {
          foreach (['alt', 'focal_point', 'width', 'height', 'title'] as $key) {
            if (!array_key_exists($key, $from_mel)) {
              continue;
            }
            $candidate = $from_mel[$key];
            if ($candidate !== '' && $candidate !== NULL) {
              $row[$key] = $candidate;
            }
          }
        }
        elseif ($alt !== '') {
          $row['alt'] = $alt;
        }
        $node->set('field_event_image', [
          $this->enrichBrandingHeroFieldItem($row, $file),
        ]);
        $warnings = $this->buildBrandingHeroDimensionWarnings($file);
        $saved_hero_file = $file;
      }
    }

    $gallery_errors = $this->saveBrandingGalleryField($node, $mel_structure, $form_state);
    if ($gallery_errors !== []) {
      return ['node' => NULL, 'errors' => $gallery_errors, 'warnings' => []];
    }

    $style_errors = $this->applyBrandingPageStyleFields(
      $node,
      $this->resolveBrandingStyleMelForPersistence($mel_subform, $form_state),
    );
    if ($style_errors !== []) {
      return ['node' => NULL, 'errors' => $style_errors, 'warnings' => []];
    }

    $cover_media_errors = $this->saveBrandingCoverMediaField(
      $node,
      $mel_structure,
      $form_state,
      $this->brandingHeroFidFromNode($node),
      $previous_hero_fid,
    );
    if ($cover_media_errors !== []) {
      return ['node' => NULL, 'errors' => $cover_media_errors, 'warnings' => []];
    }

    $final_target_id = $node->hasField('field_event_image') && !$node->get('field_event_image')->isEmpty()
      ? (int) ($node->get('field_event_image')->target_id ?? 0)
      : 0;
    $hero_file_status_after = $saved_hero_file instanceof FileInterface ? (int) $saved_hero_file->isPermanent() : NULL;
    $this->logger->info('Branding hero save assignment for node @nid: resolved_fid=@resolved final_target=@final file_status_before=@before file_status_after=@after', [
      '@nid' => (string) $node->id(),
      '@resolved' => (string) $fid,
      '@final' => (string) $final_target_id,
      '@before' => $hero_file_status_before === NULL ? 'n/a' : (string) $hero_file_status_before,
      '@after' => $hero_file_status_after === NULL ? 'n/a' : (string) $hero_file_status_after,
    ]);

    EventNodeRevisionSave::prepare($node, $draft ? 'Event Studio branding draft.' : 'Event Studio branding save.');
    try {
      $node->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Branding hero save failed for node @nid class @class file @file line @line: @message', [
        '@nid' => (string) $node->id(),
        '@class' => $e::class,
        '@file' => $e->getFile(),
        '@line' => (string) $e->getLine(),
        '@message' => $e->getMessage(),
      ]);
      return ['node' => NULL, 'errors' => ['Could not save branding.'], 'warnings' => []];
    }

    $this->flushBrandingHeroImageStylesAfterSave($saved_hero_file, $previous_hero_fid, (string) $node->id());

    return ['node' => $node, 'errors' => [], 'warnings' => $warnings];
  }

  /**
   * Persists Media Library provenance for selected and directly uploaded covers.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event being saved.
   * @param array<string, mixed> $mel_structure
   *   The Event Studio form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The submitted form state.
   * @param int $saved_hero_fid
   *   The cover file ID currently assigned to the event.
   * @param int $previous_hero_fid
   *   The cover file ID assigned before this save.
   *
   * @return list<string>
   *   User-facing validation errors.
   */
  private function saveBrandingCoverMediaField(
    NodeInterface $node,
    array $mel_structure,
    FormStateInterface $form_state,
    int $saved_hero_fid,
    int $previous_hero_fid,
  ): array {
    if (!$node->hasField('field_mel_event_cover_media')) {
      return [];
    }

    $previous_cover_media_id = (int) ($node->get('field_mel_event_cover_media')->target_id ?? 0);

    try {
      $matching_value = [];
      $display = $this->entityTypeManager->getStorage('entity_form_display')->load('node.event.studio_branding');
      $widget = $display instanceof EntityFormDisplay
        ? $display->getRenderer('field_mel_event_cover_media')
        : NULL;
      if ($widget instanceof WidgetInterface && isset($mel_structure['field_mel_event_cover_media'])) {
        $mel_form = $this->normalizeBrandingMelFormStructure($mel_structure);
        $items = $node->get('field_mel_event_cover_media');
        $widget->extractFormValues($items, $mel_form, $form_state);

        foreach ($items->referencedEntities() as $media) {
          if (!$media instanceof MediaInterface) {
            continue;
          }
          $media_fid = $this->eventCoverMediaManager instanceof EventCoverMediaManager
            ? $this->eventCoverMediaManager->sourceFileId($media)
            : 0;
          $already_applied_media = $previous_cover_media_id === (int) $media->id()
            && $saved_hero_fid > 0
            && $saved_hero_fid === $previous_hero_fid;
          if (($saved_hero_fid > 0 && $media_fid === $saved_hero_fid) || $already_applied_media) {
            $matching_value = [['target_id' => (int) $media->id()]];
            break;
          }
        }
      }

      if ($saved_hero_fid < 1) {
        $node->set('field_mel_event_cover_media', []);
        return [];
      }
      if ($matching_value !== []) {
        $node->set('field_mel_event_cover_media', $matching_value);
        return [];
      }
      if ($saved_hero_fid === $previous_hero_fid && $previous_cover_media_id > 0) {
        $node->set('field_mel_event_cover_media', [['target_id' => $previous_cover_media_id]]);
        return [];
      }

      return $this->captureDirectCoverMedia($node, $previous_hero_fid, $previous_cover_media_id);
    }
    catch (\Throwable $e) {
      $this->logger->error('Branding saved-cover media persistence failed for node @nid: @message', [
        '@nid' => (string) $node->id(),
        '@message' => $e->getMessage(),
      ]);
      return ['Could not save the selected Media Library image.'];
    }

    return [];
  }

  /**
   * Captures a changed direct upload while preserving selected-media provenance.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event being saved.
   * @param int $previous_hero_fid
   *   The cover file ID assigned before this save.
   * @param int $previous_cover_media_id
   *   The Media ID assigned before this save.
   *
   * @return list<string>
   *   User-facing validation errors.
   */
  private function captureDirectCoverMedia(
    NodeInterface $node,
    int $previous_hero_fid,
    int $previous_cover_media_id,
  ): array {
    if (!$node->hasField('field_mel_event_cover_media')) {
      return [];
    }
    if (!$node->hasField('field_event_image') || $node->get('field_event_image')->isEmpty()) {
      $node->set('field_mel_event_cover_media', []);
      return [];
    }

    $saved_hero_fid = $this->brandingHeroFidFromNode($node);
    if ($saved_hero_fid === $previous_hero_fid && $previous_cover_media_id > 0) {
      $node->set('field_mel_event_cover_media', [['target_id' => $previous_cover_media_id]]);
      return [];
    }
    if (!$this->eventCoverMediaManager instanceof EventCoverMediaManager) {
      $this->logger->error('Event cover Media capture service is unavailable for node @nid.', [
        '@nid' => (string) $node->id(),
      ]);
      return ['The cover image could not be added to Media Library. Try again.'];
    }

    try {
      $capture = $this->eventCoverMediaManager->capture($node);
      $node->set('field_mel_event_cover_media', [[
        'target_id' => (int) $capture['media']->id(),
      ]]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event cover Media capture failed for node @nid: @message', [
        '@nid' => (string) $node->id(),
        '@message' => $e->getMessage(),
      ]);
      return ['The cover image could not be added to Media Library. Try again.'];
    }

    return [];
  }

  /**
   * Persists optional gallery media from the branding form widget.
   *
   * @param array<string, mixed> $mel_structure
   *
   * @return list<string>
   */
  private function saveBrandingGalleryField(NodeInterface $node, array $mel_structure, FormStateInterface $form_state): array {
    if (!$node->hasField('field_mel_event_gallery')) {
      return [];
    }

    $display = $this->entityTypeManager->getStorage('entity_form_display')->load('node.event.studio_branding');
    if (!$display instanceof EntityFormDisplay) {
      return [];
    }

    $widget = $display->getRenderer('field_mel_event_gallery');
    if ($widget === NULL) {
      return [];
    }

    if (!isset($mel_structure['field_mel_event_gallery'])) {
      return [];
    }

    $mel_form = $this->normalizeBrandingMelFormStructure($mel_structure);
    $before_ids = $this->brandingGalleryMediaIdsFromField($node->get('field_mel_event_gallery')->getValue());

    try {
      $this->syncBrandingGallerySubmittedValues($form_state);

      $items = $node->get('field_mel_event_gallery');
      $submitted_ids = $widget instanceof MediaLibraryWidget
        ? $this->extractBrandingGalleryTargetIdsFromSubmittedMel($form_state, $widget, $mel_form)
        : NULL;

      if ($submitted_ids !== NULL) {
        $items->setValue(array_map(
          static fn(int $media_id): array => ['target_id' => $media_id],
          $submitted_ids,
        ));
      }
      else {
        $widget->extractFormValues($items, $mel_form, $form_state);
      }

      $final_value = $items->getValue();
      $final_ids = $this->brandingGalleryMediaIdsFromField($final_value);
      $new_ids = array_values(array_diff($final_ids, $before_ids));
      if ($new_ids !== [] && $this->organiserMediaAccess instanceof OrganiserMediaAccess) {
        $selected_media = $this->entityTypeManager->getStorage('media')->loadMultiple($new_ids);
        foreach ($new_ids as $media_id) {
          $media = $selected_media[$media_id] ?? NULL;
          if (!$media instanceof MediaInterface || !$this->organiserMediaAccess->canSelect($media)) {
            return ['One or more gallery images are not available to your organiser account.'];
          }
        }
      }
      $node->set('field_mel_event_gallery', $final_value);

      $this->logger->info('Branding gallery save for node @nid: before=@before submitted=@submitted final=@final', [
        '@nid' => (string) $node->id(),
        '@before' => implode(',', array_map('strval', $before_ids)) ?: 'none',
        '@submitted' => $submitted_ids === NULL ? 'n/a' : (implode(',', array_map('strval', $submitted_ids)) ?: 'none'),
        '@final' => implode(',', array_map('strval', $final_ids)) ?: 'none',
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Branding gallery save failed for node @nid class @class file @file line @line: @message', [
        '@nid' => (string) $node->id(),
        '@class' => $e::class,
        '@file' => $e->getFile(),
        '@line' => (string) $e->getLine(),
        '@message' => $e->getMessage(),
      ]);
      return ['Could not save event gallery.'];
    }

    return [];
  }

  /**
   * Ensures the mel subform fragment has #parents for field widget extraction.
   *
   * @param array<string, mixed> $mel_structure
   *
   * @return array<string, mixed>
   */
  private function normalizeBrandingMelFormStructure(array $mel_structure): array {
    if (!isset($mel_structure['#parents']) || !is_array($mel_structure['#parents'])) {
      $mel_structure['#parents'] = ['mel'];
    }
    return $mel_structure;
  }

  /**
   * Syncs branding hero widget input before form validation.
   *
   * image_widget_crop AJAX can leave crop_applied only in user input; element
   * validators read form_state values and would falsely reject an applied crop.
   */
  public function prepareBrandingHeroFormStateForValidation(FormStateInterface $form_state): void {
    $this->syncBrandingHeroSubmittedValues($form_state);
  }

  /**
   * Copies hero widget values from raw user input when Form API values are stale.
   *
   * image_widget_crop AJAX handlers can leave fids / crop / focal point in user input
   * only; WidgetBase reads form_state values during extractFormValues().
   */
  private function syncBrandingHeroSubmittedValues(FormStateInterface $form_state): void {
    $user_input = $form_state->getUserInput();
    if (!is_array($user_input)) {
      $user_input = [];
    }
    $mel_input = $user_input['mel'] ?? NULL;
    if (!is_array($mel_input)) {
      $mel_input = [];
    }
    $from_input = $this->brandingHeroMelFieldFragmentFromMelArray($mel_input);
    if ($from_input === NULL) {
      $from_request = $this->brandingHeroMelFieldFragmentFromRequest();
      if ($from_request === NULL) {
        return;
      }
      $from_input = $from_request;
      $mel_input['field_event_image'] = $from_input;
      if (!array_key_exists('field_event_image_alt', $mel_input)) {
        $request_mel = $this->brandingHeroRequestMelFragment();
        if (is_array($request_mel) && array_key_exists('field_event_image_alt', $request_mel)) {
          $mel_input['field_event_image_alt'] = $request_mel['field_event_image_alt'];
        }
      }
      $user_input['mel'] = $mel_input;
      $form_state->setUserInput($user_input);
    }
    elseif ($this->brandingHeroFidFromMelFieldFragment($from_input) < 1) {
      $from_request = $this->brandingHeroMelFieldFragmentFromRequest();
      if ($from_request !== NULL) {
        $from_input = $from_request;
        $mel_input['field_event_image'] = $from_input;
        if (!array_key_exists('field_event_image_alt', $mel_input)) {
          $request_mel = $this->brandingHeroRequestMelFragment();
          if (is_array($request_mel) && array_key_exists('field_event_image_alt', $request_mel)) {
            $mel_input['field_event_image_alt'] = $request_mel['field_event_image_alt'];
          }
        }
        $user_input['mel'] = $mel_input;
        $form_state->setUserInput($user_input);
      }
    }

    $values = $form_state->getValues();
    if (!is_array($values)) {
      return;
    }
    $mel_values = $values['mel'] ?? NULL;
    $current = is_array($mel_values) ? ($mel_values['field_event_image'] ?? NULL) : NULL;
    if (!is_array($current)) {
      $mel_values = is_array($mel_values) ? $mel_values : [];
      $mel_values['field_event_image'] = $from_input;
      $this->applyBrandingHeroAltToMelValues($mel_input, $mel_values);
      $values['mel'] = $mel_values;
      $form_state->setValues($values);
      return;
    }

    $input_fid = $this->brandingHeroFidFromMelFieldFragment($from_input);
    $current_fid = $this->brandingHeroFidFromMelFieldFragment($current);
    if ($input_fid !== $current_fid) {
      $current = $this->stripBrandingHeroCropFromMelFragment($current);
      $merged = $this->mergeBrandingHeroMelFieldFragment($current, $from_input);
      $mel_values['field_event_image'] = $merged;
      $this->applyBrandingHeroAltToMelValues($mel_input, $mel_values);
      $values['mel'] = $mel_values;
      $form_state->setValues($values);
      return;
    }

    if (!$this->brandingHeroMelFragmentHasAuthoritativeInput($current)) {
      $mel_values['field_event_image'] = array_merge($current, $from_input);
      $this->applyBrandingHeroAltToMelValues($mel_input, $mel_values);
      $values['mel'] = $mel_values;
      $form_state->setValues($values);
      return;
    }

    if ($this->brandingHeroMelFragmentNeedsSupplementalInputSync($current, $from_input)) {
      $merged = $this->mergeBrandingHeroMelFieldFragment($current, $from_input);
      $mel_values['field_event_image'] = $merged;
      $this->applyBrandingHeroAltToMelValues($mel_input, $mel_values);
      $values['mel'] = $mel_values;
      $form_state->setValues($values);
    }

    $this->ensureBrandingHeroFidInFormStateValues($form_state);
  }

  /**
   * Ensures mel[field_event_image] form values include the best available fid.
   */
  private function ensureBrandingHeroFidInFormStateValues(FormStateInterface $form_state): void {
    $best_fragment = $this->resolveBestBrandingHeroMelFieldFragment($form_state);
    if ($best_fragment === NULL) {
      return;
    }

    $best_fid = $this->brandingHeroFidFromMelFieldFragment($best_fragment);
    if ($best_fid < 1) {
      return;
    }

    $values = $form_state->getValues();
    if (!is_array($values)) {
      return;
    }

    $mel_values = $values['mel'] ?? NULL;
    if (!is_array($mel_values)) {
      $mel_values = [];
    }

    $current = $mel_values['field_event_image'] ?? NULL;
    $current_fid = is_array($current) ? $this->brandingHeroFidFromMelFieldFragment($current) : 0;
    if ($current_fid === $best_fid) {
      return;
    }

    if (is_array($current)) {
      if ($current_fid > 0 && $best_fid !== $current_fid) {
        $current = $this->stripBrandingHeroCropFromMelFragment($current);
      }
      $mel_values['field_event_image'] = $this->mergeBrandingHeroMelFieldFragment($current, $best_fragment);
    }
    else {
      $mel_values['field_event_image'] = $best_fragment;
    }

    $user_mel = $form_state->getUserInput()['mel'] ?? NULL;
    if (is_array($user_mel)) {
      $this->applyBrandingHeroAltToMelValues($user_mel, $mel_values);
    }

    $values['mel'] = $mel_values;
    $form_state->setValues($values);
  }

  /**
   * @return array<string, mixed>|null
   */
  private function resolveBestBrandingHeroMelFieldFragment(FormStateInterface $form_state): ?array {
    $best_fragment = NULL;
    $best_fid = 0;
    foreach ($this->brandingHeroMelFieldFragmentCandidates($form_state) as $fragment) {
      $fid = $this->brandingHeroFidFromMelFieldFragment($fragment);
      if ($fid > $best_fid) {
        $best_fid = $fid;
        $best_fragment = $fragment;
      }
    }
    return $best_fragment;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function brandingHeroMelFieldFragmentCandidates(FormStateInterface $form_state): array {
    $fragments = [];
    $user_input = $form_state->getUserInput();
    $user_mel = is_array($user_input) ? ($user_input['mel'] ?? NULL) : NULL;
    if (is_array($user_mel)) {
      $user_fragment = $this->brandingHeroMelFieldFragmentFromMelArray($user_mel);
      if (is_array($user_fragment)) {
        $fragments[] = $user_fragment;
      }
    }

    $request_fragment = $this->brandingHeroMelFieldFragmentFromRequest();
    if (is_array($request_fragment)) {
      $fragments[] = $request_fragment;
    }

    $values_mel = $form_state->getValue('mel');
    if (is_array($values_mel)) {
      $values_fragment = $this->brandingHeroMelFieldFragmentFromMelArray($values_mel);
      if (is_array($values_fragment)) {
        $fragments[] = $values_fragment;
      }
    }

    return $fragments;
  }

  /**
   * Resolves the strongest hero fid available from submitted branding sources.
   */
  private function resolveBrandingHeroFidFromFormState(FormStateInterface $form_state): int {
    $best_fragment = $this->resolveBestBrandingHeroMelFieldFragment($form_state);
    if ($best_fragment === NULL) {
      return 0;
    }
    return $this->brandingHeroFidFromMelFieldFragment($best_fragment);
  }

  /**
   * @param array<string, mixed> $mel_input
   * @param array<string, mixed> $mel_values
   */
  private function applyBrandingHeroAltToMelValues(array $mel_input, array &$mel_values): void {
    if (!array_key_exists('field_event_image_alt', $mel_input)) {
      return;
    }
    $alt = trim((string) ($mel_input['field_event_image_alt'] ?? ''));
    if ($alt !== '') {
      $mel_values['field_event_image_alt'] = $alt;
    }
  }

  /**
   * @return array<string, mixed>|null
   */
  private function brandingHeroMelFieldFragmentFromRequest(): ?array {
    $request_mel = $this->brandingHeroRequestMelFragment();
    if (!is_array($request_mel)) {
      return NULL;
    }
    return $this->brandingHeroMelFieldFragmentFromMelArray($request_mel);
  }

  /**
   * Resolves a hero widget fragment from a mel array (direct or wrapper paths).
   *
   * image_widget_crop can leave values under field_event_image_wrapper.widget while
   * POST field names still use mel[field_event_image][…].
   *
   * @param array<string, mixed> $mel
   *
   * @return array<string, mixed>|null
   */
  private function brandingHeroMelFieldFragmentFromMelArray(array $mel): ?array {
    $candidates = [];
    if (isset($mel['field_event_image']) && is_array($mel['field_event_image'])) {
      $candidates[] = $mel['field_event_image'];
    }
    $wrapper = $mel['field_event_image_wrapper'] ?? NULL;
    if (is_array($wrapper)) {
      if (isset($wrapper['widget']) && is_array($wrapper['widget'])) {
        $candidates[] = $wrapper['widget'];
      }
      if (isset($wrapper[0]) && is_array($wrapper[0])) {
        $candidates[] = $wrapper;
      }
    }

    foreach ($candidates as $fragment) {
      if ($this->brandingHeroMelFragmentHasAuthoritativeInput($fragment)) {
        return $fragment;
      }
    }

    return NULL;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function brandingHeroRequestMelFragment(): ?array {
    if (!$this->requestStack instanceof RequestStack) {
      return NULL;
    }
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return NULL;
    }
    $mel = $request->request->all()['mel'] ?? NULL;
    return is_array($mel) ? $mel : NULL;
  }

  /**
   * @param array<string, mixed> $field_fragment
   */
  private function brandingHeroMelFragmentHasAuthoritativeInput(array $field_fragment): bool {
    if ($this->brandingHeroFidFromMelFieldFragment($field_fragment) > 0) {
      return TRUE;
    }
    $delta = EventStudioMelPayloadService::imageWidgetDeltaFromRaw($field_fragment);
    return array_key_exists('fids', $delta)
      || array_key_exists('focal_point', $delta)
      || array_key_exists('image_crop', $delta)
      || $this->brandingHeroCropAppliedFromDelta($delta) === 1;
  }

  /**
   * @param array<string, mixed> $field_fragment
   */
  private function brandingHeroFidFromMelFieldFragment(array $field_fragment): int {
    return EventStudioMelPayloadService::normalizeHeroFromMelFragment([
      'field_event_image' => $field_fragment,
    ])['fid'];
  }

  /**
   * @param array<string, mixed> $current
   * @param array<string, mixed> $from_input
   */
  private function brandingHeroMelFragmentNeedsSupplementalInputSync(array $current, array $from_input): bool {
    $input_delta = EventStudioMelPayloadService::imageWidgetDeltaFromRaw($from_input);
    $current_delta = EventStudioMelPayloadService::imageWidgetDeltaFromRaw($current);

    if (isset($input_delta['focal_point'])) {
      $input_focal = trim((string) $input_delta['focal_point']);
      $current_focal = trim((string) ($current_delta['focal_point'] ?? ''));
      if ($input_focal !== '' && $input_focal !== $current_focal) {
        return TRUE;
      }
    }

    $input_crop_applied = $this->brandingHeroCropAppliedFromDelta($input_delta);
    $current_crop_applied = $this->brandingHeroCropAppliedFromDelta($current_delta);
    if ($input_crop_applied !== $current_crop_applied) {
      return TRUE;
    }

    return $input_crop_applied === 1 && !isset($current_delta['image_crop']);
  }

  /**
   * @param array<string, mixed> $delta
   */
  private function brandingHeroCropAppliedFromDelta(array $delta): int {
    $crop_values = $delta['image_crop']['crop_wrapper']['event_hero']['crop_container']['values'] ?? NULL;
    if (!is_array($crop_values)) {
      return 0;
    }
    return (int) ($crop_values['crop_applied'] ?? 0);
  }

  /**
   * @param array<string, mixed> $current
   * @param array<string, mixed> $from_input
   *
   * @return array<string, mixed>
   */
  private function mergeBrandingHeroMelFieldFragment(array $current, array $from_input): array {
    if (isset($current['widget']) && is_array($current['widget'])) {
      $input_widget = $from_input['widget'] ?? NULL;
      if (!is_array($input_widget) && isset($from_input[0]) && is_array($from_input[0])) {
        $input_widget = [0 => $from_input[0]];
      }
      if (is_array($input_widget)) {
        foreach ($input_widget as $delta_key => $input_delta) {
          if (!is_numeric($delta_key) || !is_array($input_delta)) {
            continue;
          }
          $existing = $current['widget'][$delta_key] ?? [];
          if (is_array($existing)) {
            $existing = $this->stripBrandingHeroCropWhenFidChanges($existing, $input_delta);
          }
          $current['widget'][$delta_key] = array_replace_recursive(
            is_array($existing) ? $existing : [],
            $input_delta,
          );
        }
      }
      return $current;
    }

    $input_deltas = $from_input;
    if (isset($from_input['widget']) && is_array($from_input['widget'])) {
      $input_deltas = $from_input['widget'];
    }

    foreach ($input_deltas as $delta_key => $input_delta) {
      if (!is_numeric($delta_key) || !is_array($input_delta)) {
        continue;
      }
      $existing = $current[$delta_key] ?? [];
      if (is_array($existing)) {
        $existing = $this->stripBrandingHeroCropWhenFidChanges($existing, $input_delta);
      }
      $current[$delta_key] = array_replace_recursive(
        is_array($existing) ? $existing : [],
        $input_delta,
      );
    }

    return $current;
  }

  /**
   * Drops stale image_widget_crop data when the managed file id changes.
   *
   * @param array<string, mixed> $existing
   * @param array<string, mixed> $input_delta
   *
   * @return array<string, mixed>
   */
  private function stripBrandingHeroCropWhenFidChanges(array $existing, array $input_delta): array {
    $input_fid = EventStudioMelPayloadService::firstPositiveIntFromFidsValue($input_delta['fids'] ?? NULL);
    $existing_fid = EventStudioMelPayloadService::firstPositiveIntFromFidsValue($existing['fids'] ?? NULL);
    if ($input_fid > 0 && $existing_fid > 0 && $input_fid !== $existing_fid) {
      unset($existing['image_crop']);
    }

    return $existing;
  }

  /**
   * Removes crop widget values from a mel hero fragment (file replacement).
   *
   * @param array<string, mixed> $field_fragment
   *
   * @return array<string, mixed>
   */
  private function stripBrandingHeroCropFromMelFragment(array $field_fragment): array {
    if (isset($field_fragment['widget']) && is_array($field_fragment['widget'])) {
      foreach (array_keys($field_fragment['widget']) as $delta_key) {
        if (!is_numeric($delta_key) || !is_array($field_fragment['widget'][$delta_key])) {
          continue;
        }
        unset($field_fragment['widget'][$delta_key]['image_crop']);
      }
      return $field_fragment;
    }

    if (isset($field_fragment[0]) && is_array($field_fragment[0])) {
      unset($field_fragment[0]['image_crop']);
      return $field_fragment;
    }

    unset($field_fragment['image_crop']);
    return $field_fragment;
  }

  /**
   * Returns the hero file id currently stored on the event node.
   */
  private function brandingHeroFidFromNode(NodeInterface $node): int {
    if (!$node->hasField('field_event_image') || $node->get('field_event_image')->isEmpty()) {
      return 0;
    }
    return max(0, (int) ($node->get('field_event_image')->target_id ?? 0));
  }

  /**
   * Deletes an event_hero crop that no longer fits the underlying image dimensions.
   */
  private function pruneInvalidEventHeroCrop(FileInterface $file, string $node_id): void {
    $uri = $file->getFileUri();
    if ($uri === '') {
      return;
    }

    $crop = Crop::findCrop($uri, self::BRANDING_HERO_CROP_TYPE);
    if (!$crop instanceof CropInterface) {
      return;
    }

    $image = $this->imageFactory->get($uri);
    if (!$image->isValid()) {
      return;
    }

    $image_width = $image->getWidth();
    $image_height = $image->getHeight();
    $crop_width = (int) ($crop->get('width')->value ?? 0);
    $crop_height = (int) ($crop->get('height')->value ?? 0);
    $crop_x = (int) ($crop->get('x')->value ?? 0);
    $crop_y = (int) ($crop->get('y')->value ?? 0);

    $valid = $crop_width > 0
      && $crop_height > 0
      && $crop_x >= 0
      && $crop_y >= 0
      && ($crop_x + $crop_width) <= $image_width
      && ($crop_y + $crop_height) <= $image_height;

    if ($valid) {
      return;
    }

    try {
      $crop->delete();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Branding save: could not delete invalid event_hero crop for file @fid on node @nid: @message', [
        '@fid' => (string) $file->id(),
        '@nid' => $node_id,
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    $this->logger->warning('Branding save: removed invalid event_hero crop for file @fid on node @nid (image @iw×@ih, crop @x,@y @cw×@ch).', [
      '@fid' => (string) $file->id(),
      '@nid' => $node_id,
      '@iw' => (string) $image_width,
      '@ih' => (string) $image_height,
      '@x' => (string) $crop_x,
      '@y' => (string) $crop_y,
      '@cw' => (string) $crop_width,
      '@ch' => (string) $crop_height,
    ]);
  }

  /**
   * Flushes public hero derivatives after a branding save so replaced images refresh.
   */
  private function flushBrandingHeroImageStylesAfterSave(?FileInterface $saved_file, int $previous_fid, string $node_id): void {
    if ($saved_file instanceof FileInterface) {
      $this->flushBrandingHeroImageStyleForFile($saved_file, $node_id);
    }

    if ($previous_fid < 1) {
      return;
    }

    $saved_fid = $saved_file instanceof FileInterface ? (int) $saved_file->id() : 0;
    if ($saved_fid === $previous_fid) {
      return;
    }

    $previous = $this->entityTypeManager->getStorage('file')->load($previous_fid);
    if ($previous instanceof FileInterface) {
      $this->flushBrandingHeroImageStyleForFile($previous, $node_id);
    }
  }

  /**
   * Flushes MEL hero image style derivatives for one file URI.
   */
  private function flushBrandingHeroImageStyleForFile(FileInterface $file, string $node_id): void {
    $uri = $file->getFileUri();
    if ($uri === '') {
      return;
    }

    foreach (['mel_event_hero_featured', 'mel_crop_event_hero'] as $style_id) {
      $style = ImageStyle::load($style_id);
      if (!$style instanceof ImageStyleInterface) {
        continue;
      }
      try {
        $style->flush($uri);
      }
      catch (\Throwable $e) {
        $this->logger->warning('Branding save: could not flush @style for file @fid on node @nid: @message', [
          '@style' => $style_id,
          '@fid' => (string) $file->id(),
          '@nid' => $node_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * @param mixed $fragment
   */
  private function brandingHeroSyncSnapshot(mixed $fragment): string {
    if (!is_array($fragment) || $fragment === []) {
      return 'empty';
    }
    $fid = $this->brandingHeroFidFromMelFieldFragment($fragment);
    $delta = EventStudioMelPayloadService::imageWidgetDeltaFromRaw($fragment);
    $crop = $this->brandingHeroCropAppliedFromDelta($delta);
    $focal = trim((string) ($delta['focal_point'] ?? ''));
    return sprintf(
      'fid=%d,crop=%d,focal=%s',
      $fid,
      $crop,
      $focal !== '' ? $focal : 'none',
    );
  }

  /**
   * Copies media-library selection from raw user input when Form API values are stale.
   *
   * MediaLibraryWidget AJAX handlers can leave selection in user input only; WidgetBase
   * reads form_state values during extractFormValues().
   */
  private function syncBrandingGallerySubmittedValues(FormStateInterface $form_state): void {
    $path = ['mel', 'field_mel_event_gallery'];
    $from_input = NestedArray::getValue($form_state->getUserInput(), $path);
    if (!is_array($from_input) || !array_key_exists('selection', $from_input)) {
      return;
    }

    $values = $form_state->getValues();
    $current = NestedArray::getValue($values, $path);
    if (!is_array($current)) {
      NestedArray::setValue($values, $path, $from_input);
      $form_state->setValues($values);
      return;
    }

    $input_ids = $this->brandingGalleryMediaIdsFromField($from_input['selection'] ?? []);
    $current_ids = $this->brandingGalleryMediaIdsFromField($current['selection'] ?? []);
    if ($input_ids !== $current_ids) {
      $current['selection'] = $from_input['selection'];
      NestedArray::setValue($values, $path, $current);
      $form_state->setValues($values);
      return;
    }

    if (!array_key_exists('selection', $current)) {
      NestedArray::setValue($values, $path, array_merge($current, $from_input));
      $form_state->setValues($values);
    }
  }

  /**
   * @param array<int, array<string, mixed>> $field_value
   *
   * @return list<int>
   */
  private function brandingGalleryMediaIdsFromField(array $field_value): array {
    $ids = [];
    foreach ($field_value as $row) {
      if (!is_array($row)) {
        continue;
      }
      $media_id = (int) ($row['target_id'] ?? 0);
      if ($media_id > 0) {
        $ids[] = $media_id;
      }
    }
    return $ids;
  }

  /**
   * Reads ordered gallery media IDs from submitted mel values.
   *
   * @param array<string, mixed> $mel_form
   *
   * @return list<int>|null
   *   Null when no submitted gallery fragment was found.
   */
  private function extractBrandingGalleryTargetIdsFromSubmittedMel(
    FormStateInterface $form_state,
    MediaLibraryWidget $widget,
    array $mel_form,
  ): ?array {
    $path = ['mel', 'field_mel_event_gallery'];
    $fragment = NestedArray::getValue($form_state->getUserInput(), $path);
    if (!is_array($fragment) || !array_key_exists('selection', $fragment)) {
      $fragment = NestedArray::getValue($form_state->getValues(), $path);
    }
    if (!is_array($fragment)) {
      $parents = $mel_form['#parents'] ?? ['mel'];
      $widget_state = WidgetBase::getWidgetState($parents, 'field_mel_event_gallery', $form_state);
      if (isset($widget_state['items']) && is_array($widget_state['items'])) {
        $fragment = ['selection' => $widget_state['items']];
      }
    }
    if (!is_array($fragment)) {
      return NULL;
    }
    if (!array_key_exists('selection', $fragment)) {
      return [];
    }

    $massaged = $widget->massageFormValues($fragment, $mel_form, $form_state);
    $ids = [];
    foreach ($massaged as $row) {
      if (!is_array($row)) {
        continue;
      }
      $media_id = (int) ($row['target_id'] ?? 0);
      if ($media_id > 0) {
        $ids[] = $media_id;
      }
    }
    return $ids;
  }

  /**
   * Builds the style/colour mel fragment for persistence.
   *
   * Uses the merged wizard payload from {@see EventBrandingForm::persistWizardMel()}
   * and overlays scalar POST keys when Form API values are stale.
   *
   * @param array<string, mixed> $mel_subform
   *
   * @return array<string, mixed>
   */
  private function resolveBrandingStyleMelForPersistence(array $mel_subform, FormStateInterface $form_state): array {
    $style_mel = $mel_subform;
    $user_mel = $form_state->getUserInput()['mel'] ?? NULL;
    if (!is_array($user_mel)) {
      return $style_mel;
    }

    foreach (['field_mel_page_style', 'field_mel_theme_colour'] as $key) {
      if (array_key_exists($key, $user_mel) && is_scalar($user_mel[$key])) {
        $style_mel[$key] = (string) $user_mel[$key];
      }
    }

    return $style_mel;
  }

  /**
   * Persists page style and theme colour from the branding mel fragment.
   *
   * @param array<string, mixed> $mel_values
   *
   * @return list<string>
   */
  private function applyBrandingPageStyleFields(NodeInterface $node, array $mel_values): array {
    if (!$this->eventPageStyleResolver instanceof EventPageStyleResolver) {
      return [];
    }

    $account = \Drupal::currentUser();
    $resolved = $this->eventPageStyleResolver->resolveForPersistence($node, $mel_values, $account);

    if ($node->hasField('field_mel_page_style')
      && array_key_exists('field_mel_page_style', $mel_values)) {
      $node->set('field_mel_page_style', $resolved['style']);
    }

    if ($node->hasField('field_mel_theme_colour')
      && array_key_exists('field_mel_theme_colour', $mel_values)) {
      $node->set('field_mel_theme_colour', $resolved['colour']);
    }

    return [];
  }

  /**
   * @return list<string>
   */
  private function buildBrandingHeroDimensionWarnings(FileInterface $file): array {
    $image = $this->imageFactory->get($file->getFileUri());
    if (!$image->isValid()) {
      return [];
    }
    $w = $image->getWidth();
    $h = $image->getHeight();
    if ($w >= self::BRANDING_HERO_WARN_WIDTH_LT && $h >= self::BRANDING_HERO_WARN_HEIGHT_LT) {
      return [];
    }
    return [
      (string) $this->stringTranslation->translate('Your cover image is smaller than we recommend (at least @w×@h pixels; ideally about 1600×900). It may look softer when scaled on large screens.', [
        '@w' => (string) self::BRANDING_HERO_WARN_WIDTH_LT,
        '@h' => (string) self::BRANDING_HERO_WARN_HEIGHT_LT,
      ]),
    ];
  }

  /**
   * Ensures focal point + dimensions are present for focal_point_entity_update().
   *
   * @param array<string, mixed> $field_item
   *
   * @return array<string, mixed>
   */
  private function enrichBrandingHeroFieldItem(array $field_item, FileInterface $file): array {
    if (empty($field_item['width']) || empty($field_item['height'])) {
      $image = $this->imageFactory->get($file->getFileUri());
      if ($image->isValid()) {
        $field_item['width'] = $image->getWidth();
        $field_item['height'] = $image->getHeight();
      }
    }

    $synced_focal = $this->deriveBrandingHeroFocalFromEventHeroCrop($file, $field_item);
    if ($synced_focal !== NULL) {
      $field_item['focal_point'] = $synced_focal;
    }
    elseif (empty($field_item['focal_point'])) {
      $field_item['focal_point'] = '50,50';
    }

    return $field_item;
  }

  /**
   * Maps the branding event_hero crop centre to focal_point percentages.
   *
 * Public event and book heroes use mel_event_hero_featured (event_hero crop). The
 * studio crop widget edits event_hero; focal_point_entity_update() still runs
   * on node save when the field item carries focal_point + dimensions.
   */
  private function deriveBrandingHeroFocalFromEventHeroCrop(FileInterface $file, array $field_item): ?string {
    $uri = $file->getFileUri();
    if ($uri === '') {
      return NULL;
    }

    $crop = Crop::findCrop($uri, self::BRANDING_HERO_CROP_TYPE);
    if (!$crop instanceof CropInterface) {
      return NULL;
    }

    $width = (int) ($field_item['width'] ?? 0);
    $height = (int) ($field_item['height'] ?? 0);
    if ($width < 1 || $height < 1) {
      $image = $this->imageFactory->get($uri);
      if (!$image->isValid()) {
        $this->logger->warning('Branding save: cannot derive focal point from event_hero crop for file @fid — unreadable image.', [
          '@fid' => (string) $file->id(),
        ]);
        return NULL;
      }
      $width = $image->getWidth();
      $height = $image->getHeight();
    }

    $center = $crop->position();
    $relative = $this->focalPointManager->absoluteToRelative(
      (int) $center['x'],
      (int) $center['y'],
      $width,
      $height,
    );
    $value = ((int) $relative['x']) . ',' . ((int) $relative['y']);
    if (!$this->focalPointManager->validateFocalPoint($value)) {
      $this->logger->warning('Branding save: event_hero crop centre for file @fid did not produce a valid focal point (@value).', [
        '@fid' => (string) $file->id(),
        '@value' => $value,
      ]);
      return NULL;
    }

    return $value;
  }

  /**
   * Whether a file entity URI exists and is a readable image for crop widgets.
   */
  public function isHeroFileRenderable(FileInterface $file): bool {
    $uri = $file->getFileUri();
    if ($uri === '') {
      return FALSE;
    }
    $real = $this->fileSystem->realpath($uri);
    if ($real === FALSE || !is_readable($real)) {
      return FALSE;
    }
    $mime = $file->getMimeType();
    if ($mime !== '' && !str_starts_with($mime, 'image/')) {
      return FALSE;
    }
    $image = $this->imageFactory->get($uri);
    return $image->isValid()
      && $image->getWidth() > 0
      && $image->getHeight() > 0;
  }

  /**
   * User-facing error when a cover file entity exists but cannot be read.
   */
  private function brokenHeroImageErrorMessage(): string {
    return (string) $this->stringTranslation->translate(
      'The cover image file is missing or unreadable. Upload a new image or remove the cover image.'
    );
  }

  /**
   * User-facing error when a submitted hero upload could not be resolved for save.
   */
  private function brandingHeroUnsavedUploadErrorMessage(): string {
    return (string) $this->stringTranslation->translate(
      'The event image could not be saved. Please reselect the image.'
    );
  }

  /**
   * Hero image is owned by the branding workspace section only.
   *
   * @param array<string, mixed> $payload
   */
  private function shouldApplyHeroImagePayload(array $payload): bool {
    $section = trim((string) ($payload['studio_section'] ?? ''));
    return $section === '';
  }

  /**
   * @param list<string> $errors
   * @param array<string, mixed> $payload
   *
   * @return array{node: null, errors: list<string>}
   */
  private function abortSectionScopedSave(array $errors, array $payload): array {
    return ['node' => NULL, 'errors' => $this->enrichSectionScopedSaveErrors($errors, $payload)];
  }

  /**
   * Adds workspace section context when a scoped save aborts after content fields apply.
   *
   * @param list<string> $errors
   * @param array<string, mixed> $payload
   *
   * @return list<string>
   */
  private function enrichSectionScopedSaveErrors(array $errors, array $payload): array {
    if ($errors === []) {
      return [];
    }
    $section = trim((string) ($payload['studio_section'] ?? ''));
    if ($section !== 'content') {
      return $errors;
    }
    return [
      (string) $this->stringTranslation->translate(
        'Accessibility and content could not be saved because another field failed validation.'
      ),
      ...$errors,
    ];
  }

  /**
   * Detects an intentional hero removal from branding widget submission.
   *
   * @param array<string, mixed>|null $input_fragment
   */
  private function brandingHeroExplicitRemovalRequested(?array $input_fragment): bool {
    if (!is_array($input_fragment)) {
      return FALSE;
    }
    $delta = EventStudioMelPayloadService::imageWidgetDeltaFromRaw($input_fragment);
    if (!array_key_exists('fids', $delta)) {
      return FALSE;
    }
    return EventStudioMelPayloadService::firstPositiveIntFromFidsValue($delta['fids'] ?? NULL) < 1;
  }

  /**
   * Updates alt text on an existing hero when branding save did not replace the file.
   *
   * @param array<string, mixed> $mel_values
   */
  private function applyBrandingHeroAltToExistingNode(NodeInterface $node, array $mel_values): void {
    if ($node->get('field_event_image')->isEmpty()) {
      return;
    }
    $hero = EventStudioMelPayloadService::normalizeHeroFromMelFragment($mel_values);
    if ($hero['alt'] === '') {
      return;
    }
    $row = $node->get('field_event_image')->first()?->getValue() ?? [];
    if (!is_array($row) || (int) ($row['target_id'] ?? 0) < 1) {
      return;
    }
    $row['alt'] = $hero['alt'];
    $node->set('field_event_image', [$row]);
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
    if (!$this->isHeroFileRenderable($file)) {
      $this->logger->warning('Studio save: hero file @fid is not renderable (missing on disk or not an image) for node @nid.', [
        '@fid' => (string) $fid,
        '@nid' => (string) $node->id(),
      ]);
      return [$this->brokenHeroImageErrorMessage()];
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

    if ($this->questionTemplateManager === NULL) {
      $this->logger->error('Event Studio: attendee questions save failed — question template manager not injected.');
      return ['Attendee questions could not be saved.'];
    }

    $items = $payload['attendee_questions'];
    if (!is_array($items)) {
      return ['Attendee questions data was invalid. Reload and try again.'];
    }

    return $this->questionTemplateManager->saveBuilderPayload($node, $items, $account);
  }

  /**
   * Persists mel_operational_capabilities authoring metadata when present in payload.
   *
   * @param array<string, mixed> $payload
   *
   * @return list<string>
   */
  private function applyOperationalCapabilitiesPayload(NodeInterface $node, array $payload): array {
    if ($this->operationalCapabilityStudioManager === NULL) {
      return [];
    }
    if (!array_key_exists('mel_operational_capabilities', $payload)) {
      return [];
    }
    $raw = $payload['mel_operational_capabilities'];
    if (!is_array($raw)) {
      return ['Operational capabilities data was invalid.'];
    }
    try {
      $document = $this->operationalCapabilityStudioManager->normalizeMelFragment(['mel_operational_capabilities' => $raw], $node);
      $errors = $this->operationalCapabilityStudioManager->validateDocument($node, $document);
      if ($errors !== []) {
        return $errors;
      }
      $this->operationalCapabilityStudioManager->persistToEvent($node, $document);
    }
    catch (\Throwable $e) {
      $this->logger->error('Studio operational capability save failed for node @nid: @message', [
        '@nid' => (string) $node->id(),
        '@message' => $e->getMessage(),
      ]);
      return ['Could not save operational capabilities.'];
    }
    return [];
  }

  /**
   * Ensures section-scoped saves do not clear an existing event title.
   *
   * @param array<string, mixed> $payload
   *
   * @return array<string, mixed>
   */
  private function resolvePayloadTitle(array $payload, ?NodeInterface $node): array {
    $title = isset($payload['title']) ? trim((string) $payload['title']) : '';
    if ($title === '' && $node instanceof NodeInterface) {
      $payload['title'] = $node->label();
    }
    elseif ($title !== '') {
      $payload['title'] = $title;
    }
    return $payload;
  }

}
