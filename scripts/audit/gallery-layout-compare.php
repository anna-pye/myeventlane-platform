<?php

/**
 * @file
 * Audit script: compare gallery DOM/AJAX between main and feature EventBrandingForm.
 */

declare(strict_types=1);

use Drupal\Core\Render\Element;

$account = \Drupal\user\Entity\User::load(1);
\Drupal::currentUser()->setAccount($account);
$node = \Drupal::entityTypeManager()->getStorage('node')->load(1094);
if ($node === NULL) {
  echo "Node 1094 not found\n";
  exit(1);
}

$formPath = DRUPAL_ROOT . '/modules/custom/myeventlane_event_studio/src/Form/EventBrandingForm.php';
$repoRoot = dirname(DRUPAL_ROOT);
$mainPath = $repoRoot . '/scripts/audit/output/EventBrandingForm.main.php';
$featurePath = $repoRoot . '/scripts/audit/output/EventBrandingForm.feature.php';

if (!is_readable($mainPath) || !is_readable($featurePath)) {
  echo "Missing snapshot files. Run git show main:... > scripts/audit/output/EventBrandingForm.main.php first.\n";
  exit(1);
}

/**
 * @return array<string, mixed>
 */
function walkAjax(array $el, string $path = ''): array {
  $out = [];
  if (isset($el['#ajax']) && is_array($el['#ajax'])) {
    $out[] = [
      'path' => $path,
      'name' => $el['#name'] ?? NULL,
      'type' => $el['#type'] ?? NULL,
      'wrapper' => $el['#ajax']['wrapper'] ?? NULL,
      'callback' => is_array($el['#ajax']['callback'] ?? NULL)
        ? implode('::', $el['#ajax']['callback'])
        : ($el['#ajax']['callback'] ?? NULL),
      'limit_validation_errors' => $el['#limit_validation_errors'] ?? NULL,
    ];
  }
  foreach (Element::children($el) as $key) {
    if (!isset($el[$key]) || !is_array($el[$key])) {
      continue;
    }
    $out = array_merge($out, walkAjax($el[$key], $path . '/' . $key));
  }
  return $out;
}

/**
 * @return array<string, mixed>
 */
function analyzeForm(string $label, $node): array {
  $form = \Drupal::formBuilder()->getForm('Drupal\myeventlane_event_studio\Form\EventBrandingForm', $node);
  $html = (string) \Drupal::service('renderer')->renderRoot($form);
  $field = $form['mel']['field_mel_event_gallery'] ?? [];

  $galleryPos = strpos($html, 'mel-es-field-group--gallery');
  $layoutPos = strpos($html, 'mel-event-branding-studio__layout');
  $mainPos = strpos($html, 'mel-event-branding-studio__main');
  $wrapperId = 'field_mel_event_gallery-media-library-wrapper-mel';

  // DOM nesting check via regex around gallery section.
  $galleryInsideMain = (bool) preg_match(
    '/mel-event-branding-studio__main[\s\S]*?mel-es-field-group--gallery/',
    $html,
  );

  // Count div balance from layout open to gallery close.
  $layoutOpen = strpos($html, 'mel-event-branding-studio__layout');
  $galleryClose = strpos($html, 'mel-es-field-group--gallery');
  $segment = ($layoutOpen !== FALSE && $galleryClose !== FALSE)
    ? substr($html, $layoutOpen, max(0, $galleryClose - $layoutOpen + 500))
    : '';

  preg_match_all('/id="([^"]*field-mel-event-gallery[^"]*)"/', $html, $idMatches);

  return [
    'label' => $label,
    'field_parents' => $field['#parents'] ?? NULL,
    'field_id' => $field['#id'] ?? NULL,
    'ajax_elements' => walkAjax($field),
    'html_ids' => array_values(array_unique($idMatches[1] ?? [])),
    'wrapper_id_in_html' => str_contains($html, 'id="' . $wrapperId . '"'),
    'has_layout_wrapper' => $layoutPos !== FALSE,
    'gallery_inside_main' => $galleryInsideMain,
    'layout_before_gallery' => ($layoutPos !== FALSE && $galleryPos !== FALSE) ? ($layoutPos < $galleryPos) : NULL,
    'page_style_close_before_style_fields' => (bool) preg_match(
      '/mel-es-field-group--page-style[\s\S]*?<\/section>[\s\S]*?field_mel_page_style/s',
      $html,
    ),
    'segment_preview' => $segment !== '' ? substr($segment, 0, 300) : NULL,
  ];
}

copy($formPath, $featurePath);
copy($mainPath, $formPath);
drupal_flush_all_caches();
$main = analyzeForm('main', $node);

copy($featurePath, $formPath);
drupal_flush_all_caches();
$feature = analyzeForm('feature', $node);

echo json_encode(['main' => $main, 'feature' => $feature], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
