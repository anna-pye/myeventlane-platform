<?php

/**
 * One-off: map Twig template class hash to template name (Drupal bootstrap).
 *
 * @usage: cd web && php sites/default/files/.mel_twig_map.php
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once __DIR__ . '/../../../autoload.php';
$kernel = DrupalKernel::createFromRequest(Request::createFromGlobals(), $autoloader, 'prod');
$kernel->boot();
$kernel->prepareLegacyRequest(Request::createFromGlobals());

$target = '037882a2c32da1eff33dcdc0de709d77';
$twig = \Drupal::service('twig');

$names = [
  '@myeventlane_vendor_theme/layout/console-page.html.twig',
  '@myeventlane_vendor_theme/templates/layout/console-page.html.twig',
  '@myeventlane_vendor_theme/vendor-console-page.html.twig',
  '@myeventlane_vendor_theme/templates/vendor-console-page.html.twig',
  '@myeventlane_theme/layout/page.html.twig',
  '@myeventlane_vendor_theme/layout/page.html.twig',
  '@myeventlane_vendor_theme/templates/vendor/boost.html.twig',
  '@myeventlane_theme/templates/views/views-view.html.twig',
  '@myeventlane_vendor_theme/templates/views/views-view.html.twig',
  '@myeventlane_boost/boost-faq.html.twig',
];

foreach ($names as $name) {
  try {
    $cls = $twig->getTemplateClass($name);
    $hash = substr($cls, strlen('__TwigTemplate_'));
    echo $hash . ' <= ' . $name . "\n";
    if ($hash === $target) {
      echo "^^^ MATCH\n";
    }
  }
  catch (\Throwable $e) {
    echo 'ERR ' . $name . ' : ' . $e->getMessage() . "\n";
  }
}

// Brute: scan theme templates under myeventlane_vendor_theme.
$root = dirname(__DIR__, 3) . '/themes/custom/myeventlane_vendor_theme/templates';
if (is_dir($root)) {
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
  foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'twig') {
      continue;
    }
    $rel = str_replace($root . '/', '', $file->getPathname());
    $name = '@myeventlane_vendor_theme/' . $rel;
    try {
      $cls = $twig->getTemplateClass($name);
      $hash = substr($cls, strlen('__TwigTemplate_'));
      if ($hash === $target) {
        echo "MATCH: $name\n";
      }
    }
    catch (\Throwable $e) {
      // skip
    }
  }
}
