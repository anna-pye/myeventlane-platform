<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\file\Entity\File;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event wizard step: Basics (wizard_step_1).
 *
 * Image: Custom managed_file + alt (EventInformationForm pattern). Avoids
 * EntityFormDisplay/image_image widget + form cache issues. Manual save.
 */
final class EventWizardBasicsForm extends EventWizardBaseForm {

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the form.
   */
  public function __construct(
    $entity_type_manager,
    $domain_detector,
    $current_user,
    RendererInterface $renderer,
    LoggerInterface $logger,
  ) {
    parent::__construct($entity_type_manager, $domain_detector, $current_user, $renderer);
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('renderer'),
      $container->get('logger.factory')->get('myeventlane_event'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'event_wizard_basics_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form_state->disableCache();

    $event = $this->getEvent();

    $form_display = EntityFormDisplay::collectRenderDisplay($event, 'wizard_step_1');
    $form_display->removeComponent('field_event_image');
    $form_display->buildForm($event, $form, $form_state);

    $form['#attributes'] = array_merge($form['#attributes'] ?? [], ['enctype' => 'multipart/form-data']);

    if ($event->hasField('field_event_image')) {
      $field_def = $event->getFieldDefinition('field_event_image');
      $settings = $field_def->getSettings();
      $first = $event->get('field_event_image')->first();
      $existing_fid = $first ? [(int) $first->target_id] : [];
      $existing_alt = $first ? ($first->alt ?? '') : '';
      $upload_location = $first ? $first->getUploadLocation() : 'public://events/' . date('Y-m');

      // Store entity image fid for after_build (upload #value/#files may not be ready yet).
      $existing_fid_int = !empty($existing_fid) ? (int) reset($existing_fid) : NULL;

      $form['field_event_image'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Event image'),
        '#weight' => 15,
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-event-image-widget']],
        '#after_build' => [[$this, 'addImagePreviewFromUpload']],
        '#existing_image_fid' => $existing_fid_int,
        '#existing_image_alt' => $existing_alt,
      ];

      // 2. Upload (Choose file). #process adds submit handler so image persists when Upload is clicked (AJAX).
      $form['field_event_image']['upload'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Upload image'),
        '#upload_location' => $upload_location,
        '#default_value' => $existing_fid ?: [],
        '#progress_indicator' => 'throbber',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png gif jpg jpeg webp'],
          'FileIsImage' => [],
          'FileImageDimensions' => [
            'maxDimensions' => $settings['max_resolution'] ?? '4000x4000',
            'minDimensions' => $settings['min_resolution'] ?? '400x200',
          ],
        ],
        '#accept' => 'image/*',
        '#description' => $this->t('Recommended size: 1200×630 pixels.'),
        '#weight' => 0,
        '#process' => [
          [\Drupal\file\Element\ManagedFile::class, 'processManagedFile'],
          [$this, 'processImageUploadButtonSubmit'],
        ],
      ];

      // 3. Alt text (always visible so user can fill it after Upload or Choose file).
      $form['field_event_image']['alt'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Alternative text'),
        '#description' => $this->t('Required for accessibility. Describe the image for screen readers.'),
        '#default_value' => $existing_alt,
        '#maxlength' => 512,
        '#weight' => 5,
      ];
    }

    $form['#title'] = $this->t('Create event: Basics');
    $form['#event'] = $event;
    $form['#step_id'] = 'basics';

    $steps = $this->buildStepper($event, 'basics');
    $form['#steps'] = $steps;

    $next_step = $this->getNextStep('basics');
    $submit_label = $next_step
      ? $this->t('Continue to @step →', ['@step' => $next_step['label']])
      : $this->t('Continue →');

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $submit_label,
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
      '#prefix' => '<div class="mel-wizard-step-card__actions">',
      '#suffix' => '</div>',
    ];

    $form['#prefix'] = $this->buildWizardPrefix($steps, 'basics', (string) $form['#title']);
    $form['#suffix'] = $this->buildWizardSuffix('basics');

