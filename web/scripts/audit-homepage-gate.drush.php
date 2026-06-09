<?php

/**
 * @file
 * Audits homepage merchandising gate on simulated front page.
 */

declare(strict_types=1);

/** @var \Symfony\Component\HttpFoundation\RequestStack $stack */
$stack = \Drupal::service('request_stack');
$request = \Symfony\Component\HttpFoundation\Request::create('/');
$stack->push($request);

/** @var \Drupal\myeventlane_front\Service\HomepageMerchandising $merch */
$merch = \Drupal::service('myeventlane_front.homepage_merchandising');

echo "isFrontPage: " . (\Drupal::service('path.matcher')->isFrontPage() ? 'yes' : 'no') . "\n";
echo "applies(block_featured): " . ($merch->applies('front_featured_events', 'block_featured') ? 'yes' : 'no') . "\n";
echo "appliesQualityGate(block_featured): " . ($merch->appliesQualityGate('front_featured_events', 'block_featured') ? 'yes' : 'no') . "\n";
echo "Ineligible promoted NIDs: " . implode(', ', $merch->getIneligiblePromotedNids()) . "\n";
echo "Hero NIDs: " . implode(', ', $merch->getHeroEventIds()) . "\n";
echo "Spotlight NIDs: " . implode(', ', $merch->getSpotlightEventIds()) . "\n";

$storage = \Drupal::entityTypeManager()->getStorage('view')->load('front_featured_events');
$view = \Drupal::service('views.executable')->get($storage);
$view->setDisplay('block_featured');
$view->execute();

echo "\nView block_featured result NIDs: ";
$resultNids = [];
foreach ($view->result as $row) {
  if (isset($row->nid)) {
    $resultNids[] = (int) $row->nid;
  }
}
echo implode(', ', $resultNids) . "\n";

$bad = array_intersect($resultNids, $merch->getIneligiblePromotedNids());
echo "Ineligible still in view results: " . ($bad !== [] ? implode(', ', $bad) : 'NONE') . "\n";

$stack->pop();
