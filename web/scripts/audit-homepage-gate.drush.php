<?php

/**
 * @file
 * Audits homepage merchandising gate and MEL-STAGING fixture readiness.
 *
 * Simulates front-page request context via HttpKernel SUB_REQUEST so
 * HomepageMerchandisingQueryAlter and dedup exclusions match /home behaviour.
 *
 * Usage (staging):
 *   php -d memory_limit=1024M vendor/bin/drush.php scr \
 *     web/scripts/audit-homepage-gate.drush.php --uri=https://staging.myeventlane.com.au
 *
 * Usage (local DDEV):
 *   ddev drush scr web/scripts/audit-homepage-gate.drush.php
 */

declare(strict_types=1);

use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

const MEL_STAGING_AUDIT_PREFIX = 'MEL-STAGING-';

/**
 * Boots a front-page sub-request so RouteMatch and PathMatcher see /home.
 */
function mel_audit_bootstrap_front_page(): array {
  $frontPath = (string) (\Drupal::config('system.site')->get('page.front') ?? '/node');
  /** @var \Symfony\Component\HttpKernel\HttpKernelInterface $kernel */
  $kernel = \Drupal::service('http_kernel');
  $request = Request::create($frontPath);
  $response = $kernel->handle($request, HttpKernelInterface::SUB_REQUEST);

  return [$kernel, $request, $response];
}

/**
 * Terminates a front-page sub-request bootstrapped by mel_audit_bootstrap_front_page().
 */
function mel_audit_teardown_front_page(
  HttpKernelInterface $kernel,
  Request $request,
  \Symfony\Component\HttpFoundation\Response $response,
): void {
  $kernel->terminate($request, $response);
}

[$kernel, $frontRequest, $frontResponse] = mel_audit_bootstrap_front_page();

/** @var \Drupal\myeventlane_front\Service\HomepageMerchandising $merch */
$merch = \Drupal::service('myeventlane_front.homepage_merchandising');
/** @var \Drupal\myeventlane_event\Service\BoostedEventQualityGate $gate */
$gate = \Drupal::service('myeventlane_event.boosted_quality_gate');

echo "=== Homepage merchandising audit ===\n\n";
echo "Front-page simulation: HttpKernel SUB_REQUEST to "
  . (\Drupal::config('system.site')->get('page.front') ?? '/node') . "\n\n";
echo 'isFrontPage: ' . (\Drupal::service('path.matcher')->isFrontPage() ? 'yes' : 'no') . "\n";
echo 'applies(block_featured): ' . ($merch->applies('front_featured_events', 'block_featured') ? 'yes' : 'no') . "\n";
echo 'appliesQualityGate(block_featured): ' . ($merch->appliesQualityGate('front_featured_events', 'block_featured') ? 'yes' : 'no') . "\n";
echo 'Ineligible promoted NIDs: ' . implode(', ', $merch->getIneligiblePromotedNids()) . "\n";
echo 'Hero NIDs: ' . implode(', ', $merch->getHeroEventIds()) . "\n";
echo 'Tonight NIDs: ' . implode(', ', $merch->getTonightEventIds()) . "\n";
echo 'Hidden Gem NIDs: ' . implode(', ', $merch->getHiddenGemEventIds()) . "\n";
echo 'Discover NIDs: ' . implode(', ', $merch->getDiscoverEventIds()) . "\n";
echo 'Featured block NIDs (rendered spotlight roster): ' . implode(', ', $merch->getFeaturedBlockEventIds()) . "\n";
echo 'Community Spotlight NIDs: ' . implode(', ', $merch->getCommunitySpotlightEventIds()) . "\n";
echo 'Community Favourite NIDs: ' . implode(', ', $merch->getCommunityFavouritesEventIds()) . "\n";
echo 'Latest NIDs: ' . implode(', ', $merch->getLatestEventIds()) . "\n";
echo 'Free RSVP NIDs: ' . implode(', ', $merch->getFreeRsvpEventIds()) . "\n";
echo 'Recommended NIDs: ' . implode(', ', $merch->getRecommendedEventIds()) . "\n";

echo "\n=== Surface counts ===\n\n";

$surfaceCounts = [
  'Hero' => count($merch->getHeroEventIds()),
  'Tonight' => count($merch->getTonightEventIds()),
  'Hidden Gems' => count($merch->getHiddenGemEventIds()),
  'Discover' => count($merch->getDiscoverEventIds()),
  'Community Spotlight' => count($merch->getCommunitySpotlightEventIds()),
  'Community Favourites' => count($merch->getCommunityFavouritesEventIds()),
  'Upcoming Highlights' => count($merch->getLatestEventIds()),
  'Free RSVP' => count($merch->getFreeRsvpEventIds()),
  'More Events To Explore' => count($merch->getRecommendedEventIds()),
];

foreach ($surfaceCounts as $label => $count) {
  echo str_pad($label, 28) . $count . "\n";
}

echo "\n=== Dedup ownership validation ===\n\n";

