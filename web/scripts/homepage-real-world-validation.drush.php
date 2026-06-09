<?php

/**
 * @file
 * Idempotent homepage merchandising fixtures for staging and local (DDEV).
 *
 * Creates additive MEL-STAGING-* content only. Never modifies existing staging
 * organisers, events, media, or follower relationships.
 *
 * Usage (staging — from release root, e.g. ~/staging/current):
 *   SITE_URI=https://staging.myeventlane.com.au \
 *     php -d memory_limit=1024M vendor/bin/drush.php scr \
 *     web/scripts/homepage-real-world-validation.drush.php --uri="$SITE_URI"
 *
 * Alternative host wrapper (if present):
 *   ~/bin/drush512 scr web/scripts/homepage-real-world-validation.drush.php \
 *     -l https://staging.myeventlane.com.au
 *
 * Usage (local DDEV):
 *   ddev drush scr web/scripts/homepage-real-world-validation.drush.php
 *
 * Cleanup (MEL-STAGING-* only):
 *   … scr web/scripts/homepage-real-world-validation.drush.php -- --cleanup
 *
 * Audit after seeding:
 *   php -d memory_limit=1024M vendor/bin/drush.php scr \
 *     web/scripts/audit-homepage-gate.drush.php --uri="$SITE_URI"
 */

declare(strict_types=1);

use Drupal\commerce_store\Entity\Store;
use Drupal\Core\File\FileSystemInterface;
use Drupal\crop\Entity\Crop;
use Drupal\crop\CropInterface;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use Drupal\myeventlane_vendor\Entity\Vendor;

const MEL_STAGING_PREFIX = 'MEL-STAGING-';
const MEL_STAGING_MARKER = 'MEL-STAGING-FIXTURE';
const MEL_STAGING_USER = 'MEL-STAGING-VALIDATION-USER';
const MEL_STAGING_USER_MAIL = 'mel-staging-validation@myeventlane.invalid';
const MEL_STAGING_FOLLOWER = 'MEL-STAGING-FOLLOWER-001';
const MEL_STAGING_FOLLOWER_MAIL = 'mel-staging-follower@myeventlane.invalid';
const MEL_STAGING_ORGANISER = 'MEL-STAGING-ORGANISER-001';
const MEL_STAGING_IMAGE_DIR = 'public://mel-staging-validation';

/**
 * Whether APP_ENV / domain config indicates production.
 */
function mel_staging_is_production(): bool {
  if (getenv('IS_DDEV_PROJECT') === 'true') {
    return FALSE;
  }
  if (mel_staging_is_staging()) {
    return FALSE;
  }

  $app_env = strtolower((string) (getenv('APP_ENV') ?: ''));
  if (in_array($app_env, ['staging', 'stage', 'local', 'dev', 'development'], TRUE)) {
    return FALSE;
  }
  if (in_array($app_env, ['production', 'prod'], TRUE)) {
    return TRUE;
  }

  $public = strtolower((string) \Drupal::config('myeventlane_core.domain_settings')->get('public_domain'));
  if (in_array($public, ['https://myeventlane.com.au', 'http://myeventlane.com.au'], TRUE)) {
    return TRUE;
  }

  return FALSE;
}

/**
 * Whether the environment is confirmed staging or local.
 */
