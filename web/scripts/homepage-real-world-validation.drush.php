<?php

/**
 * @file
 * Populates realistic homepage validation content (local/staging only).
 *
 * Usage:
 *   ddev drush scr web/scripts/homepage-real-world-validation.drush.php
 *
 * Does NOT change PHP thresholds or theme code — content + metrics only.
 */

declare(strict_types=1);

use Drupal\Core\File\FileSystemInterface;
use Drupal\crop\Entity\Crop;
use Drupal\crop\CropInterface;
use Drupal\file\Entity\File;
use Drupal\focal_point\FocalPointManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;

const MEL_HWV_MARKER = 'mel_hwv:';

$tz = new DateTimeZone('Australia/Sydney');
$now = new DateTime('now', $tz);

/** @var \Drupal\Core\Entity\EntityTypeManagerInterface $etm */
$etm = \Drupal::entityTypeManager();
/** @var \Drupal\Core\Database\Connection $db */
$db = \Drupal::database();
/** @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheInvalidator */
$cacheInvalidator = \Drupal::service('cache_tags.invalidator');
/** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
$fileSystem = \Drupal::service('file_system');

$owner = User::load(1);
if (!$owner) {
  throw new RuntimeException('Owner user uid 1 (anna) not found.');
}

$categories = [
  'Arts' => 7,
  'Community' => 10,
  'Family' => 12,
  'Food & Drink' => 9,
  'LGBTQI+' => 8,
  'Markets' => 11,
  'Music' => 4,
  'Workshop' => 6,
];

$venues = [
  ['name' => 'Hyde Park', 'street' => 'Elizabeth Street', 'city' => 'Sydney', 'postcode' => '2000'],
  ['name' => 'Barangaroo Reserve', 'street' => 'Hickson Road', 'city' => 'Barangaroo', 'postcode' => '2000'],
  ['name' => 'Carriageworks', 'street' => '245 Wilson Street', 'city' => 'Eveleigh', 'postcode' => '2015'],
  ['name' => 'Bondi Pavilion', 'street' => 'Queen Elizabeth Drive', 'city' => 'Bondi Beach', 'postcode' => '2026'],
  ['name' => 'Darling Harbour', 'street' => 'Darling Harbour', 'city' => 'Sydney', 'postcode' => '2000'],
  ['name' => 'Royal Botanic Garden', 'street' => 'Mrs Macquaries Road', 'city' => 'Sydney', 'postcode' => '2000'],
  ['name' => 'Newtown Social Club', 'street' => 'King Street', 'city' => 'Newtown', 'postcode' => '2042'],
  ['name' => 'Enmore Theatre', 'street' => '130 Enmore Road', 'city' => 'Newtown', 'postcode' => '2042'],
];

$imageSources = [
  'festival-lights' => 'https://images.unsplash.com/photo-1514525253161-7a46d19cd819?w=1600&q=80&auto=format&fit=crop',
  'pride-picnic' => 'https://images.unsplash.com/photo-1529156069898-49953e39b3ac?w=1600&q=80&auto=format&fit=crop',
  'jazz-park' => 'https://images.unsplash.com/photo-1415201364774-f4f7ba45ff78?w=1600&q=80&auto=format&fit=crop',
  'makers-market' => 'https://images.unsplash.com/photo-1488459716781-31db76582da9?w=1600&q=80&auto=format&fit=crop',
  'food-trucks' => 'https://images.unsplash.com/photo-1569718212665-3a8278787938?w=1600&q=80&auto=format&fit=crop',
  'open-mic' => 'https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?w=1600&q=80&auto=format&fit=crop',
  'community-choir' => 'https://images.unsplash.com/photo-1511671782779-c97d3d27a0d4?w=1600&q=80&auto=format&fit=crop',
  'trivia-night' => 'https://images.unsplash.com/photo-1511512578047-dfb367046420?w=1600&q=80&auto=format&fit=crop',
  'salsa-social' => 'https://images.unsplash.com/photo-1504609770536-a67286670445?w=1600&q=80&auto=format&fit=crop',
  'comedy-club' => 'https://images.unsplash.com/photo-1585699320971-4caf1371049f?w=1600&q=80&auto=format&fit=crop',
  'drag-bingo' => 'https://images.unsplash.com/photo-1530103862676-de8c9debad1d?w=1600&q=80&auto=format&fit=crop',
  'yoga-park' => 'https://images.unsplash.com/photo-1544367567-0f2fcb009e0b?w=1600&q=80&auto=format&fit=crop',
  'sunset-sessions' => 'https://images.unsplash.com/photo-1470225620780-dba8ba36b745?w=1600&q=80&auto=format&fit=crop',
  'outdoor-cinema' => 'https://images.unsplash.com/photo-1489599849927-2ee91cedd3d4?w=1600&q=80&auto=format&fit=crop',
  'local-creators-market' => 'https://images.unsplash.com/photo-1441986300917-64644bd600d8?w=1600&q=80&auto=format&fit=crop',
  'volunteer-meetup' => 'https://images.unsplash.com/photo-1521737711867-e3b97375f902?w=1600&q=80&auto=format&fit=crop',
  'pride-walk' => 'https://images.unsplash.com/photo-1464983953574-0892a716854b?w=1600&q=80&auto=format&fit=crop',
  'family-fair' => 'https://images.unsplash.com/photo-1509099836629-18eb3605266d?w=1600&q=80&auto=format&fit=crop',
  'arts-workshop' => 'https://images.unsplash.com/photo-1460661419341-fcc204c948bc?w=1600&q=80&auto=format&fit=crop',
];

/**
 * Ensures image file exists and returns file ID.
 */
