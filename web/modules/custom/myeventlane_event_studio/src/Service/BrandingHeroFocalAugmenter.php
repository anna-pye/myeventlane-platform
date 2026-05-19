<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\crop\Entity\Crop;
use Drupal\file\FileInterface;
use Drupal\focal_point\FocalPointManagerInterface;
use Drupal\focal_point\Plugin\Field\FieldWidget\FocalPointImageWidget;
use Drupal\node\NodeInterface;

/**
 * Adds focal point controls to the branding image_widget_crop hero field.
 *
 * Public event and book heroes use the focal_point crop (see mel_event_hero_featured).
 * The studio branding form uses image_widget_crop for the fixed event_hero crop; this
 * augmenter wires the focal_point form element and indicator expected by focal_point
 * JS and mel-branding-hero-tools.js without replacing the crop widget.
 */
final class BrandingHeroFocalAugmenter {

  use StringTranslationTrait;

  public const DEFAULT_FOCAL = '50,50';

  public function __construct(
    private readonly FocalPointManagerInterface $focalPointManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ImageFactory $imageFactory,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Registers #after_build to inject focal point UI into the hero widget.
   *
   * @param array<string, mixed> $field_element
   *   The field_event_image form element from the entity form display widget.
   */
  public function attachAfterBuild(array &$field_element, NodeInterface $node): void {
    $field_element['#after_build'][] = function (array $element, $form_state) use ($node): array {
      return $this->afterBuildField($element, $node);
    };
  }

  /**
   * Form API #after_build callback.
   *
   * @param array<string, mixed> $element
   *   The field_event_image element.
   *
   * @return array<string, mixed>
   */
  public function afterBuildField(array $element, NodeInterface $node): array {
    if (!isset($element['widget']) || !is_array($element['widget'])) {
      return $element;
    }

    foreach (array_keys($element['widget']) as $delta) {
      if (!is_numeric($delta)) {
        continue;
      }
      $delta_key = (int) $delta;
      if (!isset($element['widget'][$delta_key]) || !is_array($element['widget'][$delta_key])) {
        continue;
      }
      $this->augmentWidgetDelta($element['widget'][$delta_key], $node);
    }

    return $element;
  }

  /**
   * Injects focal_point field + preview indicator into one widget delta.
   *
   * @param array<string, mixed> $delta
   */
  private function augmentWidgetDelta(array &$delta, NodeInterface $node): void {
    if (isset($delta['focal_point'])) {
      return;
    }

    $focal_value = $this->resolveFocalPoint($node, $delta);
    $selector = 'focal-point-mel-field-event-image-0';

    $delta['focal_point'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Focal point'),
      '#description' => $this->t('Focus area for the public event and book page hero (16:9). Saved when you save branding.'),
      '#default_value' => $focal_value,
      '#element_validate' => [[FocalPointImageWidget::class, 'validateFocalPoint']],
      '#attributes' => [
        'class' => ['focal-point', $selector],
        'data-selector' => $selector,
        'data-field-name' => 'field_event_image',
        'aria-describedby' => 'mel-es-branding-focal-status',
      ],
      '#wrapper_attributes' => [
        'class' => ['focal-point-wrapper'],
      ],
      '#attached' => [
        'library' => ['focal_point/drupal.focal_point'],
      ],
      '#weight' => 20,
    ];

    if (isset($delta['preview']) && is_array($delta['preview'])) {
      $preview_weight = $delta['preview']['#weight'] ?? 0;
      unset($delta['preview']['#weight']);
      $delta['preview'] = [
        'indicator' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => [
            'class' => ['focal-point-indicator'],
            'data-selector' => $selector,
            'data-delta' => 0,
          ],
        ],
        'thumbnail' => $delta['preview'],
        '#weight' => $preview_weight,
      ];
    }
  }

  /**
   * Resolves a focal point string (x,y percentages) for the branding hero.
   *
   * @param array<string, mixed> $delta
   *   Optional widget delta (may include focal_point from draft POST).
   */
  public function resolveFocalPoint(NodeInterface $node, array $delta = []): string {
    if (isset($delta['focal_point']['#default_value'])) {
      $candidate = trim((string) $delta['focal_point']['#default_value']);
      if ($this->focalPointManager->validateFocalPoint($candidate)) {
        return $candidate;
      }
    }
    if (isset($delta['focal_point']) && is_string($delta['focal_point'])) {
      $candidate = trim($delta['focal_point']);
      if ($this->focalPointManager->validateFocalPoint($candidate)) {
        return $candidate;
      }
    }

    if ($node->hasField('field_event_image') && !$node->get('field_event_image')->isEmpty()) {
      $item = $node->get('field_event_image')->first();
      if ($item !== NULL) {
        $stored = $item->get('focal_point');
        if ($stored !== NULL && !$stored->isEmpty()) {
          $candidate = trim((string) $stored->value);
          if ($this->focalPointManager->validateFocalPoint($candidate)) {
            return $candidate;
          }
        }
        $file = $item->entity;
        if ($file instanceof FileInterface) {
          $from_crop = $this->focalPointFromFileCrop($file);
          if ($from_crop !== NULL) {
            return $from_crop;
          }
        }
      }
    }

    return self::DEFAULT_FOCAL;
  }

  /**
   * Reads focal_point crop entity coordinates for a file.
   */
  private function focalPointFromFileCrop(FileInterface $file): ?string {
    $crop_type = (string) $this->configFactory->get('focal_point.settings')->get('crop_type');
    if ($crop_type === '') {
      return NULL;
    }
    $crop = Crop::findCrop($file->getFileUri(), $crop_type);
    if ($crop === NULL || $crop->get('x')->isEmpty() || $crop->get('y')->isEmpty()) {
      return NULL;
    }
    $image = $this->imageFactory->get($file->getFileUri());
    if (!$image->isValid()) {
      return NULL;
    }
    $anchor = $this->focalPointManager->absoluteToRelative(
      (float) $crop->get('x')->value,
      (float) $crop->get('y')->value,
      $image->getWidth(),
      $image->getHeight(),
    );
    $value = ((int) $anchor['x']) . ',' . ((int) $anchor['y']);
    return $this->focalPointManager->validateFocalPoint($value) ? $value : NULL;
  }

}