function mel_staging_is_staging(): bool {
  if (getenv('IS_DDEV_PROJECT') === 'true') {
    return TRUE;
  }

  $app_env = strtolower((string) (getenv('APP_ENV') ?: ''));
  if (in_array($app_env, ['staging', 'stage'], TRUE)) {
    return TRUE;
  }

  $public = strtolower((string) \Drupal::config('myeventlane_core.domain_settings')->get('public_domain'));
  if (str_contains($public, 'ddev.site') || str_contains($public, 'staging.myeventlane.com')) {
    return TRUE;
  }

  if (\Drupal::config('system.site')->get('staging_environment')) {
    return TRUE;
  }

  $env_staging = getenv('STAGING_ENVIRONMENT');
  if ($env_staging === '1' || $env_staging === 'true') {
    return TRUE;
  }

  $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
  if ($host !== '') {
    $known = [
      'staging.myeventlane.com.au',
      'vendor.staging.myeventlane.com.au',
      'admin.staging.myeventlane.com.au',
      'staging.myeventlane.com',
      'vendor.staging.myeventlane.com',
      'admin.staging.myeventlane.com',
    ];
    if (in_array($host, $known, TRUE)) {
      return TRUE;
    }
    if (str_contains($host, 'staging.myeventlane.com.au') || str_contains($host, 'staging.myeventlane.com')) {
      return TRUE;
    }
  }

  return FALSE;
}

/**
 * Refuses production; requires confirmed staging or DDEV.
 */
function mel_staging_assert_allowed(): void {
  if (mel_staging_is_production()) {
    throw new RuntimeException('Refusing to run homepage validation fixtures on production.');
  }
  if (!mel_staging_is_staging()) {
    throw new RuntimeException(
      'Refusing to run: environment is not confirmed staging or local (DDEV). '
      . 'Set APP_ENV=staging, STAGING_ENVIRONMENT=1, or run via DDEV.'
    );
  }
}

/**
 * Parses script CLI args (supports `drush scr script.php -- --cleanup`).
 *
 * @return list<string>
 */
function mel_staging_script_args(): array {
  $argv = $_SERVER['argv'] ?? [];
  $args = [];
  $past_script = FALSE;
  foreach ($argv as $arg) {
    if ($past_script) {
      $args[] = $arg;
      continue;
    }
    if (str_contains((string) $arg, 'homepage-real-world-validation')) {
      $past_script = TRUE;
    }
  }
  return $args;
}

/**
 * Full fixture title from key suffix (e.g. HERO-001).
 */
function mel_staging_title(string $key): string {
  return MEL_STAGING_PREFIX . $key;
}

/**
 * Loads taxonomy term ID by name from categories vocabulary.
 */
function mel_staging_category_tid(string $name): ?int {
  static $cache = NULL;
  if ($cache === NULL) {
    $cache = [];
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'categories']);
    foreach ($terms as $term) {
      if ($term instanceof Term) {
        $cache[(string) $term->label()] = (int) $term->id();
      }
    }
  }
  return $cache[$name] ?? NULL;
}

/**
 * Ensures image file exists under mel-staging-validation and returns file ID.
 *
 * Tries remote URL first (local/DDEV); falls back to GD placeholder when outbound
 * HTTP is blocked on staging hosts.
 */
