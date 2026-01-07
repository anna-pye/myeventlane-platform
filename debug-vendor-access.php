<?php

/**
 * @file
 * Debug script to check vendor access for user "anna".
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once 'autoload.php';
$kernel = DrupalKernel::createFromRequest(Request::createFromGlobals(), $autoloader, 'prod');
$kernel->boot();
$container = $kernel->getContainer();

// Load user "anna"
$user_storage = $container->get('entity_type.manager')->getStorage('user');
$users = $user_storage->loadByProperties(['name' => 'anna']);
if (empty($users)) {
  echo "ERROR: User 'anna' not found!\n";
  exit(1);
}
$user = reset($users);

echo "=== USER INFORMATION ===\n";
echo "UID: " . $user->id() . "\n";
echo "Username: " . $user->getAccountName() . "\n";
echo "Roles: " . implode(', ', $user->getRoles()) . "\n";
echo "Is UID 1: " . ($user->id() === 1 ? 'YES' : 'NO') . "\n";
echo "Has administrator role: " . ($user->hasRole('administrator') ? 'YES' : 'NO') . "\n";
echo "Has 'administer site configuration' permission: " . ($user->hasPermission('administer site configuration') ? 'YES' : 'NO') . "\n";
echo "Has 'access vendor console' permission: " . ($user->hasPermission('access vendor console') ? 'YES' : 'NO') . "\n";
echo "\n";

// Test VendorConsoleAccess
echo "=== TESTING VendorConsoleAccess ===\n";
$route_match = $container->get('current_route_match');
$access_check = \Drupal\myeventlane_vendor\Access\VendorConsoleAccess::access($route_match, $user);
echo "Access result: " . ($access_check->isAllowed() ? 'ALLOWED' : 'FORBIDDEN') . "\n";
echo "Cache context: " . implode(', ', $access_check->getCacheContexts()) . "\n";
echo "\n";

// Test domain detection
echo "=== TESTING DOMAIN DETECTION ===\n";
$domain_detector = $container->get('myeventlane_core.domain_detector');
$request = Request::createFromGlobals();
echo "Current host: " . $request->getHost() . "\n";
echo "Is vendor domain: " . ($domain_detector->isVendorDomain() ? 'YES' : 'NO') . "\n";
echo "Is admin domain: " . ($domain_detector->isAdminDomain() ? 'YES' : 'NO') . "\n";
echo "Is public domain: " . ($domain_detector->isPublicDomain() ? 'YES' : 'NO') . "\n";







