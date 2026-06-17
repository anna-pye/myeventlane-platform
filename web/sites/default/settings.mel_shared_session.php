<?php

/**
 * @file
 * Environment-aware trusted hosts, reverse proxy defaults, and shared session
 * cookies for MyEventLane (main / vendor / admin subdomains).
 *
 * Session domain / SameSite / httponly are set only in mel.session.*.yml via
 * parameters.session.storage.options (appended to $settings['container_yamls']).
 * Drupal core does not apply $settings['session_storage_options'] to the session
 * service; the effective cookie domain is whatever the selected mel.session file
 * contributes to the container.
 *
 * Included from settings.php after settings.ddev.php. Uses X-Forwarded-Host
 * (first value) when present for $mel_host; staging AU also consults HTTP_HOST
 * so vendor.* / admin.* still load the shared cookie YAML when forwarded
 * headers are missing or wrong.
 */

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;

$mel_forwarded_raw = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';
if ($mel_forwarded_raw !== '') {
  $mel_http_host = trim(explode(',', $mel_forwarded_raw)[0]);
}
else {
  $mel_http_host = $_SERVER['HTTP_HOST'] ?? '';
}
$mel_host = strtolower((string) preg_replace('/:\d+$/', '', $mel_http_host));

// Browser-visible host (no port). Used with $mel_host for staging session YAML.
$mel_http_host_direct = strtolower((string) preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));

$mel_trusted_ddev = [
  '^myeventlane\.ddev\.site$',
  '^vendor\.myeventlane\.ddev\.site$',
  '^admin\.myeventlane\.ddev\.site$',
];

$mel_trusted_staging_prod = [
  '^staging\.myeventlane\.com$',
  '^vendor\.staging\.myeventlane\.com$',
  '^admin\.staging\.myeventlane\.com$',
  '^staging\.myeventlane\.com\.au$',
  '^vendor\.staging\.myeventlane\.com\.au$',
  '^admin\.staging\.myeventlane\.com\.au$',
  '^myeventlane\.com$',
  '^vendor\.myeventlane\.com$',
  '^admin\.myeventlane\.com$',
  '^myeventlane\.com\.au$',
  '^vendor\.myeventlane\.com\.au$',
  '^admin\.myeventlane\.com\.au$',
];

if (getenv('IS_DDEV_PROJECT') === 'true') {
  $settings['trusted_host_patterns'] = $mel_trusted_ddev;
  $settings['container_yamls'][] = dirname(__DIR__) . '/development.services.yml';
}
else {
  $settings['trusted_host_patterns'] = array_values(array_unique(array_merge(
    $settings['trusted_host_patterns'] ?? [],
    $mel_trusted_staging_prod
  )));
}

// Shared session YAML: DDEV uses its own; never load staging/production YAML there.
$mel_session_services_file = NULL;
if (getenv('IS_DDEV_PROJECT') === 'true') {
  $mel_session_services_file = __DIR__ . '/mel.session.ddev.yml';
}
elseif ($mel_host !== '' || $mel_http_host_direct !== '') {
  // Staging AU: must run before .com / production .com.au checks. HTTP_HOST +
  // forwarded host so YAML loads when either is correct (avoids anonymous user
  // on vendor when X-Forwarded-Host is wrong). Substring matches
  // *.staging.myeventlane.com.au (e.g. vendor.staging...).
  if (str_contains($mel_http_host_direct, 'staging.myeventlane.com.au')
    || str_contains($mel_host, 'staging.myeventlane.com.au')) {
    $mel_session_services_file = __DIR__ . '/mel.session.staging-au.yml';
  }
  elseif (
    $mel_host === 'staging.myeventlane.com'
    || preg_match('/\.staging\.myeventlane\.com$/', $mel_host)
    || $mel_http_host_direct === 'staging.myeventlane.com'
    || preg_match('/\.staging\.myeventlane\.com$/', $mel_http_host_direct)
  ) {
    $mel_session_services_file = __DIR__ . '/mel.session.staging-com.yml';
  }
  elseif (
    (preg_match('/(^|\.)myeventlane\.com$/', $mel_host)
      || preg_match('/(^|\.)myeventlane\.com$/', $mel_http_host_direct))
    && !str_contains($mel_host, '.staging.')
    && !str_contains($mel_http_host_direct, '.staging.')
  ) {
    $mel_session_services_file = __DIR__ . '/mel.session.production.yml';
  }
  elseif (
    (preg_match('/(^|\.)myeventlane\.com\.au$/', $mel_host)
      || preg_match('/(^|\.)myeventlane\.com\.au$/', $mel_http_host_direct))
    && !str_contains($mel_host, '.staging.')
    && !str_contains($mel_http_host_direct, '.staging.')
  ) {
    $mel_session_services_file = __DIR__ . '/mel.session.production-au.yml';
  }
}

if ($mel_session_services_file !== NULL && is_readable($mel_session_services_file)) {
  $settings['container_yamls'][] = $mel_session_services_file;
}

$settings['reverse_proxy'] = TRUE;