function mel_staging_ensure_image(string $key, string $url, string $alt, FileSystemInterface $fileSystem): ?int {
  // prepareDirectory() requires a variable (by reference); constants fail on PHP 8.3.
  $directory = MEL_STAGING_IMAGE_DIR;
  if (!$fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
    echo "  ⚠ Could not prepare image directory: {$directory}\n";
    return NULL;
  }

  $uri = $directory . '/' . $key . '.jpg';

  $existing = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
  if ($existing !== []) {
    $file = reset($existing);
    return $file instanceof File ? (int) $file->id() : NULL;
  }

  $data = @file_get_contents($url);
  if ($data === FALSE) {
    $data = mel_staging_placeholder_jpeg($alt !== '' ? $alt : $key);
    if ($data === NULL) {
      echo "  ⚠ Could not download or generate image: {$key}\n";
      return NULL;
    }
    echo "  · Placeholder image for {$key} (remote download unavailable)\n";
  }

  $path = $fileSystem->saveData($data, $uri, FileSystemInterface::EXISTS_REPLACE);
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
 * Builds a local JPEG placeholder (no outbound HTTP required).
 */
function mel_staging_placeholder_jpeg(string $label, int $width = 1600, int $height = 900): ?string {
  if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
    return NULL;
  }

  $image = imagecreatetruecolor($width, $height);
  if ($image === FALSE) {
    return NULL;
  }

  $hash = crc32($label);
  $r1 = 180 + ($hash & 0x3f);
  $g1 = 200 + (($hash >> 6) & 0x2f);
  $b1 = 230 + (($hash >> 12) & 0x1f);
  $r2 = 240 + (($hash >> 18) & 0x0f);
  $g2 = 210 + (($hash >> 24) & 0x2f);
  $b2 = 220 + (($hash >> 28) & 0x1f);
  for ($y = 0; $y < $height; $y++) {
    $ratio = $height > 1 ? $y / ($height - 1) : 0;
    $r = (int) ($r1 * (1 - $ratio) + $r2 * $ratio);
    $g = (int) ($g1 * (1 - $ratio) + $g2 * $ratio);
    $b = (int) ($b1 * (1 - $ratio) + $b2 * $ratio);
    $line = imagecolorallocate($image, min(255, $r), min(255, $g), min(255, $b));
    imageline($image, 0, $y, $width, $y, $line);
  }

  $text = substr($label, 0, 48);
  $textColor = imagecolorallocate($image, 40, 40, 40);
  $font = 5;
  $textWidth = imagefontwidth($font) * strlen($text);
  $textHeight = imagefontheight($font);
  imagestring(
    $image,
    $font,
    (int) max(8, ($width - $textWidth) / 2),
    (int) max(8, ($height - $textHeight) / 2),
    $text,
    $textColor,
  );

  ob_start();
  $written = imagejpeg($image, NULL, 88);
  $data = ob_get_clean();
  imagedestroy($image);

  if (!$written || !is_string($data) || $data === '') {
    return NULL;
  }

  return $data;
}

/**
 * Ensures focal_point crop data exists for merchandising validation.
 */
function mel_staging_ensure_focal_point(int $fid, string $focal = '50,50'): void {
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
  $image = \Drupal::service('image.factory')->get($uri);
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
 * Removes focal crops so BoostedEventQualityGate treats the hero as ineligible.
 */
function mel_staging_remove_focal_point(int $fid): void {
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

  foreach (['focal_point', 'event_hero', (string) \Drupal::config('focal_point.settings')->get('crop_type')] as $type) {
    if ($type === '') {
      continue;
    }
    $crop = Crop::findCrop($uri, $type);
    if ($crop instanceof CropInterface) {
      $crop->delete();
    }
  }
}

/**
 * Upserts attendance metrics for social-proof validation.
 */
function mel_staging_set_metrics(int $nid, int $going): void {
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
      ->fields($fields + [
        'event_node_id' => $nid,
        'revenue_total' => 0,
        'refund_count' => 0,
        'checkin_count' => 0,
      ])
      ->execute();
  }
  $cacheInvalidator->invalidateTags([sprintf('event_metrics:%d', $nid)]);
}

/**
 * Loads or creates a user by deterministic account name.
 */
function mel_staging_load_or_create_user(string $name, string $mail): User {
  $storage = \Drupal::entityTypeManager()->getStorage('user');
  $existing = $storage->loadByProperties(['name' => $name]);
  if ($existing !== []) {
    $user = reset($existing);
    if ($user instanceof User) {
      return $user;
    }
  }

  $user = User::create([
    'name' => $name,
    'mail' => $mail,
    'status' => 1,
    'roles' => ['authenticated'],
  ]);
  $user->enforceIsNew();
  $user->save();
  return $user;
}

/**
 * Loads or creates the dedicated staging organiser vendor + store.
 *
 * @return array{vendor: \Drupal\myeventlane_vendor\Entity\Vendor, store: \Drupal\commerce_store\Entity\StoreInterface, user: \Drupal\user\Entity\User}
 */