    if (isset($form['body'])) {
      $form['body']['#suffix'] = ($form['body']['#suffix'] ?? '') . '</section><section class="mel-wizard-section mel-wizard-section--category">';
    }
    if (isset($form['field_category'])) {
      $form['field_category']['#suffix'] = ($form['field_category']['#suffix'] ?? '') . '</section><section class="mel-wizard-section mel-wizard-section--image">';
    }
    if (isset($form['field_event_image'])) {
      $form['field_event_image']['#suffix'] = ($form['field_event_image']['#suffix'] ?? '') . '</section>';
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $this->processImageUploadIfNeeded($form, $form_state);

    $title_raw = $form_state->getValue('title');
    $title = is_array($title_raw)
      ? trim((string) ($title_raw[0]['value'] ?? $title_raw['value'] ?? ''))
      : trim((string) ($title_raw ?? ''));
    if ($title === '') {
      $form_state->setErrorByName('title', $this->t('Event name is required.'));
    }

    if (isset($form['field_category']) && $this->getEvent()->hasField('field_category')) {
      $category = $form_state->getValue('field_category');
      if (empty($category) || (is_array($category) && empty($category[0]['target_id'] ?? ''))) {
        $form_state->setErrorByName('field_category', $this->t('Category is required.'));
      }
    }

    $fids = $this->getImageFidsFromState($form_state);
    $alt = trim((string) ($form_state->getValue(['field_event_image', 'alt']) ?? $form_state->getValue('alt') ?? ''));
    if (!empty($fids) && empty($alt)) {
      $form_state->setErrorByName('field_event_image][alt', $this->t('Alternative text is required when an image is uploaded.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent();

    // Process file from $_FILES if not yet in form_state (valueCallback can be
    // empty when "Choose file + Continue" in same request; run again before read).
    $this->processImageUploadIfNeeded($form, $form_state);

    $this->saveEventImage($event, $form_state);

    $form_display = EntityFormDisplay::collectRenderDisplay($event, 'wizard_step_1');
    $field_names = array_keys($form_display->getComponents());

    $this->copyFormValuesToEvent($event, $form, $form_state, 'wizard_step_1');

    $this->logger->notice('Event wizard basics saved: event_id=@id, fields=@fields', [
      '@id' => $event->id(),
      '@fields' => implode(', ', $field_names),
    ]);

    $this->redirectToNextStep($form_state, 'basics');
  }

  /**
   * After-build: ensure image preview exists when there is a file; show alt only when image present.
   *
   * Uses #existing_image_fid (from entity) first so preview/alt work even when
   * the managed_file element's #value/#files are not yet populated.
   */
  public function addImagePreviewFromUpload(array $element, FormStateInterface $form_state): array {
    $upload = $element['upload'] ?? NULL;
    $file = NULL;
    $alt = (string) ($element['alt']['#default_value'] ?? $element['#existing_image_alt'] ?? '');

    // 1) Preview from entity (set in buildForm; reliable in after_build).
    $existing_fid = $element['#existing_image_fid'] ?? NULL;
    if ($existing_fid && !isset($element['preview'])) {
      $file = File::load($existing_fid);
    }

    // 2) Else from managed_file #files or #value / #default_value.
    if (!$file && $upload !== NULL) {
      $files = $upload['#files'] ?? [];
      if (!empty($files)) {
        $file = reset($files);
      }
      else {
        $raw = $upload['#value']['fids'] ?? $upload['#default_value'] ?? [];
        $fids = is_array($raw) ? $raw : (array) $raw;
        if (!empty($fids)) {
          $fid = (int) reset($fids);
          $file = $fid ? File::load($fid) : NULL;
        }
      }
    }

    if ($file && !isset($element['preview'])) {
      $uri = $file->getFileUri();
      $element['preview'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-image-preview']],
        '#weight' => -10,
      ];
      if ($uri && file_exists($uri)) {
        $element['preview']['image'] = [
          '#theme' => 'image_style',
          '#style_name' => 'medium',
          '#uri' => $uri,
          '#alt' => $alt,
        ];
      }
      else {
        $element['preview']['placeholder'] = [
          '#markup' => '<div class="mel-event-image-preview__placeholder">' . $this->t('Image file missing; replace or remove.') . '</div>',
          '#weight' => 0,
        ];
      }
    }

    // Alt field is always visible (buildForm) so it shows after Upload/AJAX when
    // #value may not be populated yet in after_build.
    if (isset($element['alt'])) {
      $element['alt']['#access'] = TRUE;
    }

    return $element;
  }

  /**
   * Process callback for the managed_file element: add submit handler to Upload button.
   *
   * Runs after processManagedFile so upload_button exists. When the user clicks
   * Upload (AJAX), this handler runs and persists the fid to the event so
   * Continue (new request) loads the event with the image.
   *
   * @param array $element
   *   The managed_file element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The complete form structure.
   *
   * @return array
   *   The processed element.
   */
  public function processImageUploadButtonSubmit(array $element, FormStateInterface $form_state, array &$complete_form): array {
    if (isset($element['upload_button'])) {
      $element['upload_button']['#submit'][] = [$this, 'submitImageUploadToEvent'];
    }
    return $element;
  }

  /**
   * Submit handler for the managed_file Upload button (AJAX submit).
   *
   * Persists the uploaded file to the event so it survives the next request
   * (e.g. when the user clicks Continue).
   */
  public function submitImageUploadToEvent(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent();
    if (!$event->hasField('field_event_image')) {
      return;
    }
    $fids = $this->getImageFidsFromState($form_state);
    // Fallback: read fid from the managed_file element (validate may set value on element).
    if (empty($fids) && isset($form['field_event_image']['upload'])) {
      $el = $form['field_event_image']['upload'];
      $raw = $el['fids']['#value'] ?? $el['#value']['fids'] ?? $el['#value'] ?? NULL;
      if (is_array($raw) && !empty($raw)) {
        $fids = array_filter(array_map('intval', (array) $raw));
      }
      elseif (is_numeric($raw) && (int) $raw > 0) {
        $fids = [(int) $raw];
      }
    }
    if (empty($fids)) {
      return;
    }
    $fid = (int) reset($fids);
    $alt = trim((string) ($form_state->getValue(['field_event_image', 'alt']) ?? $form_state->getValue('alt') ?? ''));
    $file = File::load($fid);
    if (!$file) {
      return;
    }
    $file->setPermanent();
    $file->save();
    $event->set('field_event_image', [
      ['target_id' => $fid, 'alt' => $alt, 'title' => ''],
    ]);
    $event->save();
  }

  /**
   * Processes file from $_FILES when user selects file and clicks Continue
   * without using the Upload button (same-request submit).
   */
  protected function processImageUploadIfNeeded(array &$form, FormStateInterface $form_state): void {
    if (!isset($form['field_event_image']['upload']) || ($form['field_event_image']['upload']['#type'] ?? '') !== 'managed_file') {
      return;
    }

    if (!empty($this->getImageFidsFromState($form_state))) {
      return;
    }

    $element = &$form['field_event_image']['upload'];
    $request = \Drupal::request();
    $files = $request->files->get('files', []);
    $upload_name = implode('_', $element['#parents'] ?? ['field_event_image', 'upload']);
    $file_upload = $files[$upload_name] ?? NULL;
    $used_nested = FALSE;
    // Symfony may nest: files[field_event_image][upload] -> get('files')['field_event_image']['upload'].
    if (empty($file_upload) && is_array($files) && isset($files['field_event_image']['upload'])) {
      $file_upload = $files['field_event_image']['upload'];
      $used_nested = TRUE;
    }
    if (empty($file_upload)) {
      $this->logger->debug('Event wizard basics: no file in request. upload_name=@name, files_keys=@keys', [
        '@name' => $upload_name,
        '@keys' => is_array($files) ? implode(',', array_keys($files)) : gettype($files),
      ]);
      return;
    }
    // Core file_managed_file_save_upload expects flat key; if we used nested, re-set request so it finds it.
    if ($used_nested) {
      $request->files->set('files', [$upload_name => $file_upload]);
    }

    $result = file_managed_file_save_upload($element, $form_state);
    if (empty($result) || !is_array($result)) {
      return;
    }

    $fids = array_keys(array_filter($result));
    if (empty($fids)) {
      return;
    }

    $form_state->setValue(['field_event_image', 'upload'], ['fids' => $fids]);
  }

  /**
   * Extracts fids from our custom image upload element.
   *
   * Tries nested path, full tree, and user_input so values are found regardless
   * of how the form state stored them (e.g. after AJAX upload).
   *
   * @return array<int>
   */
  protected function getImageFidsFromState(FormStateInterface $form_state): array {
    $raw = $form_state->getValue(['field_event_image', 'upload']) ?? $form_state->getValue('upload');
    if (empty($raw)) {
      $tree = $form_state->getValue('field_event_image');
      $raw = is_array($tree) && isset($tree['upload']) ? $tree['upload'] : NULL;
    }
    if (empty($raw)) {
      $input = $form_state->getUserInput();
      $raw = $input['field_event_image']['upload'] ?? $input['upload'] ?? NULL;
    }
    // Managed file may store fids at nested path (hidden 'fids' element).
    if (empty($raw)) {
      $nested = $form_state->getValue(['field_event_image', 'upload', 'fids']);
      if (is_array($nested)) {
        $raw = ['fids' => $nested];
      }
      elseif (is_numeric($nested) && (int) $nested > 0) {
        return [(int) $nested];
      }
    }
    if (is_array($raw) && !empty($raw['fids'])) {
      return array_filter(array_map('intval', (array) $raw['fids']));
    }
    if (is_array($raw)) {
      $fids = array_filter(array_map('intval', array_values($raw)));
      if (!empty($fids)) {
        return $fids;
      }
    }
    if (is_numeric($raw) && (int) $raw > 0) {
      return [(int) $raw];
    }
    return [];
  }

  /**
   * Saves the event image from our custom upload element.
   */
  protected function saveEventImage($event, FormStateInterface $form_state): void {
    if (!$event->hasField('field_event_image')) {
      return;
    }

    $fids = $this->getImageFidsFromState($form_state);
    if (empty($fids)) {
      // Do not clear existing image (e.g. set by Upload button AJAX submit).
      return;
    }

    $fid = (int) reset($fids);
    $alt = trim((string) ($form_state->getValue(['field_event_image', 'alt']) ?? $form_state->getValue('alt') ?? ''));
    $file = File::load($fid);
    if (!$file) {
      return;
    }

    $file->setPermanent();
    $file->save();

    $event->set('field_event_image', [
      ['target_id' => $fid, 'alt' => $alt, 'title' => ''],
    ]);
  }

}