function mel_hwv_ensure_image(string $key, string $url, string $alt, FileSystemInterface $fileSystem): ?int {
  $directory = 'public://homepage-validation';
  $fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  $uri = $directory . '/' . $key . '.jpg';

  $existing = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
  if ($existing !== []) {
    $file = reset($existing);
    return $file instanceof File ? (int) $file->id() : NULL;
  }

  $data = @file_get_contents($url);
  if ($data === FALSE) {
    echo "  ⚠ Could not download image: {$key}\n";
    return NULL;
  }

  $path = $fileSystem->realpath($uri);
  if ($path === FALSE) {
    $path = $fileSystem->saveData($data, $uri, FileSystemInterface::EXISTS_REPLACE);
  }
  else {
    file_put_contents($path, $data);
  }

  if (!is_string($path) || !file_exists($path)) {
    echo "  ⚠ Could not save image: {$key}\n";
    return NULL;
  }

  $file = File::create([
    'uri' => $uri,
    'status' => 1,
  ]);
  $file->save();
  return (int) $file->id();
}

/**
 * Ensures focal_point crop data exists for merchandising validation.
 */
function mel_hwv_ensure_focal_point(int $fid, string $focal = '50,50'): void {
  if ($fid < 1) {
    return;
  }

  $file = File::load($fid);
  if (!$file instanceof File) {
    return;
  }

  $uri = $file->getFileUri();
  if ($uri === '') {
    return;
  }

  /** @var \Drupal\focal_point\FocalPointManagerInterface $focalManager */
  $focalManager = \Drupal::service('focal_point.manager');
  $imageFactory = \Drupal::service('image.factory');
  $image = $imageFactory->get($uri);
  if (!$image->isValid()) {
    return;
  }

  if (!$focalManager->validateFocalPoint($focal)) {
    $focal = '50,50';
  }

  [$xPct, $yPct] = array_map('intval', explode(',', $focal));
  $absolute = $focalManager->relativeToAbsolute(
    $xPct,
    $yPct,
    $image->getWidth(),
    $image->getHeight(),
  );

  $cropType = (string) \Drupal::config('focal_point.settings')->get('crop_type');
  if ($cropType === '') {
    $cropType = 'focal_point';
  }

  $crop = Crop::findCrop($uri, $cropType);
  if (!$crop instanceof CropInterface) {
    $crop = Crop::create([
      'type' => $cropType,
      'entity_id' => $fid,
      'entity_type' => 'file',
      'uri' => $uri,
    ]);
  }

  $crop->setPosition((int) $absolute['x'], (int) $absolute['y']);
  $crop->save();
}