function mel_staging_ensure_organiser(): array {
  $user = mel_staging_load_or_create_user(MEL_STAGING_USER, MEL_STAGING_USER_MAIL);
  $vendorStorage = \Drupal::entityTypeManager()->getStorage('myeventlane_vendor');
  $storeStorage = \Drupal::entityTypeManager()->getStorage('commerce_store');

  $vendors = $vendorStorage->loadByProperties(['name' => MEL_STAGING_ORGANISER]);
  if ($vendors !== []) {
    $vendor = reset($vendors);
  }
  else {
    $byOwner = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', (int) $user->id())
      ->range(0, 1)
      ->execute();
    if ($byOwner !== []) {
      $vendor = $vendorStorage->load((int) reset($byOwner));
      if ($vendor instanceof Vendor) {
        $vendor->set('name', MEL_STAGING_ORGANISER);
        $vendor->save();
      }
    }
    else {
      $vendor = Vendor::create([
        'name' => MEL_STAGING_ORGANISER,
        'uid' => (int) $user->id(),
      ]);
      $vendor->save();
    }
  }

  if (!$vendor instanceof Vendor) {
    throw new RuntimeException('Could not create MEL-STAGING organiser vendor.');
  }

  $store = NULL;
  if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
    $store = $vendor->get('field_vendor_store')->entity;
  }

  if (!$store instanceof Store) {
    $store = Store::create([
      'type' => 'online',
      'uid' => (int) $user->id(),
      'name' => MEL_STAGING_ORGANISER . ' Store',
      'mail' => MEL_STAGING_USER_MAIL,
      'default_currency' => 'AUD',
      'timezone' => 'Australia/Sydney',
      'address' => [
        'country_code' => 'AU',
        'locality' => 'Sydney',
        'address_line1' => '1 Staging Lane',
        'postal_code' => '2000',
      ],
      'billing_countries' => ['AU'],
      'is_default' => FALSE,
      'status' => TRUE,
    ]);
    if ($store->hasField('field_vendor_reference')) {
      $store->set('field_vendor_reference', $vendor);
    }
    $store->save();
    $vendor->set('field_vendor_store', $store);
    $vendor->save();
  }

  return [
    'vendor' => $vendor,
    'store' => $store,
    'user' => $user,
  ];
}

/**
 * Ensures follower user follows the staging organiser (idempotent).
 */
function mel_staging_ensure_follow(Vendor $vendor): void {
  $follower = mel_staging_load_or_create_user(MEL_STAGING_FOLLOWER, MEL_STAGING_FOLLOWER_MAIL);
  /** @var \Drupal\myeventlane_core\Service\VendorFollowService $followService */
  $followService = \Drupal::service('myeventlane_core.vendor_follow');
  if (!$followService->isFollowing($follower, $vendor)) {
    $followService->follow($follower, $vendor);
    echo "  ✓ Follow: {$follower->getAccountName()} → " . MEL_STAGING_ORGANISER . "\n";
  }
  else {
    echo "  ✓ Follow already exists: {$follower->getAccountName()} → " . MEL_STAGING_ORGANISER . "\n";
  }
}

/**
 * Loads or creates an event by deterministic MEL-STAGING title.
 */
function mel_staging_load_or_create_event(string $key): NodeInterface {
  $title = mel_staging_title($key);
  $nids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'event')
    ->condition('title', $title)
    ->range(0, 1)
    ->execute();
  if ($nids !== []) {
    $node = Node::load((int) reset($nids));
    if ($node instanceof NodeInterface) {
      return $node;
    }
  }
  return Node::create(['type' => 'event', 'status' => 1]);
}

/**
 * Applies common event fields for a staging fixture.
 *
 * @param array{name: string, street: string, city: string, postcode: string} $venue
 */
