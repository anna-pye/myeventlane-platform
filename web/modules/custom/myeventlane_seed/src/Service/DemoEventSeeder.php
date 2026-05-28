<?php

declare(strict_types=1);

namespace Drupal\myeventlane_seed\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\myeventlane_event_studio\Service\EventHighlightHelper;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\myeventlane_seed\Util\ImageFactory;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\user\Entity\User;
use InvalidArgumentException;

/**
 * Seeds deterministic [MEL TEST] demo events for a configured vendor owner.
 */
final class DemoEventSeeder {

  private const SEED_KEY_MARKER = 'mel_seed_key:';

  /**
   * Maps settings keys to accessibility taxonomy term names (lookup by label).
   */
  private const ACCESSIBILITY_TERM_NAMES = [
    'use_wheelchair_access' => 'Wheelchair Accessible',
    'use_auslan_interpreter' => 'Sign Language Interpreter Available',
    'use_sensory_friendly' => 'Cognitive Accessibility',
    'use_quiet_space' => 'Quiet Space Available',
    'use_captions' => 'Closed Captions Available',
  ];

  /**
   * @var list<array{name: string, street: string, city: string, postcode: string}>
   */
  private const SYDNEY_LOCATIONS = [
    ['name' => 'Sydney Opera House', 'street' => 'Bennelong Point', 'city' => 'Sydney', 'postcode' => '2000'],
    ['name' => 'Darling Harbour', 'street' => 'Darling Harbour', 'city' => 'Sydney', 'postcode' => '2000'],
    ['name' => 'Bondi Beach Pavilion', 'street' => 'Queen Elizabeth Drive', 'city' => 'Bondi Beach', 'postcode' => '2026'],
    ['name' => 'The Rocks Discovery Museum', 'street' => 'Kendall Lane', 'city' => 'The Rocks', 'postcode' => '2000'],
    ['name' => 'Royal Botanic Garden', 'street' => 'Mrs Macquaries Road', 'city' => 'Sydney', 'postcode' => '2000'],
    ['name' => 'Hyde Park', 'street' => 'Elizabeth Street', 'city' => 'Sydney', 'postcode' => '2000'],
    ['name' => 'Barangaroo Reserve', 'street' => 'Hickson Road', 'city' => 'Barangaroo', 'postcode' => '2000'],
    ['name' => 'Carriageworks', 'street' => '245 Wilson Street', 'city' => 'Eveleigh', 'postcode' => '2015'],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CurrentVendorResolverInterface $currentVendorResolver,
    private readonly TicketTierLifecycleService $ticketTierLifecycle,
    private readonly ImageFactory $imageFactory,
    private readonly ?EventStudioSaveService $eventStudioSave,
    private readonly ?EventHighlightHelper $eventHighlightHelper,
    private readonly DemoEventPurger $demoEventPurger,
  ) {}