$mel_default_proxy_addresses = [
  '127.0.0.1',
  '::1',
  '10.0.0.0/8',
  '172.16.0.0/12',
  '192.168.0.0/16',
];
$settings['reverse_proxy_addresses'] = array_values(array_unique(array_merge(
  $settings['reverse_proxy_addresses'] ?? [],
  $mel_default_proxy_addresses
)));

$settings['reverse_proxy_trusted_headers'] =
  Request::HEADER_X_FORWARDED_FOR
  | Request::HEADER_X_FORWARDED_PROTO
  | Request::HEADER_X_FORWARDED_PORT
  | Request::HEADER_X_FORWARDED_HOST;

// ---------------------------------------------------------------------------
// Stripe (Commerce payment gateways): secrets from the environment.
//
// StripeService::getPlatformSecretKey() checks gateways: mel_stripe, stripe,
// stripe_myeventlane_v2, stripe_pe_recurring, then myeventlane_core.stripe_settings,
// then MEL_STRIPE_SECRET_KEY. Staging may only define stripe_myeventlane_v2.
// Do not commit live keys in config/sync; set env vars on the host (e.g. systemd
// Environment=, .env consumed by PHP-FPM, or platform secret store).
//
//   MEL_STRIPE_SECRET_KEY       — sk_test_… / sk_live_…
//   MEL_STRIPE_PUBLISHABLE_KEY  — pk_test_… / pk_live_…
//   MEL_STRIPE_WEBHOOK_SECRET   — whsec_… (Payment Element gateway webhook)
//
// DDEV: set these in a gitignored .ddev/config.local.yaml, for example
//   web_environment:
//     - MEL_STRIPE_SECRET_KEY=sk_test_…
//     - MEL_STRIPE_PUBLISHABLE_KEY=pk_test_…
//     - MEL_STRIPE_WEBHOOK_SECRET=whsec_…
// then ddev restart.
// ---------------------------------------------------------------------------
$melGetEnv = static function (string $name): string {
  $v = getenv($name);
  if (is_string($v) && $v !== '') {
    return $v;
  }
  if (isset($_ENV[$name]) && is_string($_ENV[$name]) && $_ENV[$name] !== '') {
    return $_ENV[$name];
  }
  if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && $_SERVER[$name] !== '') {
    return $_SERVER[$name];
  }
  return '';
};

$mel_stripe_secret = $melGetEnv('MEL_STRIPE_SECRET_KEY');
if ($mel_stripe_secret !== '') {
  $config['commerce_payment.commerce_payment_gateway.stripe']['configuration']['secret_key'] = $mel_stripe_secret;
  $config['commerce_payment.commerce_payment_gateway.stripe_myeventlane_v2']['configuration']['secret_key'] = $mel_stripe_secret;
  $config['commerce_payment.commerce_payment_gateway.stripe_pe_recurring']['configuration']['secret_key'] = $mel_stripe_secret;
}

$mel_stripe_publishable = $melGetEnv('MEL_STRIPE_PUBLISHABLE_KEY');
if ($mel_stripe_publishable !== '') {
  $config['commerce_payment.commerce_payment_gateway.stripe']['configuration']['publishable_key'] = $mel_stripe_publishable;
  $config['commerce_payment.commerce_payment_gateway.stripe_myeventlane_v2']['configuration']['publishable_key'] = $mel_stripe_publishable;
  $config['commerce_payment.commerce_payment_gateway.stripe_pe_recurring']['configuration']['publishable_key'] = $mel_stripe_publishable;
}

$mel_stripe_webhook = $melGetEnv('MEL_STRIPE_WEBHOOK_SECRET');
if ($mel_stripe_webhook !== '') {
  $config['commerce_payment.commerce_payment_gateway.stripe_pe_recurring']['configuration']['webhook_signing_secret'] = $mel_stripe_webhook;
}

// ---------------------------------------------------------------------------
// Postmark (MyEventLane Messaging): secrets from the environment.
//
// PostmarkDeliveryProvider reads postmark.server_token; PostmarkWebhookController
// reads postmark.webhook_secret. Do not commit tokens in config/sync; set env vars
// on the host (same pattern as Stripe above).
//
//   MEL_POSTMARK_SERVER_TOKEN   — Postmark Server API token
//   MEL_POSTMARK_WEBHOOK_SECRET — Postmark webhook signing secret
//
// DDEV: set these in a gitignored .ddev/config.local.yaml, for example
//   web_environment:
//     - MEL_POSTMARK_SERVER_TOKEN=…
//     - MEL_POSTMARK_WEBHOOK_SECRET=…
// then ddev restart.
// ---------------------------------------------------------------------------
$mel_postmark_server_token = $melGetEnv('MEL_POSTMARK_SERVER_TOKEN');
if ($mel_postmark_server_token !== '') {
  $config['myeventlane_messaging.settings']['postmark']['server_token'] = $mel_postmark_server_token;
}

$mel_postmark_webhook_secret = $melGetEnv('MEL_POSTMARK_WEBHOOK_SECRET');
if ($mel_postmark_webhook_secret !== '') {
  $config['myeventlane_messaging.settings']['postmark']['webhook_secret'] = $mel_postmark_webhook_secret;
}