/**
 * Upserts attendance metrics (content validation — not business logic).
 */
function mel_hwv_set_metrics(int $nid, int $going): void {
  $db = \Drupal::database();
  $cacheInvalidator = \Drupal::service('cache_tags.invalidator');
  $rsvp = (int) round($going * 0.55);
  $tickets = $going - $rsvp;
  $exists = $db->select('myeventlane_projection_event_metrics', 'm')
    ->fields('m', ['event_node_id'])
    ->condition('event_node_id', $nid)
    ->execute()
    ->fetchField();

  $fields = [
    'tickets_sold' => $tickets,
    'rsvp_count' => $rsvp,
    'updated_at' => time(),
  ];

  if ($exists) {
    $db->update('myeventlane_projection_event_metrics')
      ->fields($fields)
      ->condition('event_node_id', $nid)
      ->execute();
  }
  else {
    $db->insert('myeventlane_projection_event_metrics')
      ->fields($fields + ['event_node_id' => $nid, 'revenue_total' => 0, 'refund_count' => 0, 'checkin_count' => 0])
      ->execute();
  }
  $cacheInvalidator->invalidateTags([sprintf('event_metrics:%d', $nid)]);
}

/**
 * Applies common event fields.
 *
 * @param array{name: string, street: string, city: string, postcode: string} $venue
 */
function mel_hwv_apply_event_fields(
  NodeInterface $event,
  string $title,
  string $summary,
  string $body,
  array $venue,
  DateTime $start,
  DateTime $end,
  string $type,
  int $categoryTid,
  ?int $imageFid,
  bool $promoted,
  ?string $socialProof,
  int $vendorId = 36,
  int $storeId = 38,
): void {
  $event->setTitle($title);
  if ($event->hasField('field_event_summary')) {
    $event->set('field_event_summary', MEL_HWV_MARKER . ' ' . $summary);
  }
  if ($event->hasField('body')) {
    $event->set('body', ['value' => $body, 'format' => 'basic_html']);
  }
  if ($event->hasField('field_event_type')) {
    $event->set('field_event_type', $type);
  }
  if ($event->hasField('field_event_vendor')) {
    $event->set('field_event_vendor', ['target_id' => $vendorId]);
  }
  if ($event->hasField('field_event_store')) {
    $event->set('field_event_store', ['target_id' => $storeId]);
  }
  if ($event->hasField('field_event_start')) {
    $event->set('field_event_start', $start->format('Y-m-d\TH:i:s'));
  }
  if ($event->hasField('field_event_end')) {
    $event->set('field_event_end', $end->format('Y-m-d\TH:i:s'));
  }
  if ($event->hasField('field_event_state')) {
    $event->set('field_event_state', 'live');
  }
  if ($event->hasField('field_venue_name')) {
    $event->set('field_venue_name', $venue['name']);
  }
  if ($event->hasField('field_location')) {
    $event->set('field_location', [[
      'country_code' => 'AU',
      'address_line1' => $venue['street'],
      'locality' => $venue['city'],
      'administrative_area' => 'NSW',
      'postal_code' => $venue['postcode'],
    ]]);
  }
  if ($event->hasField('field_category')) {
    $event->set('field_category', ['target_id' => $categoryTid]);
  }
  if ($event->hasField('field_promoted')) {
    $event->set('field_promoted', $promoted ? 1 : 0);
  }
  if ($event->hasField('field_social_proof_visibility') && $socialProof !== NULL) {
    $event->set('field_social_proof_visibility', $socialProof);
  }
  if ($event->hasField('moderation_state')) {
    $event->set('moderation_state', 'published');
  }
  if ($event->hasField('field_event_setup_complete')) {
    $event->set('field_event_setup_complete', 1);
  }
  if ($event->hasField('field_sales_start') && $event->get('field_sales_start')->isEmpty()) {
    $salesStart = (clone $start)->modify('-2 days');
    $event->set('field_sales_start', $salesStart->format('Y-m-d\TH:i:s'));
  }
  if ($event->hasField('field_sales_end') && $event->get('field_sales_end')->isEmpty()) {
    $event->set('field_sales_end', $end->format('Y-m-d\TH:i:s'));
  }
  if ($event->hasField('field_capacity')) {
    $event->set('field_capacity', $type === 'rsvp' ? 120 : 200);
  }
  if ($imageFid && $event->hasField('field_event_image')) {
    $event->set('field_event_image', ['target_id' => $imageFid, 'alt' => $title]);
    mel_hwv_ensure_focal_point($imageFid);
  }
  if ($type === 'rsvp' && $event->hasField('field_product_target')) {
    $event->set('field_product_target', []);
  }
  $event->setPublished(TRUE);
  $event->setOwnerId(1);
}