  /**
   * Seeds demo events using config, legacy defaults, and optional CLI overrides.
   *
   * @param array<string, mixed> $options
   *   Supported keys: use-settings, count, owner, dry-run.
   *
   * @return array<string, mixed>
   *   Summary with events list and dry_run flag.
   */
  public function seed(array $options = []): array {
    $settings = $this->resolveSettings($options);
    $this->validateSettings($settings);

    $logger = $this->loggerFactory->get('myeventlane_seed');
    if (empty($settings['enabled'])) {
      throw new InvalidArgumentException('Demo content generation is disabled in settings.');
    }

    $owner = $this->loadOwnerUser((string) $settings['owner_username']);
    if ($owner === NULL) {
      throw new InvalidArgumentException(sprintf('Owner user "%s" was not found.', $settings['owner_username']));
    }

    $vendor = $this->currentVendorResolver->resolveFromUser($owner);
    if (!$vendor instanceof Vendor) {
      throw new InvalidArgumentException(sprintf('No vendor entity found for user "%s".', $settings['owner_username']));
    }

    $store = $this->resolveStore($vendor);
    if (!$store instanceof StoreInterface) {
      throw new InvalidArgumentException(sprintf('No commerce store found for vendor "%s".', $vendor->label()));
    }

    if (!empty($settings['purge_before_generate']) && empty($settings['dry_run'])) {
      $this->demoEventPurger->purge([
        'owner' => $settings['owner_username'],
        'dry_run' => FALSE,
      ]);
    }
    elseif (!empty($settings['purge_before_generate']) && !empty($settings['dry_run'])) {
      $logger->info('Dry-run: would purge existing seeded events before generate.');
    }

    $schedule = $this->buildEventSchedule($settings);
    $events = [];
    $timezone = new \DateTimeZone((string) $settings['default_timezone']);

    foreach ($schedule as $slot) {
      $index = (int) $slot['index'];
      $type = (string) $slot['type'];
      $seedKey = $this->buildSeedKey($settings, $index);
      $existing = !empty($settings['update_existing'])
        ? $this->loadExistingSeedEvent($vendor, $seedKey)
        : NULL;

      if (!empty($settings['dry_run'])) {
        $events[] = [
          'seed_key' => $seedKey,
          'type' => $type,
          'action' => $existing instanceof NodeInterface ? 'update' : 'create',
          'title' => $this->buildTitle($settings, $index, $type),
        ];
        continue;
      }

      $event = $this->seedSingleEvent(
        $settings,
        $owner,
        $vendor,
        $store,
        $index,
        $type,
        $seedKey,
        $existing,
        $timezone,
      );

      if ($event instanceof NodeInterface) {
        $events[] = [
          'event_nid' => (int) $event->id(),
          'seed_key' => $seedKey,
          'type' => $type,
          'title' => (string) $event->label(),
        ];
      }
    }

    $logger->info('Demo event seed complete for @user: @count events.', [
      '@user' => $settings['owner_username'],
      '@count' => (string) count($events),
    ]);

    return [
      'owner_username' => $settings['owner_username'],
      'vendor_id' => (int) $vendor->id(),
      'store_id' => (int) $store->id(),
      'dry_run' => !empty($settings['dry_run']),
      'events' => $events,
    ];
  }

  /**
   * @param array<string, mixed> $options
   *
   * @return array<string, mixed>
   */
  public function resolveSettings(array $options = []): array {
    $useSettings = array_key_exists('use-settings', $options)
      ? !empty($options['use-settings'])
      : FALSE;

    $base = $useSettings
      ? $this->configFactory->get('myeventlane_seed.settings')->get()
      : $this->getLegacyDefaults();

    $eventCount = isset($options['count']) ? (int) $options['count'] : (int) ($base['event_count'] ?? 8);
    $settings = array_merge($base, [
      'owner_username' => (string) ($options['owner'] ?? $base['owner_username'] ?? 'anna'),
      'event_count' => $eventCount,
      'dry_run' => !empty($options['dry-run']) || !empty($base['dry_run']),
    ]);

    if (isset($options['count'])
      && !empty($settings['generate_rsvp_events'])
      && !empty($settings['generate_paid_events'])) {
      $baseRsvp = (int) ($base['rsvp_event_count'] ?? 0);
      $basePaid = (int) ($base['paid_event_count'] ?? 0);
      $baseTotal = max(1, $baseRsvp + $basePaid);
      $settings['rsvp_event_count'] = (int) round($eventCount * $baseRsvp / $baseTotal);
      $settings['paid_event_count'] = $eventCount - (int) $settings['rsvp_event_count'];
    }

    return $settings;
  }

