<?php

/**
 * @file
 * Creates or updates policy pages and aligns legal config URLs.
 *
 * Run: ddev drush scr scripts/create-policy-pages.drush.php
 *
 * Policy content: Launch-ready drafts marked for legal review.
 * Refunds: canonical public destination is /help/policies/refund-policy.
 * /cookies: Route conflict — CookiePolicyController occupies /cookies.
 *   Cookie Policy node created at /cookie-policy; config set to /cookie-policy.
 */

use Drupal\Core\Language\LanguageInterface;
use Drupal\myeventlane_legal\Service\LegalPolicyPageContent;
use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;

$policies = LegalPolicyPageContent::getDefinitions(\Drupal::configFactory());

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

// Retire duplicate /refund-policy node if present.
foreach ($pathAliasStorage->loadByProperties(['alias' => '/refund-policy']) as $pathAlias) {
  if (preg_match('#^/node/(\d+)$#', $pathAlias->getPath(), $m)) {
    $legacyNode = $nodeStorage->load($m[1]);
    if ($legacyNode) {
      $legacyNode->setUnpublished();
      $legacyNode->save();
      echo "UNPUBLISHED legacy refund-policy node nid={$m[1]}\n";
    }
  }
  $pathAlias->delete();
  echo "Removed /refund-policy path alias.\n";
}

$config = \Drupal::configFactory()->getEditable('myeventlane_legal.settings');
$config
  ->set('customer_terms_url', '/terms')
  ->set('vendor_terms_url', '/vendor-terms')
  ->set('privacy_url', '/privacy')
  ->set('refund_policy_url', '/help/policies/refund-policy')
  ->set('cookie_policy_url', '/cookie-policy')
  ->save();

echo "\nLegal config URLs updated to: /terms, /vendor-terms, /privacy, /help/policies/refund-policy, /cookie-policy\n";
echo "\nNote: /cookies is served by CookiePolicyController (preferences page). Cookie Policy node is at /cookie-policy.\n";