/**
 * Loads or creates a validation event by seed key in summary.
 */
function mel_hwv_load_or_create(string $seedKey): NodeInterface {
  $nids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'event')
    ->condition('field_event_summary', '%' . MEL_HWV_MARKER . ' ' . $seedKey . '%', 'LIKE')
    ->range(0, 1)
    ->execute();
  if ($nids !== []) {
    $node = Node::load((int) reset($nids));
    if ($node instanceof NodeInterface) {
      return $node;
    }
  }
  return Node::create(['type' => 'event', 'uid' => 1, 'status' => 1]);
}

echo "MEL Homepage Real-World Validation — seeding content\n";
echo str_repeat('=', 60) . "\n";

// Demote non-validation promoted events.
$demoteNids = [1661, 1592];
foreach ($demoteNids as $demoteNid) {
  $node = Node::load($demoteNid);
  if ($node instanceof NodeInterface && $node->hasField('field_promoted')) {
    $node->set('field_promoted', 0);
    $node->save();
    echo "Demoted promoted flag on nid {$demoteNid}\n";
  }
}

$featured = [
  ['seed' => 'featured-01', 'nid' => 1660, 'title' => 'Community Pride Picnic', 'category' => 'LGBTQI+', 'image' => 'pride-picnic', 'going' => 38, 'type' => 'rsvp', 'days' => 5, 'hour' => 12],
  ['seed' => 'featured-02', 'nid' => 1681, 'title' => 'Jazz in the Park', 'category' => 'Music', 'image' => 'jazz-park', 'going' => 24, 'type' => 'rsvp', 'days' => 12, 'hour' => 17],
  ['seed' => 'featured-03', 'nid' => 1662, 'title' => 'Makers Market', 'category' => 'Markets', 'image' => 'makers-market', 'going' => 12, 'type' => 'paid', 'days' => 19, 'hour' => 10],
  ['seed' => 'featured-04', 'nid' => 1591, 'title' => 'Winter Festival of Lights', 'category' => 'Community', 'image' => 'festival-lights', 'going' => 44, 'type' => 'paid', 'days' => 24, 'hour' => 18],
  ['seed' => 'featured-05', 'nid' => 1664, 'title' => 'Food Truck Fridays', 'category' => 'Food & Drink', 'image' => 'food-trucks', 'going' => 18, 'type' => 'paid', 'days' => 33, 'hour' => 17],
  ['seed' => 'featured-06', 'title' => 'Sunset Sessions', 'category' => 'Music', 'image' => 'sunset-sessions', 'going' => 27, 'type' => 'rsvp', 'days' => 38, 'hour' => 18],
  ['seed' => 'featured-07', 'title' => 'Drag Bingo', 'category' => 'LGBTQI+', 'image' => 'drag-bingo', 'going' => 31, 'type' => 'rsvp', 'days' => 41, 'hour' => 20],
  ['seed' => 'featured-08', 'title' => 'Outdoor Cinema', 'category' => 'Community', 'image' => 'outdoor-cinema', 'going' => 52, 'type' => 'rsvp', 'days' => 45, 'hour' => 19, 'minute' => 30],
  ['seed' => 'featured-09', 'title' => 'Community Choir', 'category' => 'Community', 'image' => 'community-choir', 'going' => 16, 'type' => 'rsvp', 'days' => 47, 'hour' => 15],
  ['seed' => 'featured-10', 'title' => 'Local Creators Market', 'category' => 'Markets', 'image' => 'local-creators-market', 'going' => 21, 'type' => 'paid', 'days' => 50, 'hour' => 11],
];

