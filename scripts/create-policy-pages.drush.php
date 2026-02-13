<?php

/**
 * @file
 * Creates or updates policy pages and aligns legal config URLs.
 *
 * Run: ddev drush scr scripts/create-policy-pages.drush.php
 *
 * Policy content: Uses placeholder boilerplate. Replace via admin UI after run.
 * /cookies: Route conflict — CookiePolicyController occupies /cookies.
 *   Cookie Policy node created at /cookie-policy; config set to /cookie-policy.
 */

use Drupal\Core\Language\LanguageInterface;
use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;

// Policy definitions: alias => [title, body].
// Body uses placeholder content; replace via /admin/content after creation.
$policies = [
  '/terms' => [
    'title' => 'Customer Terms of Service',
    'body' => '<p>These Terms of Service govern your use of MyEventLane. By using our platform, you agree to these terms.</p>'
      . '<p><strong>Last updated:</strong> ' . date('F j, Y') . '</p>'
      . '<p><em>Full policy content to be inserted by legal. This is placeholder boilerplate.</em></p>',
  ],
  '/vendor-terms' => [
    'title' => 'Vendor Agreement',
    'body' => '<p>This Vendor Agreement sets out the terms under which organisers use MyEventLane to list and sell tickets.</p>'
      . '<p><strong>Last updated:</strong> ' . date('F j, Y') . '</p>'
      . '<p><em>Full policy content to be inserted by legal. This is placeholder boilerplate.</em></p>',
  ],
  '/privacy' => [
    'title' => 'Privacy Policy',
    'body' => '<p>MyEventLane respects your privacy. This policy describes how we collect, use, and protect your personal information.</p>'
      . '<p><strong>Last updated:</strong> ' . date('F j, Y') . '</p>'
      . '<p><em>Full policy content to be inserted by legal. This is placeholder boilerplate.</em></p>',
  ],
  '/refund-policy' => [
    'title' => 'Refund Policy',
    'body' => '<p>This Refund Policy explains how refunds are handled for tickets purchased through MyEventLane.</p>'
      . '<p><strong>Last updated:</strong> ' . date('F j, Y') . '</p>'
      . '<p><em>Full policy content to be inserted by legal. This is placeholder boilerplate.</em></p>',
  ],
  // /cookies is occupied by CookiePolicyController. Use /cookie-policy for the node.
  '/cookie-policy' => [
    'title' => 'Cookie Policy',
    'body' => '<p>This Cookie Policy explains how MyEventLane uses cookies and similar technologies.</p>'
      . '<p><strong>Last updated:</strong> ' . date('F j, Y') . '</p>'
      . '<p><em>Full policy content to be inserted by legal. This is placeholder boilerplate.</em></p>',
  ],
];

$nodeType = \Drupal::entityTypeManager()->getStorage('node_type')->load('page');
if (!$nodeType) {
  echo "ERROR: Basic page content type (page) not found. Available types: ";
  $types = \Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple();
  echo implode(', ', array_keys($types)) . "\n";
  exit(1);
}

$pathAliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
$nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
$results = [];

foreach ($policies as $alias => $info) {
  $node = NULL;
  $aliasEntities = $pathAliasStorage->loadByProperties(['alias' => $alias]);
  if (!empty($aliasEntities)) {
    $pa = reset($aliasEntities);
    $path = $pa->getPath();
    if (preg_match('#^/node/(\d+)$#', $path, $m)) {
      $node = $nodeStorage->load($m[1]);
    }
  }

  if ($node) {
    $node->set('title', $info['title']);
    $node->set('body', [
      'value' => $info['body'],
      'format' => 'basic_html',
    ]);
    $node->setPublished();
    $node->save();
    $results[] = "UPDATED: nid={$node->id()} alias={$alias} title={$info['title']}";
  }
  else {
    $node = Node::create([
      'type' => 'page',
      'title' => $info['title'],
      'body' => [
        'value' => $info['body'],
        'format' => 'basic_html',
      ],
      'status' => 1,
      'uid' => 1,
    ]);
    $node->save();
    $pathAlias = PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => $alias,
      'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
    ]);
    $pathAlias->save();
    $results[] = "CREATED: nid={$node->id()} alias={$alias} title={$info['title']}";
  }
}

echo "Policy nodes:\n" . implode("\n", $results) . "\n";

// Phase 3: Update legal config.
$config = \Drupal::configFactory()->getEditable('myeventlane_legal.settings');
$config
  ->set('customer_terms_url', '/terms')
  ->set('vendor_terms_url', '/vendor-terms')
  ->set('privacy_url', '/privacy')
  ->set('refund_policy_url', '/refund-policy')
  ->set('cookie_policy_url', '/cookie-policy')
  ->save();

echo "\nLegal config URLs updated to: /terms, /vendor-terms, /privacy, /refund-policy, /cookie-policy\n";
echo "\nNote: /cookies is served by CookiePolicyController (preferences page). Cookie Policy node is at /cookie-policy.\n";
