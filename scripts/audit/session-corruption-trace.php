<?php

/**
 * @file
 * Audit: trace paid availability resolution during Studio branding render.
 *
 * Usage:
 *   ddev drush php:script scripts/audit/session-corruption-trace.php
 */

declare(strict_types=1);

use Drupal\node\NodeInterface;

$nid = 1679;
$section = 'branding';

$node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
if (!$node instanceof NodeInterface) {
  throw new \RuntimeException("Node {$nid} not found.");
}

$account = \Drupal::entityTypeManager()->getStorage('user')->load(1);
if ($account) {
  \Drupal::currentUser()->setAccount($account);
}

$calls = 0;
$container = \Drupal::getContainer();
$inner = $container->get('myeventlane_commerce.ticket_availability');

$wrapper = new class($inner, $calls) {

  public function __construct(
    private readonly object $inner,
    private int &$sharedCount,
  ) {}

  public function __call(string $name, array $arguments): mixed {
    if ($name === 'filterPurchasableVariations') {
      $this->sharedCount++;
      $event = $arguments[0] ?? NULL;
      $eid = $event instanceof NodeInterface ? (string) $event->id() : '?';
      $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 14);
      $frames = [];
      foreach ($trace as $frame) {
        $class = $frame['class'] ?? '';
        $func = $frame['function'] ?? '';
        $file = isset($frame['file']) ? basename((string) $frame['file']) : '?';
        $line = $frame['line'] ?? 0;
        if ($class === self::class && $func === '__call') {
          continue;
        }
        $frames[] = trim("{$class}::{$func} @ {$file}:{$line}", ':');
        if (count($frames) >= 10) {
          break;
        }
      }
      \Drupal::logger('session_audit')->warning(
        'filterPurchasableVariations call #{n} event={eid} route={route} frames={frames}',
        [
          '#n' => (string) $this->sharedCount,
          '#eid' => $eid,
          '#route' => (string) (\Drupal::routeMatch()->getRouteName() ?? '(none)'),
          '#frames' => implode(' <- ', $frames),
        ]
      );
    }
    return $this->inner->{$name}(...$arguments);
  }

};

$container->set('myeventlane_commerce.ticket_availability', $wrapper);

/** @var \Drupal\myeventlane_event_studio\Controller\EventStudioController $controller */
$controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(
  \Drupal\myeventlane_event_studio\Controller\EventStudioController::class
);

$build = $controller->workspace($node, $section);
$html = (string) \Drupal::service('renderer')->renderRoot($build);

print "Rendered workspace section={$section} node={$nid}; html_bytes=" . strlen($html) . "; filterPurchasableVariations calls={$calls}\n";

if ($calls === 0) {
  print "No paid availability resolution during CLI branding render.\n";
}

$entries = \Drupal::database()->select('watchdog', 'w')
  ->fields('w', ['wid', 'message', 'variables'])
  ->condition('type', 'session_audit')
  ->orderBy('wid', 'DESC')
  ->range(0, 10)
  ->execute()
  ->fetchAll();

foreach ($entries as $entry) {
  $vars = @unserialize($entry->variables, ['allowed_classes' => FALSE]);
  print "\nWID {$entry->wid}: {$entry->message}\n";
  if (is_array($vars)) {
    foreach ($vars as $k => $v) {
      print "  {$k}: {$v}\n";
    }
  }
}