$tonight = [
  ['seed' => 'tonight-01', 'title' => 'Open Mic Night', 'category' => 'Music', 'image' => 'open-mic', 'going' => 8, 'hour' => 19],
  ['seed' => 'tonight-02', 'title' => 'Community Choir', 'category' => 'Community', 'image' => 'community-choir', 'going' => 12, 'hour' => 19, 'minute' => 30],
  ['seed' => 'tonight-03', 'title' => 'Trivia Night', 'category' => 'Community', 'image' => 'trivia-night', 'going' => 18, 'hour' => 20],
  ['seed' => 'tonight-04', 'title' => 'Salsa Social', 'category' => 'Music', 'image' => 'salsa-social', 'going' => 24, 'hour' => 20, 'minute' => 30],
  ['seed' => 'tonight-05', 'title' => 'Comedy Club', 'category' => 'Arts', 'image' => 'comedy-club', 'going' => 39, 'hour' => 21],
  ['seed' => 'tonight-06', 'title' => 'Drag Bingo', 'category' => 'LGBTQI+', 'image' => 'drag-bingo', 'going' => 31, 'hour' => 21, 'minute' => 30],
  ['seed' => 'tonight-07', 'title' => 'Poetry Slam', 'category' => 'Arts', 'image' => 'open-mic', 'going' => 14, 'hour' => 18, 'minute' => 30],
  ['seed' => 'tonight-08', 'title' => 'Board Games Social', 'category' => 'Community', 'image' => 'trivia-night', 'going' => 11, 'hour' => 18],
  ['seed' => 'tonight-09', 'title' => 'Sunset Yoga', 'category' => 'Workshop', 'image' => 'yoga-park', 'going' => 22, 'hour' => 17, 'minute' => 30],
  ['seed' => 'tonight-10', 'title' => 'Laneway Live Sets', 'category' => 'Music', 'image' => 'jazz-park', 'going' => 16, 'hour' => 22],
];

$freeRsvp = [
  ['seed' => 'free-01', 'title' => 'Community Picnic', 'category' => 'Community', 'image' => 'pride-picnic', 'going' => 3, 'days' => 8],
  ['seed' => 'free-02', 'title' => 'Yoga in the Park', 'category' => 'Workshop', 'image' => 'yoga-park', 'going' => 8, 'days' => 10],
  ['seed' => 'free-03', 'title' => 'Sunday Makers Market', 'category' => 'Markets', 'image' => 'makers-market', 'going' => 16, 'days' => 14],
  ['seed' => 'free-04', 'title' => 'Pride Community Walk', 'category' => 'LGBTQI+', 'image' => 'pride-walk', 'going' => 25, 'days' => 16],
  ['seed' => 'free-05', 'title' => 'Volunteer Meetup', 'category' => 'Community', 'image' => 'volunteer-meetup', 'going' => 44, 'days' => 20],
  ['seed' => 'free-06', 'title' => 'Family Fun Fair', 'category' => 'Family', 'image' => 'family-fair', 'going' => 6, 'days' => 22],
  ['seed' => 'free-07', 'title' => 'Neighbourhood Potluck', 'category' => 'Community', 'image' => 'pride-picnic', 'going' => 9, 'days' => 26],
  ['seed' => 'free-08', 'title' => 'Beginners Salsa', 'category' => 'Music', 'image' => 'salsa-social', 'going' => 13, 'days' => 28],
  ['seed' => 'free-09', 'title' => 'Creative Arts Drop-in', 'category' => 'Arts', 'image' => 'arts-workshop', 'going' => 5, 'days' => 30],
  ['seed' => 'free-10', 'title' => 'Community Garden Day', 'category' => 'Community', 'image' => 'volunteer-meetup', 'going' => 21, 'days' => 35],
];