function mel_staging_apply_event_fields(
  NodeInterface $event,
  string $key,
  string $summary,
  string $body,
  array $venue,
  DateTime $start,
  DateTime $end,
  string $type,
  ?int $categoryTid,
  ?int $imageFid,
  bool $promoted,
  ?string $socialProof,
  Vendor $vendor,
  Store $store,
  User $owner,
  bool $ensureFocal = TRUE,
): void {
  $title = mel_staging_title($key);
  $event->setTitle($title);
  if ($event->hasField('field_event_summary')) {
    $event->set('field_event_summary', MEL_STAGING_MARKER . ':' . $key . ' ' . $summary);
  }
  if ($event->hasField('body')) {
    $event->set('body', ['value' => $body, 'format' => 'basic_html']);
  }
  if ($event->hasField('field_event_type')) {
    $event->set('field_event_type', $type);
  }
  if ($event->hasField('field_event_vendor')) {
    $event->set('field_event_vendor', ['target_id' => (int) $vendor->id()]);
  }
  if ($event->hasField('field_event_store')) {
    $event->set('field_event_store', ['target_id' => (int) $store->id()]);
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
  if ($categoryTid && $event->hasField('field_category')) {
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
    if ($ensureFocal) {
      mel_staging_ensure_focal_point($imageFid);
    }
    else {
      mel_staging_remove_focal_point($imageFid);
    }
  }
  if ($type === 'rsvp' && $event->hasField('field_product_target')) {
    $event->set('field_product_target', []);
  }
  $event->setPublished(TRUE);
  $event->setOwnerId((int) $owner->id());
}

/**
 * Deletes all MEL-STAGING-* fixture content.
 */
function mel_staging_cleanup(): void {
  echo "MEL-STAGING cleanup — removing fixture content only\n";
  echo str_repeat('=', 60) . "\n";

  mel_staging_assert_allowed();

  $etm = \Drupal::entityTypeManager();
  $db = \Drupal::database();
  $fileSystem = \Drupal::service('file_system');

  $nids = $etm->getStorage('node')->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'event')
    ->condition('title', MEL_STAGING_PREFIX . '%', 'LIKE')
    ->execute();

  if ($nids !== []) {
    foreach ($nids as $nid) {
      $db->delete('myeventlane_projection_event_metrics')
        ->condition('event_node_id', (int) $nid)
        ->execute();
    }
    $nodes = Node::loadMultiple($nids);
    $etm->getStorage('node')->delete($nodes);
    echo '  ✓ Deleted ' . count($nids) . " staging event(s)\n";
  }
  else {
    echo "  · No staging events found\n";
  }

  $vendors = $etm->getStorage('myeventlane_vendor')->loadByProperties(['name' => MEL_STAGING_ORGANISER]);
  foreach ($vendors as $vendor) {
    if (!$vendor instanceof Vendor) {
      continue;
    }
    $followIds = $etm->getStorage('myeventlane_vendor_follow')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vendor_id', (int) $vendor->id())
      ->execute();
    if ($followIds !== []) {
      $etm->getStorage('myeventlane_vendor_follow')->delete(
        $etm->getStorage('myeventlane_vendor_follow')->loadMultiple($followIds),
      );
      echo '  ✓ Deleted ' . count($followIds) . " follow row(s) for staging organiser\n";
    }

    $store = NULL;
    if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
    }
    $vendor->delete();
    echo "  ✓ Deleted staging organiser vendor\n";
    if ($store instanceof Store) {
      $store->delete();
      echo "  ✓ Deleted staging organiser store\n";
    }
  }

  foreach ([MEL_STAGING_USER, MEL_STAGING_FOLLOWER] as $accountName) {
    $users = $etm->getStorage('user')->loadByProperties(['name' => $accountName]);
    if ($users !== []) {
      $user = reset($users);
      if ($user instanceof User) {
        $user->delete();
        echo "  ✓ Deleted staging user {$accountName}\n";
      }
    }
  }

  $imageDir = MEL_STAGING_IMAGE_DIR;
  $realImageDir = $fileSystem->realpath($imageDir);
  if (is_string($realImageDir) && is_dir($realImageDir)) {
    $fileSystem->deleteRecursive($imageDir);
    echo "  ✓ Deleted staging image directory\n";
  }

  \Drupal::service('cache_tags.invalidator')->invalidateTags([
    'node_list:event',
    'myeventlane_vendor_follow_list',
  ]);
  \Drupal::cache()->delete('homepage_organiser_spotlight');

  echo str_repeat('=', 60) . "\n";
  echo "Cleanup complete. Run cache rebuild.\n";
}

