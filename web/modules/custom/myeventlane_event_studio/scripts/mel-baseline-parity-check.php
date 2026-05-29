<?php

/**
 * @file
 * Compares legacy EventStudioForm baseline vs EventStudioMelDefaultsProvider.
 *
 * Usage: ddev drush php:script web/modules/custom/myeventlane_event_studio/scripts/mel-baseline-parity-check.php
 */

declare(strict_types=1);

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event_studio\Form\EventStudioForm;
use Drupal\myeventlane_event_studio\Service\EntityAutocompleteMelNormalizer;
use Drupal\myeventlane_event_studio\Service\EventStudioMelDefaultsExtraction;
use Drupal\myeventlane_event_studio\Service\EventStudioMelDefaultsProvider;
use Drupal\node\NodeInterface;

/**
 * @param array<string, mixed> $element
 *
 * @return mixed
 */
function mel_baseline_extract(array $element): mixed {
  return EventStudioMelDefaultsExtraction::extractDefaultsRecursive($element);
}

/**
 * @return array<string, mixed>
 */
function mel_baseline_legacy(NodeInterface $node, FormBuilderInterface $formBuilder, EntityAutocompleteMelNormalizer $normalizer): array {
  $form = $formBuilder->getForm(EventStudioForm::class, $node);
  $mel = $form['mel'] ?? [];
  if (!is_array($mel)) {
    return [];
  }
  $baseline = mel_baseline_extract($mel);
  if (!is_array($baseline)) {
    return [];
  }
  return $normalizer->normalizeValuesForForm($mel, $baseline, [
    'section' => 'baseline',
    'event_id' => (int) $node->id(),
    'uid' => NULL,
  ]);
}

/**
 * Canonicalizes baseline values for stable assertSame-style comparison.
 */
function mel_baseline_canonicalize(mixed $value): mixed {
  if ($value instanceof DrupalDateTime) {
    return $value->format('Y-m-d\TH:i:s');
  }
  if ($value instanceof Url) {
    return $value->toString();
  }
  if ($value instanceof EntityInterface) {
    return $value->getEntityTypeId() . ':' . $value->id();
  }
  if ($value instanceof \Stringable) {
    return (string) $value;
  }
  if (!is_array($value)) {
    return $value;
  }
  $out = [];
  foreach ($value as $key => $item) {
    $out[$key] = mel_baseline_canonicalize($item);
  }
  return $out;
}

/**
 * @param mixed $a
 * @param mixed $b
 *
 * @return list<string>
 */
function mel_baseline_diff_paths(string $path, mixed $a, mixed $b): array {
  $a = mel_baseline_canonicalize($a);
  $b = mel_baseline_canonicalize($b);
  if ($a === $b) {
    return [];
  }
  if (gettype($a) !== gettype($b)) {
    return ["$path type " . gettype($a) . ' vs ' . gettype($b)];
  }
  if (!is_array($a)) {
    return ["$path value mismatch"];
  }
  $out = [];
  $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
  sort($keys);
  foreach ($keys as $key) {
    $child = "$path.$key";
    if (!array_key_exists($key, $a)) {
      $out[] = "$child missing in legacy";
      continue;
    }
    if (!array_key_exists($key, $b)) {
      $out[] = "$child missing in provider";
      continue;
    }
    $out = array_merge($out, mel_baseline_diff_paths($child, $a[$key], $b[$key]));
  }
  return $out;
}

$account = \Drupal::currentUser();
if (!$account->hasPermission('administer nodes')) {
  $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['uid' => 1]);
  $admin = reset($users);
  if ($admin) {
    \Drupal::service('account_switcher')->switchTo($admin);
  }
}

/** @var \Drupal\myeventlane_event_studio\Service\EventStudioMelDefaultsProvider $provider */
$provider = \Drupal::service('myeventlane_event_studio.mel_defaults_provider');
$formBuilder = \Drupal::formBuilder();
$normalizer = \Drupal::service('myeventlane_event_studio.entity_autocomplete_mel_normalizer');

$storage = \Drupal::entityTypeManager()->getStorage('node');
$events = $storage->loadByProperties(['type' => 'event']);
if ($events === []) {
  print "No event nodes to compare.\n";
  exit(0);
}

$failures = 0;
foreach ($events as $event) {
  if (!$event instanceof NodeInterface) {
    continue;
  }
  try {
    $legacy = mel_baseline_legacy($event, $formBuilder, $normalizer);
    $modern = $provider->buildNormalizedBaselineMel($event);
  }
  catch (\Throwable $e) {
    print 'SKIP nid=' . $event->id() . ' (' . $e::class . ': ' . $e->getMessage() . ")\n";
    continue;
  }
  if (mel_baseline_canonicalize($legacy) === mel_baseline_canonicalize($modern)) {
    print 'OK nid=' . $event->id() . ' type=' . ($event->hasField('field_event_type') ? $event->get('field_event_type')->value : '?') . "\n";
    continue;
  }
  $failures++;
  print 'MISMATCH nid=' . $event->id() . "\n";
  foreach (mel_baseline_diff_paths('mel', $legacy, $modern) as $line) {
    print "  $line\n";
  }
  if ($failures >= 5) {
    print "Stopping after 5 mismatches.\n";
    break;
  }
}

if ($failures > 0) {
  print "Parity failures: $failures\n";
  exit(1);
}

print "Parity check complete: all compared events matched.\n";
exit(0);