  /**
   * Built-in defaults for mel:seed-events without --use-settings.
   *
   * @return array<string, mixed>
   */
  public function getLegacyDefaults(): array {
    return [
      'enabled' => TRUE,
      'owner_username' => 'anna',
      'event_count' => 8,
      'title_prefix' => '[MEL TEST]',
      'seed_key_prefix' => 'mel_seed',
      'default_timezone' => 'Australia/Sydney',
      'future_date_start_days' => 14,
      'future_date_spacing_days' => 7,
      'update_existing' => TRUE,
      'purge_before_generate' => FALSE,
      'populate_title' => TRUE,
      'populate_event_type' => TRUE,
      'populate_start_end_dates' => TRUE,
      'populate_vendor_reference' => TRUE,
      'populate_store_reference' => TRUE,
      'populate_capacity' => TRUE,
      'populate_event_image' => TRUE,
      'populate_location' => TRUE,
      'populate_venue_name' => TRUE,
      'populate_body' => TRUE,
      'populate_event_summary' => TRUE,
      'populate_highlights' => TRUE,
      'populate_accessibility' => TRUE,
      'populate_attendee_questions' => TRUE,
      'populate_ticket_types' => TRUE,
      'populate_rsvp_settings' => TRUE,
      'generate_rsvp_events' => TRUE,
      'generate_paid_events' => TRUE,
      'rsvp_event_count' => 3,
      'paid_event_count' => 5,
      'default_currency' => 'AUD',
      'default_paid_ticket_capacity' => 50,
      'default_rsvp_capacity' => 40,
      'create_general_ticket' => TRUE,
      'create_concession_ticket' => TRUE,
      'create_early_bird_ticket' => TRUE,
      'create_supporter_ticket' => FALSE,
      'min_ticket_price' => 10.00,
      'max_ticket_price' => 45.00,
      'use_wheelchair_access' => TRUE,
      'use_auslan_interpreter' => TRUE,
      'use_sensory_friendly' => TRUE,
      'use_quiet_space' => TRUE,
      'use_captions' => FALSE,
      'skip_missing_accessibility_terms' => TRUE,
      'generate_images' => TRUE,
      'image_directory' => 'public://events',
      'image_width' => 1600,
      'image_height' => 900,
      'reuse_existing_seed_images' => TRUE,
      'dry_run' => FALSE,
      'allow_purge_from_ui' => FALSE,
      'require_confirm_text' => TRUE,
      'confirm_text_value' => 'GENERATE MEL TEST CONTENT',
    ];
  }

  /**
   * @param array<string, mixed> $settings
   */
  public function validateSettings(array $settings): void {
    $eventCount = (int) ($settings['event_count'] ?? 0);
    if ($eventCount < 1 || $eventCount > 50) {
      throw new InvalidArgumentException('event_count must be between 1 and 50.');
    }

    $startDays = (int) ($settings['future_date_start_days'] ?? 0);
    if ($startDays < 1) {
      throw new InvalidArgumentException('future_date_start_days must be at least 1.');
    }

    $spacingDays = (int) ($settings['future_date_spacing_days'] ?? 0);
    if ($spacingDays < 1) {
      throw new InvalidArgumentException('future_date_spacing_days must be at least 1.');
    }

    $owner = trim((string) ($settings['owner_username'] ?? ''));
    if ($owner === '' || $this->loadOwnerUser($owner) === NULL) {
      throw new InvalidArgumentException(sprintf('owner_username "%s" must resolve to a real user.', $owner));
    }

    $generateRsvp = !empty($settings['generate_rsvp_events']);
    $generatePaid = !empty($settings['generate_paid_events']);
    if (!$generateRsvp && !$generatePaid) {
      throw new InvalidArgumentException('At least one of generate_rsvp_events or generate_paid_events must be enabled.');
    }

    $rsvpCount = (int) ($settings['rsvp_event_count'] ?? 0);
    $paidCount = (int) ($settings['paid_event_count'] ?? 0);

    if ($generateRsvp && $generatePaid && ($rsvpCount + $paidCount) !== $eventCount) {
      throw new InvalidArgumentException('rsvp_event_count + paid_event_count must equal event_count when both types are enabled.');
    }
    if ($generateRsvp && !$generatePaid && $rsvpCount !== $eventCount) {
      throw new InvalidArgumentException('rsvp_event_count must equal event_count when only RSVP events are enabled.');
    }
    if ($generatePaid && !$generateRsvp && $paidCount !== $eventCount) {
      throw new InvalidArgumentException('paid_event_count must equal event_count when only paid events are enabled.');
    }

    if (!empty($settings['populate_ticket_types']) && !$generatePaid) {
      throw new InvalidArgumentException('populate_ticket_types requires generate_paid_events to be enabled.');
    }

    if (!empty($settings['populate_rsvp_settings']) && !$generateRsvp) {
      throw new InvalidArgumentException('populate_rsvp_settings requires generate_rsvp_events to be enabled.');
    }

    $required = [
      'populate_title',
      'populate_event_type',
      'populate_start_end_dates',
      'populate_vendor_reference',
      'populate_store_reference',
    ];
    foreach ($required as $key) {
      if (empty($settings[$key])) {
        throw new InvalidArgumentException(sprintf('Required setting "%s" must be enabled to create valid events.', $key));
      }
    }

    $minPrice = (float) ($settings['min_ticket_price'] ?? 0);
    $maxPrice = (float) ($settings['max_ticket_price'] ?? 0);
    if ($minPrice > $maxPrice) {
      throw new InvalidArgumentException('min_ticket_price cannot exceed max_ticket_price.');
    }
  }