// --- Main ---

$args = mel_staging_script_args();
if (in_array('--cleanup', $args, TRUE)) {
  mel_staging_cleanup();
  return;
}

mel_staging_assert_allowed();

$tz = new DateTimeZone('Australia/Sydney');
$now = new DateTime('now', $tz);

/** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
$fileSystem = \Drupal::service('file_system');

echo "MEL-STAGING homepage validation — seeding additive fixtures\n";
echo str_repeat('=', 60) . "\n";
echo 'Environment: ' . (getenv('IS_DDEV_PROJECT') === 'true' ? 'DDEV' : (getenv('APP_ENV') ?: 'staging/local')) . "\n\n";

$organiser = mel_staging_ensure_organiser();
$vendor = $organiser['vendor'];
$store = $organiser['store'];
$owner = $organiser['user'];

echo "Organiser: " . MEL_STAGING_ORGANISER . " (vendor {$vendor->id()}, store {$store->id()})\n\n";

$venues = [
  ['name' => 'Hyde Park', 'street' => 'Elizabeth Street', 'city' => 'Sydney', 'postcode' => '2000'],
  ['name' => 'Barangaroo Reserve', 'street' => 'Hickson Road', 'city' => 'Barangaroo', 'postcode' => '2000'],
  ['name' => 'Carriageworks', 'street' => '245 Wilson Street', 'city' => 'Eveleigh', 'postcode' => '2015'],
  ['name' => 'Bondi Pavilion', 'street' => 'Queen Elizabeth Drive', 'city' => 'Bondi Beach', 'postcode' => '2026'],
];

$images = [
  'hero' => 'https://images.unsplash.com/photo-1514525253161-7a46d19cd819?w=1600&q=80&auto=format&fit=crop',
  'spotlight' => 'https://images.unsplash.com/photo-1529156069898-49953e39b3ac?w=1600&q=80&auto=format&fit=crop',
  'tonight' => 'https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?w=1600&q=80&auto=format&fit=crop',
  'discover' => 'https://images.unsplash.com/photo-1488459716781-31db76582da9?w=1600&q=80&auto=format&fit=crop',
  'rsvp' => 'https://images.unsplash.com/photo-1544367567-0f2fcb009e0b?w=1600&q=80&auto=format&fit=crop',
  'latest' => 'https://images.unsplash.com/photo-1460661419341-fcc204c948bc?w=1600&q=80&auto=format&fit=crop',
  'gate-fail' => 'https://images.unsplash.com/photo-1530103862676-de8c9debad1d?w=1600&q=80&auto=format&fit=crop',
];

$categoryCommunity = mel_staging_category_tid('Community') ?? mel_staging_category_tid('Music');
$categoryMusic = mel_staging_category_tid('Music') ?? $categoryCommunity;
$categoryWorkshop = mel_staging_category_tid('Workshop') ?? $categoryCommunity;

/**
 * @var list<array<string, mixed>> $fixtures
 */
