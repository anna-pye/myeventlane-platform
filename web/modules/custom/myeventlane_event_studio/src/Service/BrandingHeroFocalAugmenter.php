<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\crop\Entity\Crop;
use Drupal\file\FileInterface;
use Drupal\focal_point\FocalPointManagerInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;
use Drupal\image_widget_crop\Element\ImageCrop;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Adds focal point controls to the branding image_widget_crop hero field.
 *
 * Public event and book heroes use the event_hero crop (see mel_event_hero_featured).
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
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly EventStudioSaveService $eventStudioSaveService,
    private readonly LoggerInterface $logger,
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
  public function attachAfterBuild(array &$field_element, NodeInterface $node, ?string $focal_override = NULL): void {
    if ($focal_override !== NULL && trim($focal_override) !== '') {
      $field_element['#mel_branding_focal_override'] = trim($focal_override);
    }
    // Callable must be serializable — instance [$this, …] breaks form cache (AJAX/crop/media).
    $field_element['#mel_branding_node_id'] = (int) $node->id();
    if (isset($field_element['widget']) && is_array($field_element['widget'])) {
      foreach ($field_element['widget'] as $delta_key => &$delta) {
        if (!is_numeric($delta_key) || !is_array($delta)) {
          continue;
        }
        $delta['#process'] = is_array($delta['#process'] ?? NULL) ? $delta['#process'] : [];
        $delta['#process'][] = [self::class, 'formProcessHeroWidget'];
      }
      unset($delta);
    }
    $field_element['#after_build'][] = [self::class, 'formAfterBuildFieldFromElement'];
  }

  /**
   * Adds the fid-retention submit callback before Form API resolves the trigger.
   *
   * @param array<string, mixed> $element
   * @param array<string, mixed> $form
   *
   * @return array<string, mixed>
   */
  public static function formProcessHeroWidget(
    array $element,
    FormStateInterface $form_state,
    array &$form,
  ): array {
    if (isset($element['upload_button']['#submit']) && is_array($element['upload_button']['#submit'])) {
      $element['upload_button']['#submit'][] = [self::class, 'formRetainManagedHeroUpload'];
    }
    return $element;
  }

  /**
   * Serializable #after_build entry point (form cache / AJAX safe).
   *
   * @param array<string, mixed> $element
   *
   * @return array<string, mixed>
   */
  public static function formAfterBuildFieldFromElement(array $element, FormStateInterface $form_state): array {
    $augmenter = \Drupal::service('myeventlane_event_studio.branding_hero_focal_augmenter');
    assert($augmenter instanceof self);
    return $augmenter->afterBuildFieldFromElement($element, $form_state);
  }

  /**
   * Serializable #element_validate entry point (form cache / AJAX safe).
   *
   * @param array<string, mixed> $element
   */
  public static function formValidateFocalPointElement(array &$element, FormStateInterface $form_state): void {
    $augmenter = \Drupal::service('myeventlane_event_studio.branding_hero_focal_augmenter');
    assert($augmenter instanceof self);
    $augmenter->validateFocalPointElement($element, $form_state);
  }

  /**
   * Keeps required-crop validation on final save, not managed-file AJAX.
   *
   * Image Widget Crop's upload detection expects the legacy string submit
   * callback. Drupal 11 supplies ManagedFile::submit as a callable array, so
   * the contrib validator otherwise treats Choose file / Remove as final save.
   *
   * @param array<string, mixed> $element
   */
  public static function formValidateRequiredCrop(array &$element, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $name = is_array($trigger) ? (string) ($trigger['#name'] ?? '') : '';
    if (str_ends_with($name, '_upload_button') || str_ends_with($name, '_remove_button')) {
      return;
    }
    ImageCrop::cropRequired($element, $form_state);
  }

  /**
   * Retains the uploaded fid across this manually embedded widget's rebuild.
   *
   * @param array<string, mixed> $form
   */
  public static function formRetainManagedHeroUpload(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $array_parents = is_array($trigger) ? ($trigger['#array_parents'] ?? NULL) : NULL;
    if (!is_array($array_parents) || array_pop($array_parents) !== 'upload_button') {
      return;
    }
    $managed = NestedArray::getValue($form, $array_parents);
    if (!is_array($managed) || !isset($managed['fids']) || !is_array($managed['fids'])) {
      return;
    }
    $fids = array_map('intval', array_keys(is_array($managed['#files'] ?? NULL) ? $managed['#files'] : []));
    $fids = array_values(array_filter($fids, static fn (int $fid): bool => $fid > 0));
    $fids_parents = $managed['fids']['#parents'] ?? NULL;
    if ($fids === [] || !is_array($fids_parents)) {
      return;
    }
    $value = implode(' ', $fids);
    $form_state->setValueForElement($managed['fids'], $value);
    $user_input = $form_state->getUserInput();
    NestedArray::setValue($user_input, $fids_parents, $value);
    $form_state->setUserInput($user_input);
  }

  /**
   * Form API #after_build callback (loads node from #mel_branding_node_id).
   *
   * @param array<string, mixed> $element
   *   The field_event_image element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   */
  public function afterBuildFieldFromElement(array $element, FormStateInterface $form_state): array {
    $nid = (int) ($element['#mel_branding_node_id'] ?? 0);
    if ($nid < 1) {
      return $element;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      return $element;
    }
    return $this->afterBuildField($element, $node);
  }

  /**
   * Injects focal_point field + preview indicator into the hero widget tree.
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
      $override = isset($element['#mel_branding_focal_override'])
        ? (string) $element['#mel_branding_focal_override']
        : NULL;
      $this->augmentWidgetDelta($element['widget'][$delta_key], $node, $override);
      $this->augmentCropWorkspace($element['widget'][$delta_key]);
      $this->suppressWidgetAltField($element['widget'][$delta_key]);
    }

    // Keep the saved public preview available even when a rebuilt image widget
    // has no processed delta (for example after Media Library AJAX or stale
    // form recovery). The node field remains the source of truth.
    $this->attachBrandingHeroPreviewSettings($element, $node, []);

    return $element;
  }

  /**
   * Hides the crop widget alt field; branding uses mel[field_event_image_alt].
   *
   * @param array<string, mixed> $delta
   */
  private function suppressWidgetAltField(array &$delta): void {
    if (isset($delta['alt']) && is_array($delta['alt'])) {
      $delta['alt']['#access'] = FALSE;
      $delta['alt']['#required'] = FALSE;
    }
  }

  /**
   * Injects focal_point field + preview indicator into one widget delta.
   *
   * @param array<string, mixed> $delta
   */
  private function augmentWidgetDelta(array &$delta, NodeInterface $node, ?string $focal_override = NULL): void {
    // #parents and #array_parents from the processed widget delta (after
    // doBuildForm). FormErrorHandler requires #array_parents on every child.
    $delta_parents = $delta['#parents'] ?? [];
    if (!is_array($delta_parents)) {
      $delta_parents = [];
    }
    $delta_array_parents = $delta['#array_parents'] ?? NULL;
    if (!is_array($delta_array_parents)) {
      return;
    }

    if (isset($delta['focal_point']) && is_array($delta['focal_point'])) {
      $delta['focal_point']['#type'] = 'hidden';
      $delta['focal_point']['#element_validate'] = [[self::class, 'formValidateFocalPointElement']];
      unset(
        $delta['focal_point']['#title'],
        $delta['focal_point']['#description'],
        $delta['focal_point']['#description_display'],
        $delta['focal_point']['#wrapper_attributes'],
        $delta['focal_point']['#attached'],
      );
      $this->attachBrandingHeroPreviewSettings($delta, $node, $delta);
      $this->suppressWidgetAltField($delta);
      return;
    }

    $focal_value = $focal_override !== NULL && $this->focalPointManager->validateFocalPoint($focal_override)
      ? $focal_override
      : $this->resolveFocalPoint($node, $delta);
    $selector = 'focal-point-mel-field-event-image-0';

    $delta['focal_point'] = [
      '#type' => 'hidden',
      '#default_value' => $focal_value,
      '#element_validate' => [[self::class, 'formValidateFocalPointElement']],
      '#parents' => array_merge($delta_parents, ['focal_point']),
      '#array_parents' => array_merge($delta_array_parents, ['focal_point']),
      '#attributes' => [
        'class' => ['focal-point', $selector],
        'data-selector' => $selector,
        'data-field-name' => 'field_event_image',
      ],
      '#weight' => 20,
    ];

    if (isset($delta['preview']) && is_array($delta['preview'])) {
      $thumbnail = $delta['preview'];
      $preview_weight = $thumbnail['#weight'] ?? 0;
      unset($thumbnail['#weight']);
      $thumbnail['#parents'] = array_merge($delta_parents, ['preview', 'thumbnail']);
      $thumbnail['#array_parents'] = array_merge($delta_array_parents, ['preview', 'thumbnail']);
      $delta['preview'] = [
        '#parents' => array_merge($delta_parents, ['preview']),
        '#array_parents' => array_merge($delta_array_parents, ['preview']),
        'indicator' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#parents' => array_merge($delta_parents, ['preview', 'indicator']),
          '#array_parents' => array_merge($delta_array_parents, ['preview', 'indicator']),
          '#attributes' => [
            'class' => ['focal-point-indicator'],
            'data-selector' => $selector,
            'data-delta' => 0,
          ],
        ],
        'thumbnail' => $thumbnail,
        '#weight' => $preview_weight,
      ];
    }

    $this->attachBrandingHeroPreviewSettings($delta, $node, $delta);
  }

  /**
   * Turns the single crop type into a clear organiser-facing framing step.
   *
   * @param array<string, mixed> $delta
   *   Processed image widget delta.
   */
  private function augmentCropWorkspace(array &$delta): void {
    if (!isset($delta['image_crop']['crop_wrapper']) || !is_array($delta['image_crop']['crop_wrapper'])) {
      return;
    }
    $delta['image_crop']['#element_validate'] = [[self::class, 'formValidateRequiredCrop']];
    $wrapper = &$delta['image_crop']['crop_wrapper'];
    $wrapper_parents = is_array($wrapper['#parents'] ?? NULL) ? $wrapper['#parents'] : [];
    $wrapper_array_parents = is_array($wrapper['#array_parents'] ?? NULL) ? $wrapper['#array_parents'] : [];
    $guidance_parents = array_merge($wrapper_parents, ['mel_guidance']);
    $guidance_array_parents = array_merge($wrapper_array_parents, ['mel_guidance']);

    $wrapper['#title'] = $this->t('Adjust 16:9 framing');
    $wrapper['mel_guidance'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#weight' => -30,
      '#parents' => $guidance_parents,
      '#array_parents' => $guidance_array_parents,
      '#attributes' => [
        'class' => ['mel-es-branding-crop-guidance'],
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#parents' => array_merge($guidance_parents, ['title']),
        '#array_parents' => array_merge($guidance_array_parents, ['title']),
        '#attributes' => [
          'class' => ['mel-es-branding-crop-guidance__title'],
        ],
        '#value' => $this->t('Choose what guests will see'),
      ],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#parents' => array_merge($guidance_parents, ['text']),
        '#array_parents' => array_merge($guidance_array_parents, ['text']),
        '#attributes' => [
          'class' => ['mel-es-branding-crop-guidance__text'],
        ],
        '#value' => $this->t('Drag the frame to choose what guests will see. Keep faces, performers and important details inside the box. Resize using the corners.'),
      ],
      'aftercare' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#parents' => array_merge($guidance_parents, ['aftercare']),
        '#array_parents' => array_merge($guidance_array_parents, ['aftercare']),
        '#attributes' => [
          'class' => ['mel-es-branding-crop-guidance__aftercare'],
        ],
        '#value' => $this->t('Use Reset crop to start again, then click Save branding.'),
      ],
    ];
  }

  /**
   * Exposes the mel_event_hero_featured URL for the 16:9 framing preview.
   *
   * Uses the same image style and event_hero crop as public event and book pages.
   *
   * @param array<string, mixed> $delta
   */
  private function attachBrandingHeroPreviewSettings(array &$delta, NodeInterface $node, array $widget_delta): void {
    $file = $this->resolveHeroFile($node, $widget_delta);
    if (!$file instanceof FileInterface) {
      return;
    }
    if (!$this->eventStudioSaveService->isHeroFileRenderable($file)) {
      $this->logger->warning('Branding hero tools: file @fid on node @nid is not renderable; omitting framing preview URL.', [
        '@fid' => (string) $file->id(),
        '@nid' => (string) $node->id(),
      ]);
      return;
    }
    $uri = $file->getFileUri();
    if ($uri === '') {
      return;
    }
    $preview_url = $this->buildBrandingHeroFeaturedUrl($uri);
    if ($preview_url === NULL) {
      return;
    }
    $delta['#attached']['drupalSettings']['myeventlane_event_studio']['brandingHero'] = [
      'sourceUrl' => $preview_url,
      'fileId' => (int) $file->id(),
    ];
  }

  /**
   * Builds the featured hero style URL used on public event and book pages.
   */
  private function buildBrandingHeroFeaturedUrl(string $uri): ?string {
    $style = ImageStyle::load('mel_event_hero_featured');
    if (!$style instanceof ImageStyleInterface) {
      return NULL;
    }
    try {
      return $style->buildUrl($uri);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Branding hero tools: could not build mel_event_hero_featured URL for @uri: @message', [
        '@uri' => $uri,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
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

    $file = $this->resolveHeroFile($node, $delta);
    if ($file instanceof FileInterface) {
      $from_crop = $this->focalPointFromFileCrop($file);
      if ($from_crop !== NULL) {
        return $from_crop;
      }
    }

    return self::DEFAULT_FOCAL;
  }

  /**
   * Loads the hero file from the node field or an in-progress widget delta.
   *
   * Focal point is stored on the file's focal_point crop entity, not on the image
   * field item (image fields do not expose a focal_point property).
   *
   * @param array<string, mixed> $delta
   */
  private function resolveHeroFile(NodeInterface $node, array $delta = []): ?FileInterface {
    $widget_fid = EventStudioMelPayloadService::firstPositiveIntFromFidsValue(
      $delta['fids']['#value'] ?? $delta['fids'] ?? $delta['target_id']['#value'] ?? $delta['target_id'] ?? NULL
    );

    if ($widget_fid > 0) {
      $widget_file = $this->entityTypeManager->getStorage('file')->load($widget_fid);
      if ($widget_file instanceof FileInterface && $this->eventStudioSaveService->isHeroFileRenderable($widget_file)) {
        return $widget_file;
      }
    }

    if ($node->hasField('field_event_image') && !$node->get('field_event_image')->isEmpty()) {
      $file = $node->get('field_event_image')->entity;
      if ($file instanceof FileInterface && $this->eventStudioSaveService->isHeroFileRenderable($file)) {
        return $file;
      }
    }

    return NULL;
  }

  /**
   * Validates the augmented focal_point textfield on the branding form.
   *
   * Do not use FocalPointImageWidget::validateFocalPoint() here: it assumes a
   * widget-integrated element tree and breaks when #parents is not an array.
   * Empty values are allowed; save defaults to 50,50.
   *
   * @param array<string, mixed> $element
   */
  public function validateFocalPointElement(array &$element, FormStateInterface $form_state): void {
    $value = trim((string) ($element['#value'] ?? ''));
    if ($value === '') {
      return;
    }
    if ($this->focalPointManager->validateFocalPoint($value)) {
      return;
    }
    $parents = $element['#parents'] ?? [];
    $name = is_array($parents) ? implode('][', $parents) : (string) $parents;
    if ($name === '' && isset($element['#name'])) {
      $name = (string) $element['#name'];
    }
    $form_state->setErrorByName($name, $this->t('Focal point must be percentages like 50,50 (horizontal, vertical).'));
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