$latest = [
  ['seed' => 'latest-01', 'title' => 'Watercolour Workshop', 'category' => 'Arts', 'image' => 'arts-workshop', 'going' => 7, 'days' => 40, 'type' => 'rsvp'],
  ['seed' => 'latest-02', 'title' => 'Night Markets', 'category' => 'Markets', 'image' => 'makers-market', 'going' => 19, 'days' => 42, 'type' => 'rsvp'],
  ['seed' => 'latest-03', 'title' => 'Queer Film Night', 'category' => 'LGBTQI+', 'image' => 'pride-walk', 'going' => 15, 'days' => 44, 'type' => 'rsvp'],
  ['seed' => 'latest-04', 'title' => 'Indie Band Showcase', 'category' => 'Music', 'image' => 'open-mic', 'going' => 28, 'days' => 46, 'type' => 'paid'],
  ['seed' => 'latest-05', 'title' => 'Kids Circus Skills', 'category' => 'Family', 'image' => 'family-fair', 'going' => 11, 'days' => 48, 'type' => 'rsvp'],
  ['seed' => 'latest-06', 'title' => 'Street Food Festival', 'category' => 'Food & Drink', 'image' => 'food-trucks', 'going' => 33, 'days' => 50, 'type' => 'paid'],
  ['seed' => 'latest-07', 'title' => 'Community Choir Open Rehearsal', 'category' => 'Community', 'image' => 'community-choir', 'going' => 4, 'days' => 52, 'type' => 'rsvp'],
  ['seed' => 'latest-08', 'title' => 'Ceramics for Beginners', 'category' => 'Workshop', 'image' => 'arts-workshop', 'going' => 9, 'days' => 54, 'type' => 'rsvp'],
  ['seed' => 'latest-09', 'title' => 'Harbour Sunset Picnic', 'category' => 'Community', 'image' => 'pride-picnic', 'going' => 26, 'days' => 56, 'type' => 'rsvp'],
  ['seed' => 'latest-10', 'title' => 'Live Painting Jam', 'category' => 'Arts', 'image' => 'arts-workshop', 'going' => 17, 'days' => 58, 'type' => 'rsvp'],
];

$created = [];

echo "\nPhase 2 — Featured events (10)\n";
foreach ($featured as $i => $item) {
  $venue = $venues[$i % count($venues)];
  $start = clone $now;
  $start->modify('+' . $item['days'] . ' days');
  $start->setTime($item['hour'], $item['minute'] ?? 0);
  $end = clone $start;
  $end->modify('+3 hours');

  $fid = mel_hwv_ensure_image($item['image'], $imageSources[$item['image']], $item['title'], $fileSystem);
  $existingNid = $item['nid'] ?? NULL;
  $event = ($existingNid !== NULL ? Node::load($existingNid) : NULL) ?? mel_hwv_load_or_create($item['seed']);
  mel_hwv_apply_event_fields(
    $event,
    $item['title'],
    $item['seed'] . ' ' . $item['title'],
    '<p>Join locals for <strong>' . $item['title'] . '</strong> — a hand-picked community moment on MyEventLane.</p>',
    $venue,
    $start,
    $end,
    $item['type'],
    $categories[$item['category']],
    $fid,
    TRUE,
    'show',
  );
  $event->save();
  mel_hwv_set_metrics((int) $event->id(), $item['going']);
  $created[] = ['section' => 'featured', 'nid' => (int) $event->id(), 'title' => $item['title'], 'going' => $item['going']];
  echo "  ✓ Featured nid {$event->id()}: {$item['title']} ({$item['going']} going)\n";
}