$rails = [
  'hero' => $merch->getHeroEventIds(),
  'tonight' => $merch->getTonightEventIds(),
  'hidden_gems' => $merch->getHiddenGemEventIds(),
  'discover' => $merch->getDiscoverEventIds(),
  'spotlight' => $merch->getFeaturedBlockEventIds(),
  'community_favourites' => $merch->getCommunityFavouritesEventIds(),
  'latest' => $merch->getLatestEventIds(),
  'free_rsvp' => $merch->getFreeRsvpEventIds(),
  'recommended' => $merch->getRecommendedEventIds(),
];

$priority = array_keys($rails);
$owner = [];
$duplicates = [];

foreach ($priority as $rail) {
  foreach ($rails[$rail] as $nid) {
    if (isset($owner[$nid])) {
      $duplicates[] = [
        'nid' => $nid,
        'winner' => $owner[$nid],
        'loser' => $rail,
      ];
    }
    else {
      $owner[$nid] = $rail;
    }
  }
}

if ($duplicates === []) {
  echo "No duplicate ownership across rails.\n";
}
else {
  echo "Duplicate ownership detected:\n";
  foreach ($duplicates as $dup) {
    echo '  NID ' . $dup['nid'] . ': winner=' . $dup['winner'] . ', also in=' . $dup['loser'] . "\n";
  }
}

$priorityChecks = [
  ['hero', 'tonight'],
  ['tonight', 'hidden_gems'],
  ['hidden_gems', 'discover'],
  ['discover', 'spotlight'],
  ['spotlight', 'community_favourites'],
  ['community_favourites', 'latest'],
  ['latest', 'free_rsvp'],
  ['free_rsvp', 'recommended'],
];

foreach ($priorityChecks as [$higher, $lower]) {
  $overlap = array_intersect($rails[$higher], $rails[$lower]);
  if ($overlap !== []) {
    echo 'PRIORITY VIOLATION: ' . $higher . ' should win over ' . $lower . ' but overlap: '
      . implode(', ', $overlap) . "\n";
  }
}

echo "\n=== Homepage event ownership table ===\n\n";
echo "NID\tWinning Surface\tOther Eligible Surfaces\n";

$allNids = [];
foreach ($rails as $rail => $nids) {
  foreach ($nids as $nid) {
    $allNids[$nid][] = $rail;
  }
}
ksort($allNids);
foreach ($allNids as $nid => $eligible) {
  $winner = $eligible[0];
  $other = array_slice($eligible, 1);
  echo $nid . "\t" . $winner . "\t" . ($other === [] ? '-' : implode(', ', $other)) . "\n";
}

echo "\n=== MEL-STAGING fixtures ===\n\n";

$stagingNids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'event')
  ->condition('title', MEL_STAGING_AUDIT_PREFIX . '%', 'LIKE')
  ->sort('title', 'ASC')
  ->execute();

if ($stagingNids === []) {
  echo "No MEL-STAGING events found. Run homepage-real-world-validation.drush.php first.\n";
}
else {
  foreach ($stagingNids as $nid) {
    $node = Node::load((int) $nid);
    if ($node === NULL) {
      continue;
    }
    $promoted = (int) ($node->get('field_promoted')->value ?? 0);
    $ready = $gate->isMarketplaceReady($node) ? 'yes' : 'NO';
    $img = $gate->hasHeroImage($node) ? 'yes' : 'NO';
    $focal = $gate->hasFocalPoint($node) ? 'yes' : 'NO';
    echo implode(' | ', [
      (string) $nid,
      $node->label(),
      'promoted=' . $promoted,
      'ready=' . $ready,
      'img=' . $img,
      'focal=' . $focal,
    ]) . "\n";
  }
}

echo "\n=== Featured view (block_featured) ===\n\n";

try {
  $storage = \Drupal::entityTypeManager()->getStorage('view')->load('front_featured_events');
  $view = \Drupal::service('views.executable')->get($storage);
  $view->setDisplay('block_featured');
  $view->execute();

  $resultNids = [];
  foreach ($view->result as $row) {
    if (isset($row->nid)) {
      $resultNids[] = (int) $row->nid;
    }
  }
  echo 'View block_featured result NIDs (homepage context): ' . implode(', ', $resultNids) . "\n";
  echo 'Merchandising featured block NIDs: ' . implode(', ', $merch->getFeaturedBlockEventIds()) . "\n";

  $viewMerchMismatch = array_diff($resultNids, $merch->getFeaturedBlockEventIds())
    + array_diff($merch->getFeaturedBlockEventIds(), $resultNids);
  echo 'View vs merchandising mismatch: ' . ($viewMerchMismatch !== [] ? implode(', ', $viewMerchMismatch) : 'NONE') . "\n";

  $bad = array_intersect($resultNids, $merch->getIneligiblePromotedNids());
  echo 'Ineligible still in view results: ' . ($bad !== [] ? implode(', ', $bad) : 'NONE') . "\n";
}
catch (\Throwable $e) {
  echo 'View execute skipped: ' . $e->getMessage() . "\n";
}

mel_audit_teardown_front_page($kernel, $frontRequest, $frontResponse);
