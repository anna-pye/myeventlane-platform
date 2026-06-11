<?php

/**
 * @file
 * Audits footer and legal destination HTTP status for anonymous users.
 *
 * Run: ddev drush scr scripts/audit/check-footer-destinations.drush.php
 */

use Drupal\Core\Url;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

$paths = [
  '/privacy',
  '/terms',
  '/cookies',
  '/cookie-policy',
  '/vendor-terms',
  '/contact',
  '/help',
  '/help/policies/refund-policy',
  '/help/policies/trust-and-safety',
  '/help/policies/community-guidelines',
];

$base = rtrim(Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(), '/');
$jar = new CookieJar();
$client = new Client([
  'cookies' => $jar,
  'http_errors' => FALSE,
  'allow_redirects' => [
    'max' => 5,
    'track_redirects' => TRUE,
  ],
  'verify' => FALSE,
]);

echo "Base URL: {$base}\n\n";
echo str_pad('Path', 45) . str_pad('Status', 8) . "Final URL\n";
echo str_repeat('-', 100) . "\n";

$failures = 0;
foreach ($paths as $path) {
  $url = $base . $path;
  try {
    $response = $client->get($url);
    $status = (int) $response->getStatusCode();
    $final = (string) $response->getHeaderLine('X-Guzzle-Redirect-History');
    if ($final === '') {
      $final = $url;
    }
    else {
      $parts = explode(', ', $final);
      $final = end($parts);
    }
    echo str_pad($path, 45) . str_pad((string) $status, 8) . $final . "\n";
    if ($status < 200 || $status >= 400) {
      $failures++;
    }
  }
  catch (\Throwable $e) {
    echo str_pad($path, 45) . str_pad('ERR', 8) . $e->getMessage() . "\n";
    $failures++;
  }
}

echo "\nLegal config URLs:\n";
$config = \Drupal::config('myeventlane_legal.settings');
foreach ([
  'customer_terms_url',
  'vendor_terms_url',
  'privacy_url',
  'cookie_policy_url',
  'refund_policy_url',
] as $key) {
  echo "  {$key}: " . ($config->get($key) ?? 'NULL') . "\n";
}

echo "\nSupport email: " . (\Drupal::config('myeventlane_core.settings')->get('support_email') ?? 'NULL') . "\n";

if ($failures > 0) {
  echo "\nFAILED: {$failures} destination(s) returned non-2xx/3xx status.\n";
  exit(1);
}

echo "\nOK: All destinations returned 2xx/3xx.\n";
