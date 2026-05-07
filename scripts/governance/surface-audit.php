#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Emits a JSON summary of route inventory + SurfaceResolver ownership hints.
 */

$root = dirname(__DIR__, 2);
$routesPath = $root . '/mel-routes.json';
if (!is_readable($routesPath)) {
  fwrite(STDERR, "Missing mel-routes.json\n");
  exit(1);
}

$data = json_decode((string) file_get_contents($routesPath), TRUE);
if (!is_array($data)) {
  fwrite(STDERR, "Invalid mel-routes.json\n");
  exit(1);
}

$routes = $data['routes'] ?? $data;
if (!is_array($routes)) {
  fwrite(STDERR, "mel-routes.json has unexpected shape\n");
  exit(1);
}

$report = [
  'generated_at' => gmdate('c'),
  'total_routes_documented' => count($routes),
  'surface_hints' => [
    'admin_route_prefix' => '/admin',
    'vendor_route_prefix' => '/vendor',
    'customer_route_prefixes' => ['/my-account', '/my-settings', '/my-tickets', '/my-events', '/my-past-events', '/my-profile'],
    'auth_route_names' => ['user.login', 'user.register', 'user.pass', 'user.reset', 'user.reset.login'],
  ],
  'references' => [
    'resolver' => 'Drupal\\myeventlane_surface\\SurfaceResolver',
    'observability_permission' => 'access mel observability diagnostics',
    'governance_debug_permission' => 'access mel governance debug',
  ],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