  /**
   * @param array<string, mixed> $settings
   *
   * @return list<array{index: int, type: string}>
   */
  private function buildEventSchedule(array $settings): array {
    $eventCount = (int) $settings['event_count'];
    $generateRsvp = !empty($settings['generate_rsvp_events']);
    $generatePaid = !empty($settings['generate_paid_events']);

    $rsvpCount = $generateRsvp
      ? ($generatePaid ? (int) $settings['rsvp_event_count'] : $eventCount)
      : 0;
    $paidCount = $generatePaid
      ? ($generateRsvp ? (int) $settings['paid_event_count'] : $eventCount)
      : 0;

    $schedule = [];
    for ($i = 1; $i <= $eventCount; $i++) {
      $type = $i <= $rsvpCount ? 'rsvp' : 'paid';
      $schedule[] = ['index' => $i, 'type' => $type];
    }
    return $schedule;
  }

  /**
   * @param array<string, mixed> $settings
   */
  private function seedSingleEvent(
    array $settings,
    User $owner,
    Vendor $vendor,
    StoreInterface $store,
    int $index,
    string $type,
    string $seedKey,
    ?NodeInterface $existing,
    \DateTimeZone $timezone,
  ): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $location = self::SYDNEY_LOCATIONS[($index - 1) % count(self::SYDNEY_LOCATIONS)];
    [$start, $end] = $this->buildSchedule($settings, $index, $type, $timezone);

    if ($existing instanceof NodeInterface) {
      $event = $existing;
    }
    else {
      $event = $storage->create([
        'type' => 'event',
        'uid' => (int) $owner->id(),
        'status' => 1,
      ]);
    }

    if (!empty($settings['populate_title'])) {
      $event->setTitle($this->buildTitle($settings, $index, $type));
    }

    if (!empty($settings['populate_event_type'])) {
      $event->set('field_event_type', $type === 'rsvp' ? 'rsvp' : 'paid');
    }

    if (!empty($settings['populate_vendor_reference']) && $event->hasField('field_event_vendor')) {
      $event->set('field_event_vendor', ['target_id' => (int) $vendor->id()]);
    }

    if (!empty($settings['populate_store_reference']) && $event->hasField('field_event_store')) {
      $event->set('field_event_store', ['target_id' => (int) $store->id()]);
    }

    if (!empty($settings['populate_start_end_dates'])) {
      $event->set('field_event_start', $start->format('Y-m-d\TH:i:s'));
      $event->set('field_event_end', $end->format('Y-m-d\TH:i:s'));
    }

    if (!empty($settings['populate_capacity']) && $event->hasField('field_capacity')) {
      $capacity = $type === 'rsvp'
        ? (int) $settings['default_rsvp_capacity']
        : (int) $settings['default_paid_ticket_capacity'];
      $event->set('field_capacity', $capacity);
    }

