<?php

/**
 * @file
 * Audits homepage merchandising gate and MEL-STAGING fixture readiness.
 *
 * Usage (staging):
 *   php -d memory_limit=1024M vendor/bin/drush.php scr \
 *     web/scripts/audit-homepage-gate.drush.php --uri=https://staging.myeventlane.com.au
 */

declare(strict_types=1);

use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

const MEL_STAGING_AUDIT_PREFIX = 'MEL-STAGING-';

/** @var \Symfony\Component\HttpFoundation\RequestStack $stack */
$stack = \Drupal::service('request_stack');
$frontPath = (string) (\Drupal::config('system.site')->get('page.front') ?? '/node');
$request = Request::create($frontPath);
$session = new Session(new MockArraySessionStorage());
$session->start();
$request->setSession($session);
$stack->push($request);

/** @var \Drupal\myeventlane_front\Service\HomepageMerchandising $merch */
$merch = \Drupal::service('myeventlane_front.homepage_merchandising');
/** @var \Drupal\myeventlane_event\Service\BoostedEventQualityGate $gate */
$gate = \Drupal::service('myeventlane_event.boosted_quality_gate');

echo "=== Homepage merchandising audit ===\n\n";
echo "Note: isFrontPage is often 'no' under Drush CLI (no route match); hero/spotlight resolution still runs.\n\n";
echo 'isFrontPage: ' . (\Drupal::service('path.matcher')->isFrontPage() ? 'yes' : 'no') . "\n";
echo 'applies(block_featured): ' . ($merch->applies('front_featured_events', 'block_featured') ? 'yes' : 'no') . "\n";
echo 'appliesQualityGate(block_featured): ' . ($merch->appliesQualityGate('front_featured_events', 'block_featured') ? 'yes' : 'no') . "\n";
echo 'Ineligible promoted NIDs: ' . implode(', ', $merch->getIneligiblePromotedNids()) . "\n";
echo 'Hero NIDs: ' . implode(', ', $merch->getHeroEventIds()) . "\n";
echo 'Spotlight NIDs: ' . implode(', ', $merch->getSpotlightEventIds()) . "\n";
echo 'Discover NIDs: ' . implode(', ', $merch->getDiscoverEventIds()) . "\n";
echo 'Tonight NIDs: ' . implode(', ', $merch->getTonightEventIds()) . "\n";
echo 'Free RSVP NIDs: ' . implode(', ', $merch->getFreeRsvpEventIds()) . "\n";
echo 'Latest NIDs: ' . implode(', ', $merch->getLatestEventIds()) . "\n";

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
  echo 'View block_featured result NIDs: ' . implode(', ', $resultNids) . "\n";

  $bad = array_intersect($resultNids, $merch->getIneligiblePromotedNids());
  echo 'Ineligible still in view results: ' . ($bad !== [] ? implode(', ', $bad) : 'NONE') . "\n";
}
catch (\Throwable $e) {
  echo 'View execute skipped: ' . $e->getMessage() . "\n";
}

$stack->pop();
