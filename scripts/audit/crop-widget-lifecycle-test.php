<?php

/**
 * @file
 * Audit-only: test saveBrandingHero crop lifecycle.
 */

use Drupal\myeventlane_event_studio\Form\EventBrandingForm;
use Drupal\node\NodeInterface;

$user = \Drupal::entityTypeManager()->getStorage('user')->load(1);
\Drupal::currentUser()->setAccount($user);
$node = \Drupal::entityTypeManager()->getStorage('node')->load(1381);
if (!$node instanceof NodeInterface) {
  throw new \RuntimeException('Node 1381 missing');
}

$form_object = \Drupal::classResolver()->getInstanceFromDefinition(EventBrandingForm::class);
$fs = new \Drupal\Core\Form\FormState();
$form = $form_object->buildForm([], $fs, $node);

$crop_values = [
  'crop_applied' => '1',
  'width' => '1200',
  'height' => '630',
  'x' => '100',
  'y' => '50',
];

$fs->setValues([
  'nid' => '1381',
  'mel' => [
    'field_event_image' => [
      0 => [
        'fids' => ['454'],
        'display' => 1,
        'image_crop' => [
          'crop_wrapper' => [
            'event_hero' => [
              'crop_container' => [
                'values' => $crop_values,
              ],
            ],
          ],
        ],
      ],
    ],
    'field_event_image_alt' => 'audit alt',
    'field_mel_page_style' => 'classic',
    'field_mel_theme_colour' => 'coral',
  ],
]);
$fs->setCompleteForm($form);

$file = \Drupal::entityTypeManager()->getStorage('file')->load(454);
$uri = $file ? $file->getFileUri() : '';
$before = \Drupal\crop\Entity\Crop::findCrop($uri, 'event_hero');

$save = \Drupal::service('myeventlane_event_studio.save');
$clone = clone $node;
$result = $save->saveBrandingHero($clone, $fs->getValue('mel'), $fs, TRUE);

$after = \Drupal\crop\Entity\Crop::findCrop($uri, 'event_hero');

print json_encode([
  'errors' => $result['errors'],
  'hero_field' => $result['node']?->get('field_event_image')->getValue(),
  'event_hero_crop_before' => $before ? ['id' => $before->id(), 'w' => $before->get('width')->value] : NULL,
  'event_hero_crop_after' => $after ? ['id' => $after->id(), 'w' => $after->get('width')->value] : NULL,
  'hero_field_has_image_crop_key' => array_key_exists('image_crop', $result['node']?->get('field_event_image')->first()?->getValue() ?? []),
], JSON_PRETTY_PRINT) . "\n";