$fixtures = [
  [
    'key' => 'HERO-001',
    'section' => 'hero',
    'summary' => 'Lead promoted hero fixture for rotation validation.',
    'image' => 'hero',
    'going' => 44,
    'type' => 'paid',
    'promoted' => TRUE,
    'focal' => TRUE,
    'category' => $categoryCommunity,
    'schedule' => static function (DateTime $now): array {
      $start = (clone $now)->modify('+4 days')->setTime(18, 0);
      $end = (clone $start)->modify('+3 hours');
      return [$start, $end];
    },
  ],
  [
    'key' => 'SPOTLIGHT-001',
    'section' => 'spotlight',
    'summary' => 'Spotlight carousel fixture (marketplace-ready).',
    'image' => 'spotlight',
    'going' => 31,
    'type' => 'rsvp',
    'promoted' => TRUE,
    'focal' => TRUE,
    'category' => $categoryMusic,
    'schedule' => static function (DateTime $now): array {
      $start = (clone $now)->modify('+8 days')->setTime(19, 0);
      $end = (clone $start)->modify('+2 hours');
      return [$start, $end];
    },
  ],
  [
    'key' => 'SPOTLIGHT-002',
    'section' => 'spotlight',
    'summary' => 'Second spotlight fixture for featured experiences carousel.',
    'image' => 'spotlight',
    'going' => 22,
    'type' => 'rsvp',
    'promoted' => TRUE,
    'focal' => TRUE,
    'category' => $categoryCommunity,
    'schedule' => static function (DateTime $now): array {
      $start = (clone $now)->modify('+12 days')->setTime(17, 30);
      $end = (clone $start)->modify('+3 hours');
      return [$start, $end];
    },
  ],
  [
    'key' => 'GATE-FAIL-001',
    'section' => 'quality-gate',
    'summary' => 'Promoted but missing focal point — ineligible for hero/spotlight.',
    'image' => 'gate-fail',
    'going' => 9,
    'type' => 'rsvp',
    'promoted' => TRUE,
    'focal' => FALSE,
    'category' => $categoryCommunity,
    'schedule' => static function (DateTime $now): array {
      $start = (clone $now)->modify('+6 days')->setTime(20, 0);
      $end = (clone $start)->modify('+2 hours');
      return [$start, $end];
    },
  ],
  [
    'key' => 'TONIGHT-001',
    'section' => 'tonight',
    'summary' => 'Tonight rail fixture (evening start).',
    'image' => 'tonight',
    'going' => 18,
    'type' => 'rsvp',
    'promoted' => FALSE,
    'focal' => TRUE,
    'category' => $categoryMusic,
    'schedule' => static function (DateTime $now): array {
      $start = (clone $now)->setTime(19, 30);
      if ($start <= $now) {
        $start->modify('+1 hour');
      }
      $end = (clone $start)->modify('+2 hours');
      return [$start, $end];
    },
  ],
  [
    'key' => 'TONIGHT-002',
    'section' => 'tonight',
    'summary' => 'Second tonight rail fixture.',
    'image' => 'tonight',
    'going' => 12,
    'type' => 'rsvp',
    'promoted' => FALSE,
    'focal' => TRUE,
    'category' => $categoryCommunity,
    'schedule' => static function (DateTime $now): array {
      $start = (clone $now)->setTime(20, 30);
      if ($start <= $now) {
        $start->modify('+90 minutes');
      }
      $end = (clone $start)->modify('+2 hours');
      return [$start, $end];
    },
  ],
  [
    'key' => 'DISCOVER-001',
    'section' => 'discover',
    'summary' => 'Discover rail fixture.',
    'image' => 'discover',
    'going' => 16,
    'type' => 'paid',
    'promoted' => FALSE,
    'focal' => TRUE,
    'category' => $categoryCommunity,
    'schedule' => static function (DateTime $now): array {
      $start = (clone $now)->modify('+15 days')->setTime(11, 0);
      $end = (clone $start)->modify('+4 hours');
      return [$start, $end];
    },
  ],
  [
    'key' => 'DISCOVER-002',
    'section' => 'discover',
    'summary' => 'Second discover rail fixture.',
    'image' => 'discover',
    'going' => 8,
    'type' => 'rsvp',
    'promoted' => FALSE,
    'focal' => TRUE,
    'category' => $categoryMusic,
    'schedule' => static function (DateTime $now): array {
      $start = (clone $now)->modify('+18 days')->setTime(14, 0);
      $end = (clone $start)->modify('+3 hours');
      return [$start, $end];
    },
  ],
  [
    'key' => 'RSVP-001',
    'section' => 'free-rsvp',
    'summary' => 'Free & RSVP (under_20) rail fixture.',
    'image' => 'rsvp',
    'going' => 5,
    'type' => 'rsvp',
    'promoted' => FALSE,
    'focal' => TRUE,
    'category' => $categoryWorkshop,
    'schedule' => static function (DateTime $now): array {
      $start = (clone $now)->modify('+10 days')->setTime(10, 0);
      $end = (clone $start)->modify('+2 hours');
      return [$start, $end];
    },
  ],
  [
    'key' => 'LATEST-001',
    'section' => 'latest',
    'summary' => 'Latest events rail fixture.',
    'image' => 'latest',
    'going' => 14,
    'type' => 'rsvp',
    'promoted' => FALSE,
    'focal' => TRUE,
    'category' => $categoryCommunity,
    'schedule' => static function (DateTime $now): array {
      $start = (clone $now)->modify('+22 days')->setTime(15, 0);
      $end = (clone $start)->modify('+2 hours');
      return [$start, $end];
    },
  ],
  [
    'key' => 'RECOMMENDED-001',
    'section' => 'recommended',
    'summary' => 'Recommended events rail fixture.',
    'image' => 'discover',
    'going' => 20,
    'type' => 'rsvp',
    'promoted' => FALSE,
    'focal' => TRUE,
    'category' => $categoryMusic,
    'schedule' => static function (DateTime $now): array {
      $start = (clone $now)->modify('+25 days')->setTime(16, 0);
      $end = (clone $start)->modify('+3 hours');
      return [$start, $end];
    },
  ],
];