    if (!empty($settings['populate_venue_name']) && $event->hasField('field_venue_name')) {
      $event->set('field_venue_name', $location['name']);
    }

    if (!empty($settings['populate_body']) && $event->hasField('body')) {
      $event->set('body', [
        'value' => $this->buildBodyCopy($settings, $index, $type, $seedKey),
        'format' => 'basic_html',
      ]);
    }

    if (!empty($settings['populate_event_summary']) && $event->hasField('field_event_summary')) {
      $event->set('field_event_summary', $this->buildSummary($settings, $index, $type, $seedKey));
    }

    $event->save();

    if (!empty($settings['populate_event_image']) && !empty($settings['generate_images']) && $event->hasField('field_event_image')) {
      $this->attachEventImage($event, $settings, $seedKey);
    }

    if (!empty($settings['populate_highlights'])) {
      $this->populateHighlights($event, $index);
    }

    if (!empty($settings['populate_accessibility'])) {
      $this->populateAccessibility($event, $settings);
    }

    if (!empty($settings['populate_attendee_questions'])) {
      $this->populateAttendeeQuestions($event, $index);
    }

    if ($type === 'paid' && !empty($settings['populate_ticket_types'])) {
      if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
        $this->populatePaidTickets($event, $owner, $settings, $index);
      }
    }

    if ($type === 'rsvp' && !empty($settings['populate_rsvp_settings'])) {
      if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
        $this->populateRsvpTicket($event, $owner, $settings);
      }
    }

    if (!empty($settings['populate_location'])) {
      $this->populateLocation($event, $owner, $location, $start, $end, $type);
    }

    $event->save();
    return $event;
  }

  /**
   * @param array<string, mixed> $settings
   *
   * @return array{\DateTime, \DateTime}
   */
  private function buildSchedule(array $settings, int $index, string $type, \DateTimeZone $timezone): array {
    $startOffset = (int) $settings['future_date_start_days'];
    $spacing = (int) $settings['future_date_spacing_days'];
    $daysAhead = $startOffset + (($index - 1) * $spacing);

    $start = new \DateTime('now', $timezone);
    $start->modify('+' . $daysAhead . ' days');
    $start->setTime($type === 'rsvp' ? 14 : 18, 0);

    $end = clone $start;
    $end->modify($type === 'rsvp' ? '+4 hours' : '+3 hours');
    return [$start, $end];
  }

  /**
   * @param array<string, mixed> $settings
   */
  private function buildSeedKey(array $settings, int $index): string {
    return sprintf('%s_%03d', (string) $settings['seed_key_prefix'], $index);
  }

  /**
   * @param array<string, mixed> $settings
   */
  private function buildTitle(array $settings, int $index, string $type): string {
    $label = $type === 'rsvp' ? 'RSVP' : 'Paid';
    return sprintf('%s Event %d - %s', (string) $settings['title_prefix'], $index, $label);
  }

  /**
   * @param array<string, mixed> $settings
   */
  private function buildSummary(array $settings, int $index, string $type, string $seedKey): string {
    return sprintf(
      "MyEventLane demo seed event (%s). %s %s",
      $type,
      self::SEED_KEY_MARKER,
      $seedKey,
    );
  }

  /**
   * @param array<string, mixed> $settings
   */
  private function buildBodyCopy(array $settings, int $index, string $type, string $seedKey): string {
    return sprintf(
      '<p>This is deterministic demo copy for <strong>%s</strong> (event %d, %s).</p><p>Safe to purge via mel:purge-events.</p>',
      $seedKey,
      $index,
      $type,
    );
  }

  private function loadOwnerUser(string $username): ?User {
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $username]);
    if ($users === []) {
      return NULL;
    }
    $user = reset($users);
    return $user instanceof User ? $user : NULL;
  }

  private function resolveStore(Vendor $vendor): ?StoreInterface {
    if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
      return $store instanceof StoreInterface ? $store : NULL;
    }
    return NULL;
  }

  /**
   * @param array<string, mixed> $settings
   */
  private function loadExistingSeedEvent(Vendor $vendor, string $seedKey): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('field_event_vendor', (int) $vendor->id())
      ->condition('field_event_summary', '%' . self::SEED_KEY_MARKER . ' ' . $seedKey . '%', 'LIKE')
      ->range(0, 1)
      ->execute();

    if ($nids === []) {
      return NULL;
    }
    $node = $storage->load(reset($nids));
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * @param array<string, mixed> $settings
   */
  private function attachEventImage(NodeInterface $event, array $settings, string $seedKey): void {
    $directory = preg_replace('#^public://#', '', (string) $settings['image_directory']);
    $directory = trim($directory, '/');
    if ($directory === '') {
      $directory = 'events';
    }

    $filename = $seedKey;
    if (!empty($settings['reuse_existing_seed_images'])) {
      $existing = $this->findExistingSeedImage($settings, $filename);
      if ($existing !== NULL) {
        $event->set('field_event_image', [
          'target_id' => (int) $existing->id(),
          'alt' => (string) $event->label(),
        ]);
        return;
      }
    }

    $file = $this->imageFactory->createPlaceholderImage(
      $filename,
      (int) $settings['image_width'],
      (int) $settings['image_height'],
      substr((string) $event->label(), 0, 40),
      $directory,
    );
    if ($file !== NULL) {
      $event->set('field_event_image', [
        'target_id' => (int) $file->id(),
        'alt' => (string) $event->label(),
      ]);
    }
  }

  /**
   * @param array<string, mixed> $settings
   */
  private function findExistingSeedImage(array $settings, string $filename): ?\Drupal\file\FileInterface {
    $directory = (string) $settings['image_directory'];
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename) ?? $filename;
    $uri = rtrim($directory, '/') . '/' . $safe . '.png';
    $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
    if ($files === []) {
      return NULL;
    }
    $file = reset($files);
    return $file instanceof \Drupal\file\FileInterface ? $file : NULL;
  }

  /**
   * @param array{name: string, street: string, city: string, postcode: string} $location
   */
  private function populateLocation(
    NodeInterface $event,
    User $owner,
    array $location,
    \DateTime $start,
    \DateTime $end,
    string $type,
  ): void {
    if ($event->hasField('field_location')) {
      $event->set('field_location', [[
        'country_code' => 'AU',
        'address_line1' => $location['street'],
        'locality' => $location['city'],
        'administrative_area' => 'NSW',
        'postal_code' => $location['postcode'],
        'address_line2' => '',
      ]]);
      return;
    }

    if (!$this->eventStudioSave instanceof EventStudioSaveService) {
      return;
    }

    $payload = [
      'title' => (string) $event->label(),
      'summary' => $event->hasField('field_event_summary') && !$event->get('field_event_summary')->isEmpty()
        ? (string) $event->get('field_event_summary')->value
        : '',
      'venue_choice' => 'one_off',
      'venue_id' => NULL,
      'new_venue_name' => '',
      'field_location' => [[
        'country_code' => 'AU',
        'address_line1' => $location['street'],
        'locality' => $location['city'],
        'administrative_area' => 'NSW',
        'postal_code' => $location['postcode'],
        'address_line2' => '',
      ]],
      'field_event_start' => $start->format('Y-m-d\TH:i:s'),
      'field_event_end' => $end->format('Y-m-d\TH:i:s'),
      'field_event_type' => $type === 'rsvp' ? 'rsvp' : 'paid',
      'status' => TRUE,
    ];
    $result = $this->eventStudioSave->save($payload, $event, $owner, FALSE);
    if ($result['errors'] !== []) {
      $this->loggerFactory->get('myeventlane_seed')->warning(
        'Location save for seed event @nid failed: @errors',
        [
          '@nid' => (string) $event->id(),
          '@errors' => implode('; ', $result['errors']),
        ],
      );
    }
  }

  private function populateHighlights(NodeInterface $event, int $index): void {
    if (!$event->hasField('field_event_highlights')) {
      return;
    }

    $allowedIcons = $this->eventHighlightHelper instanceof EventHighlightHelper
      ? $this->eventHighlightHelper->getAllowedIconKeys()
      : [];

    $samples = [
      ['text' => 'Live performances and community showcases', 'icon' => $allowedIcons[0] ?? ''],
      ['text' => 'Family-friendly atmosphere with accessible facilities', 'icon' => $allowedIcons[1] ?? ''],
      ['text' => 'Local vendors and food options on site', 'icon' => $allowedIcons[2] ?? ''],
    ];

    $old = array_values($event->get('field_event_highlights')->referencedEntities());
    $paragraphStorage = $this->entityTypeManager->getStorage('paragraph');
    $refs = [];

    foreach ($samples as $i => $item) {
      if (isset($old[$i]) && $old[$i] instanceof ParagraphInterface && $old[$i]->bundle() === 'event_highlight') {
        $paragraph = $old[$i];
      }
      else {
        $paragraph = $paragraphStorage->create(['type' => 'event_highlight']);
      }
      if ($paragraph->hasField('field_highlight_text')) {
        $paragraph->set('field_highlight_text', ['value' => $item['text']]);
      }
      if ($item['icon'] !== '' && $paragraph->hasField('field_highlight_icon')) {
        $paragraph->set('field_highlight_icon', $item['icon']);
      }
      $paragraph->save();
      $refs[] = [
        'target_id' => (int) $paragraph->id(),
        'target_revision_id' => (int) $paragraph->getRevisionId(),
      ];
    }

    for ($j = count($samples); $j < count($old); $j++) {
      if ($old[$j] instanceof ParagraphInterface) {
        $old[$j]->delete();
      }
    }

    $event->set('field_event_highlights', $refs);
  }

  /**
   * @param array<string, mixed> $settings
   */
  private function populateAccessibility(NodeInterface $event, array $settings): void {
    if (!$event->hasField('field_accessibility')) {
      return;
    }

    $termIds = [];
    $missing = [];
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');

    foreach (self::ACCESSIBILITY_TERM_NAMES as $settingKey => $termName) {
      if (empty($settings[$settingKey])) {
        continue;
      }
      $terms = $termStorage->loadByProperties([
        'vid' => 'accessibility',
        'name' => $termName,
      ]);
      if ($terms === []) {
        $missing[] = $termName;
        continue;
      }
      $term = reset($terms);
      $termIds[] = (int) $term->id();
    }

    if ($missing !== []) {
      $message = 'Missing accessibility terms: ' . implode(', ', $missing);
      if (!empty($settings['skip_missing_accessibility_terms'])) {
        $this->loggerFactory->get('myeventlane_seed')->warning('@message', ['@message' => $message]);
      }
      else {
        throw new InvalidArgumentException($message);
      }
    }

    $event->set('field_accessibility', array_map(static fn (int $id) => ['target_id' => $id], array_values(array_unique($termIds))));
  }

  private function populateAttendeeQuestions(NodeInterface $event, int $index): void {
    if (!$event->hasField('field_attendee_questions')) {
      return;
    }

    $questions = [
      [
        'label' => 'Dietary requirements',
        'type' => 'textfield',
        'required' => FALSE,
      ],
      [
        'label' => 'How did you hear about this event?',
        'type' => 'select',
        'required' => FALSE,
        'options' => ['Social media', 'Friend', 'Email', 'Other'],
      ],
    ];

    $paragraphStorage = $this->entityTypeManager->getStorage('paragraph');
    $old = array_values($event->get('field_attendee_questions')->referencedEntities());
    $refs = [];

    foreach ($questions as $i => $q) {
      if (isset($old[$i]) && $old[$i] instanceof ParagraphInterface && $old[$i]->bundle() === 'attendee_extra_field') {
        $paragraph = $old[$i];
      }
      else {
        $paragraph = $paragraphStorage->create(['type' => 'attendee_extra_field']);
      }
      if ($paragraph->hasField('field_question_label')) {
        $paragraph->set('field_question_label', $q['label']);
      }
      if ($paragraph->hasField('field_question_type')) {
        $paragraph->set('field_question_type', $q['type']);
      }
      if ($paragraph->hasField('field_question_required')) {
        $paragraph->set('field_question_required', !empty($q['required']) ? 1 : 0);
      }
      if ($paragraph->hasField('field_question_options')) {
        if (!empty($q['options'])) {
          $paragraph->set('field_question_options', ['value' => implode("\n", $q['options'])]);
        }
        else {
          $paragraph->set('field_question_options', NULL);
        }
      }
      $paragraph->save();
      $refs[] = [
        'target_id' => (int) $paragraph->id(),
        'target_revision_id' => (int) $paragraph->getRevisionId(),
      ];
    }

    for ($j = count($questions); $j < count($old); $j++) {
      if ($old[$j] instanceof ParagraphInterface) {
        $old[$j]->delete();
      }
    }

    $event->set('field_attendee_questions', $refs);
    if ($refs !== [] && $event->hasField('field_collect_per_ticket')) {
      $event->set('field_collect_per_ticket', TRUE);
    }
  }

  /**
   * @param array<string, mixed> $settings
   */
  private function populatePaidTickets(NodeInterface $event, User $owner, array $settings, int $index): void {
    $currency = (string) $settings['default_currency'];
    $capacity = (int) $settings['default_paid_ticket_capacity'];
    $tiers = [];

    if (!empty($settings['create_general_ticket'])) {
      $tiers[] = ['title' => 'General admission', 'price' => $this->deterministicPrice($settings, $index, 1), 'default' => TRUE];
    }
    if (!empty($settings['create_concession_ticket'])) {
      $tiers[] = ['title' => 'Concession', 'price' => $this->deterministicPrice($settings, $index, 2)];
    }
    if (!empty($settings['create_early_bird_ticket'])) {
      $tiers[] = ['title' => 'Early bird', 'price' => $this->deterministicPrice($settings, $index, 3)];
    }
    if (!empty($settings['create_supporter_ticket'])) {
      $tiers[] = ['title' => 'Supporter', 'price' => $this->deterministicPrice($settings, $index, 4)];
    }

    $multipleTiers = count($tiers) > 1;
    foreach ($tiers as $i => $tier) {
      $values = $this->ticketTierLifecycle->buildTicketValuesFromInput($event, $owner, [
        'title' => $tier['title'],
        'ticket_kind' => 'paid',
        'capacity' => $capacity,
        'price' => [
          'number' => number_format((float) $tier['price'], 2, '.', ''),
          'currency_code' => $currency,
        ],
        'field_is_default_ticket' => !empty($tier['default']) ? 1 : 0,
        'field_is_best_value' => ($multipleTiers && $i === 0) ? 1 : 0,
      ]);
      $this->ticketTierLifecycle->createAttachAndSync($event, $values);
    }
  }

  /**
   * @param array<string, mixed> $settings
   */
  private function populateRsvpTicket(NodeInterface $event, User $owner, array $settings): void {
    $values = $this->ticketTierLifecycle->buildTicketValuesFromInput($event, $owner, [
      'title' => 'General admission',
      'ticket_kind' => 'rsvp',
      'capacity' => (int) $settings['default_rsvp_capacity'],
      'field_is_default_ticket' => 1,
    ]);
    $this->ticketTierLifecycle->createAttachAndSync($event, $values);
  }

  /**
   * @param array<string, mixed> $settings
   */
  private function deterministicPrice(array $settings, int $eventIndex, int $tierOffset): float {
    $min = (float) $settings['min_ticket_price'];
    $max = (float) $settings['max_ticket_price'];
    if ($max <= $min) {
      return $min;
    }
    $range = (int) round(($max - $min) * 100);
    $offset = ($eventIndex * 13 + $tierOffset * 7) % ($range + 1);
    return round($min + ($offset / 100), 2);
  }

}