echo "\nPhase 2 — Tonight events (10)\n";
foreach ($tonight as $i => $item) {
  $venue = $venues[$i % count($venues)];
  $start = clone $now;
  $start->setTime($item['hour'], $item['minute'] ?? 0);
  if ($start <= $now) {
    $start->modify('+1 hour');
  }
  $end = clone $start;
  $end->modify('+2 hours');

  $fid = mel_hwv_ensure_image($item['image'], $imageSources[$item['image']], $item['title'], $fileSystem);
  $event = mel_hwv_load_or_create($item['seed']);
  mel_hwv_apply_event_fields(
    $event,
    $item['title'],
    $item['seed'] . ' ' . $item['title'],
    '<p>Tonight in Sydney: <strong>' . $item['title'] . '</strong>. Drop in, meet people, stay for one set.</p>',
    $venue,
    $start,
    $end,
    'rsvp',
    $categories[$item['category']],
    $fid,
    FALSE,
    'show',
  );
  $event->save();
  mel_hwv_set_metrics((int) $event->id(), $item['going']);
  $created[] = ['section' => 'tonight', 'nid' => (int) $event->id(), 'title' => $item['title'], 'going' => $item['going']];
  echo "  ✓ Tonight nid {$event->id()}: {$item['title']} @ {$start->format('H:i')} ({$item['going']} going)\n";
}

echo "\nPhase 2 — Free RSVP events (10)\n";
foreach ($freeRsvp as $i => $item) {
  $venue = $venues[$i % count($venues)];
  $start = clone $now;
  $start->modify('+' . $item['days'] . ' days');
  $start->setTime(11, 0);
  $end = clone $start;
  $end->modify('+4 hours');

  $fid = mel_hwv_ensure_image($item['image'], $imageSources[$item['image']], $item['title'], $fileSystem);
  $event = mel_hwv_load_or_create($item['seed']);
  mel_hwv_apply_event_fields(
    $event,
    $item['title'],
    $item['seed'] . ' ' . $item['title'],
    '<p>Free to join — <strong>' . $item['title'] . '</strong>. RSVP so organisers know you are coming.</p>',
    $venue,
    $start,
    $end,
    'rsvp',
    $categories[$item['category']],
    $fid,
    FALSE,
    'show',
  );
  $event->save();
  mel_hwv_set_metrics((int) $event->id(), $item['going']);
  $created[] = ['section' => 'free', 'nid' => (int) $event->id(), 'title' => $item['title'], 'going' => $item['going']];
  echo "  ✓ Free RSVP nid {$event->id()}: {$item['title']} ({$item['going']} going)\n";
}

echo "\nPhase 2 — Latest events (10)\n";
foreach ($latest as $i => $item) {
  $venue = $venues[$i % count($venues)];
  $start = clone $now;
  $start->modify('+' . $item['days'] . ' days');
  $start->setTime(14, 0);
  $end = clone $start;
  $end->modify('+3 hours');

  $fid = mel_hwv_ensure_image($item['image'], $imageSources[$item['image']], $item['title'], $fileSystem);
  $event = mel_hwv_load_or_create($item['seed']);
  mel_hwv_apply_event_fields(
    $event,
    $item['title'],
    $item['seed'] . ' ' . $item['title'],
    '<p>Fresh on MyEventLane: <strong>' . $item['title'] . '</strong>.</p>',
    $venue,
    $start,
    $end,
    $item['type'],
    $categories[$item['category']],
    $fid,
    FALSE,
    $item['type'] === 'rsvp' ? 'show' : 'auto',
  );
  $event->save();
  mel_hwv_set_metrics((int) $event->id(), $item['going']);
  $created[] = ['section' => 'latest', 'nid' => (int) $event->id(), 'title' => $item['title'], 'going' => $item['going']];
  echo "  ✓ Latest nid {$event->id()}: {$item['title']} ({$item['going']} going)\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Seeded " . count($created) . " validation events.\n";
echo "Run: ddev drush cr\n";