$created = [];

foreach ($fixtures as $i => $item) {
  $venue = $venues[$i % count($venues)];
  [$start, $end] = $item['schedule']($now);
  $imageKey = 'mel-staging-' . $item['image'];
  $fid = mel_staging_ensure_image(
    $imageKey,
    $images[$item['image']],
    mel_staging_title($item['key']),
    $fileSystem,
  );

  $event = mel_staging_load_or_create_event($item['key']);
  mel_staging_apply_event_fields(
    $event,
    $item['key'],
    $item['summary'],
    '<p>Staging validation fixture: <strong>' . mel_staging_title($item['key']) . '</strong></p>',
    $venue,
    $start,
    $end,
    $item['type'],
    $item['category'],
    $fid,
    $item['promoted'],
    'show',
    $vendor,
    $store,
    $owner,
    $item['focal'],
  );
  $event->save();
  mel_staging_set_metrics((int) $event->id(), $item['going']);
  $created[] = [
    'section' => $item['section'],
    'nid' => (int) $event->id(),
    'title' => mel_staging_title($item['key']),
  ];
  echo "  ✓ {$item['section']} nid {$event->id()}: " . mel_staging_title($item['key']) . "\n";
}

echo "\nFollow relationship validation\n";
mel_staging_ensure_follow($vendor);

\Drupal::service('cache_tags.invalidator')->invalidateTags(['node_list:event']);
\Drupal::cache()->delete('homepage_organiser_spotlight');

echo "\n" . str_repeat('=', 60) . "\n";
echo 'Seeded/updated ' . count($created) . " MEL-STAGING fixtures.\n";
echo "Community Spotlight uses real organisers unless MEL-STAGING-ORGANISER-001 has the most upcoming events.\n";
echo "\nValidate:\n";
echo "  php -d memory_limit=1024M vendor/bin/drush.php cr --uri=https://staging.myeventlane.com.au\n";
echo "  php -d memory_limit=1024M vendor/bin/drush.php scr web/scripts/audit-homepage-gate.drush.php --uri=https://staging.myeventlane.com.au\n";
