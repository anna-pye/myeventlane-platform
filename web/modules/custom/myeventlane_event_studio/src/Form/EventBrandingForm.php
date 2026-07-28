<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\myeventlane_event_studio\Service\BrandingHeroFocalAugmenter;
use Drupal\myeventlane_event_studio\Service\EventBrandingPreviewBuilder;
use Drupal\myeventlane_event_studio\Service\EventPageStyleResolver;
use Drupal\myeventlane_event_studio\Service\EventStudioMelPayloadService;
use Drupal\myeventlane_event_studio\Service\EventStyleAccessManager;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Isolated Event Studio form for event branding.
 */
final class EventBrandingForm extends EventStudioBaseForm {

  private EventStyleAccessManager $eventStyleAccess;

  private EventPageStyleResolver $eventPageStyleResolver;

  private BrandingHeroFocalAugmenter $brandingHeroFocalAugmenter;

  private EventBrandingPreviewBuilder $eventBrandingPreviewBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->ensureInjectedServices();
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function __wakeup(): void {
    parent::__wakeup();
  }

  /**
   * Ensures base and branding services are present after form cache restore.
   *
   * Cached form state restores the form object in build_info. Properties assigned
   * after parent::create() are not re-initialized unless restored here.
   */
  protected function ensureInjectedServices(): void {
    parent::ensureInjectedServices();
    if (isset($this->eventStyleAccess, $this->eventPageStyleResolver, $this->brandingHeroFocalAugmenter, $this->eventBrandingPreviewBuilder)) {
      return;
    }
    $container = \Drupal::getContainer();
    if (!isset($this->eventStyleAccess)) {
      $this->eventStyleAccess = $container->get('myeventlane_event_studio.event_style_access');
    }
    if (!isset($this->eventPageStyleResolver)) {
      $this->eventPageStyleResolver = $container->get('myeventlane_event_studio.event_page_style_resolver');
    }
    if (!isset($this->brandingHeroFocalAugmenter)) {
      $this->brandingHeroFocalAugmenter = $container->get('myeventlane_event_studio.branding_hero_focal_augmenter');
    }
    if (!isset($this->eventBrandingPreviewBuilder)) {
      $this->eventBrandingPreviewBuilder = $container->get('myeventlane_event_studio.event_branding_preview_builder');
    }
  }

  public function getFormId(): string {
    return 'myeventlane_event_studio_branding_form';
  }

  protected function getNextRouteName(): string {
    return 'myeventlane_event_studio.workspace_branding';
  }

  protected function getPreviousRouteName(): ?string {
    return NULL;
  }

  protected function getCurrentStepId(): string {
    return 'branding';
  }

  protected function getContinueButtonLabel() {
    return $this->t('Save changes');
  }

  protected function onWizardStepSaveSuccess(NodeInterface $saved, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($this->t('Branding saved. Your public event page preview has been updated.'));
    $form_state->setRedirect('myeventlane_event_studio.workspace_branding', ['node' => $saved->id()]);
  }

  /**
   * Branding save persists hero media to the node for public/book pages.
   */
  protected function isDraftWizardSave(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function persistWizardMel(FormStateInterface $form_state, bool $draft): ?array {
    $nid = (int) ($form_state->getValue('nid') ?? 0);
    if ($nid < 1) {
      return NULL;
    }
    $loaded = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$loaded instanceof NodeInterface) {
      return NULL;
    }
    $this->assertVendorEvent($loaded);

    $baseline = $this->wizardMelBaseline->getBaselineMel($loaded);
    $submitted = $form_state->getValue('mel') ?? [];
    if (!is_array($submitted)) {
      $submitted = [];
    }
    $merged = $this->mergeMel($baseline, $submitted);

    return $this->saveService->saveBrandingHero($loaded, $merged, $form_state, $draft);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $this->ensureInjectedServices();
    $managed_file_action = $this->isBrandingHeroManagedFileAction($form_state);
    $nid = (int) ($form_state->getValue('nid') ?? 0);
    if ($nid > 0) {
      $this->saveService->prepareBrandingHeroFormStateForValidation($form_state);
    }
    parent::validateForm($form, $form_state);

    // Choose file / Remove are AJAX staging actions. They must rebuild the
    // crop editor before final branding validation runs.
    if ($managed_file_action || $nid < 1) {
      return;
    }
    $loaded = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$loaded instanceof NodeInterface) {
      return;
    }

    $mel = $form_state->getValue('mel') ?? [];
    if (!is_array($mel)) {
      return;
    }

    $style = isset($mel['field_mel_page_style']) && is_scalar($mel['field_mel_page_style'])
      ? (string) $mel['field_mel_page_style']
      : '';
    $colour = isset($mel['field_mel_theme_colour']) && is_scalar($mel['field_mel_theme_colour'])
      ? (string) $mel['field_mel_theme_colour']
      : '';

    $sanitized_style = $this->eventPageStyleResolver->sanitizeStyle($style !== '' ? $style : NULL);
    $sanitized_colour = $this->eventPageStyleResolver->sanitizeColour($colour !== '' ? $colour : NULL);

    if ($style !== '' && $sanitized_style !== $style) {
      $form_state->setErrorByName(
        'mel][field_mel_page_style',
        $this->t('Choose a valid event page style.')
      );
    }

    if ($colour !== '' && $sanitized_colour !== $colour) {
      $form_state->setErrorByName(
        'mel][field_mel_theme_colour',
        $this->t('Choose a valid event theme colour.')
      );
    }

    if ($style !== '' && !$this->eventStyleAccess->canUseStyle($sanitized_style, $this->currentUser)) {
      $form_state->setErrorByName(
        'mel][field_mel_page_style',
        $this->t('Custom page styles are a Pro feature.')
      );
    }

    if ($colour !== '' && !$this->eventStyleAccess->canUseColour($sanitized_colour, $this->currentUser)) {
      $form_state->setErrorByName(
        'mel][field_mel_theme_colour',
        $this->t('Custom colour palettes are a Pro feature.')
      );
    }

    $mel_for_hero = $mel;
    $user_mel = $form_state->getUserInput()['mel'] ?? NULL;
    if (is_array($user_mel)) {
      $mel_for_hero = array_replace_recursive($mel_for_hero, $user_mel);
    }
    $hero = EventStudioMelPayloadService::normalizeHeroFromMelFragment($mel_for_hero);
    if ($hero['fid'] > 0 && $hero['alt'] === '') {
      $form_state->setErrorByName(
        'mel][field_event_image_alt',
        $this->t('Add alt text for your cover image before saving. Describe what appears in the image for screen readers and search.')
      );
    }

    $this->validateBrandingHeroPendingUpload($form_state);
    $this->validateBrandingHeroUploadResolution($form_state, $mel_for_hero);
  }

  /**
   * Whether the current submission is staging or removing a managed hero file.
   */
  private function isBrandingHeroManagedFileAction(FormStateInterface $form_state): bool {
    $trigger = $form_state->getTriggeringElement();
    $name = is_array($trigger) ? (string) ($trigger['#name'] ?? '') : '';
    return str_ends_with($name, '_upload_button') || str_ends_with($name, '_remove_button');
  }

  /**
   * Blocks save when a file was chosen but Upload was not clicked (managed_file pattern).
   *
   * Applies even when a previous cover fid is still in the form (replace-without-upload).
   */
  private function validateBrandingHeroPendingUpload(FormStateInterface $form_state): void {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return;
    }

    $files = $request->files->all();
    $upload = $files['files']['mel_field_event_image_0'] ?? NULL;
    if ($upload === NULL) {
      return;
    }

    $original_name = trim((string) $upload->getClientOriginalName());
    if ($original_name === '') {
      return;
    }

    $form_state->setErrorByName(
      'mel][field_event_image',
      $this->t('Click Upload before Save branding. Upload alone does not publish your cover.')
    );
  }

  /**
   * Surfaces widget sync failures before save when an upload cannot be resolved.
   *
   * @param array<string, mixed> $mel_for_hero
   */
  private function validateBrandingHeroUploadResolution(FormStateInterface $form_state, array $mel_for_hero): void {
    $user_mel = $form_state->getUserInput()['mel'] ?? NULL;
    if (!is_array($user_mel)) {
      return;
    }

    $input_fragment = $this->brandingHeroInputFragmentWithFid($user_mel);
    if ($input_fragment === NULL) {
      return;
    }

    $input_fid = EventStudioMelPayloadService::normalizeHeroFromMelFragment([
      'field_event_image' => $input_fragment,
    ])['fid'];
    $resolved_fid = EventStudioMelPayloadService::normalizeHeroFromMelFragment($mel_for_hero)['fid'];
    if ($resolved_fid > 0) {
      return;
    }
    $form_state->setErrorByName(
      'mel][field_event_image',
      $this->t('The event image could not be saved. Please reselect the image.')
    );
  }

  /**
   * Resolves the submitted hero image fragment that contains an uploaded fid.
   *
   * image_widget_crop can submit an empty direct field array while the uploaded
   * file data lives under field_event_image_wrapper.widget.
   *
   * @param array<string, mixed> $user_mel
   *
   * @return array<string, mixed>|null
   */
  private function brandingHeroInputFragmentWithFid(array $user_mel): ?array {
    $candidates = [];
    if (isset($user_mel['field_event_image']) && is_array($user_mel['field_event_image'])) {
      $candidates[] = $user_mel['field_event_image'];
    }

    $wrapper = $user_mel['field_event_image_wrapper'] ?? NULL;
    if (is_array($wrapper)) {
      if (isset($wrapper['widget']) && is_array($wrapper['widget'])) {
        $candidates[] = $wrapper['widget'];
      }
      if (isset($wrapper[0]) && is_array($wrapper[0])) {
        $candidates[] = $wrapper;
      }
    }

    foreach ($candidates as $fragment) {
      $fid = EventStudioMelPayloadService::normalizeHeroFromMelFragment([
        'field_event_image' => $fragment,
      ])['fid'];
      if ($fid > 0) {
        return $fragment;
      }
    }

    return NULL;
  }

  /**
   * Applies restored-draft hero defaults onto a clone for widget rendering only.
   */
  private function applyDraftHeroOverlay(NodeInterface $target, array $melDefaults): void {
    if (!array_key_exists('field_event_image', $melDefaults)) {
      return;
    }
    $hero = EventStudioMelPayloadService::normalizeHeroFromMelFragment($melDefaults);
    if ($hero['fid'] < 1) {
      $target->set('field_event_image', []);
      return;
    }
    $target->set('field_event_image', [
      [
        'target_id' => $hero['fid'],
        'alt' => $hero['alt'],
      ],
    ]);
  }

  /**
   * Keeps a managed-file AJAX upload on the render-only entity clone.
   *
   * This widget is embedded manually rather than built by a full entity form.
   * After ManagedFile creates a temporary file, the rebuilt widget therefore
   * needs the submitted fid overlaid onto the clone explicitly.
   */
  private function applySubmittedHeroOverlay(
    NodeInterface $target,
    FormStateInterface $form_state,
  ): void {
    $candidates = [];
    $user_mel = $form_state->getUserInput()['mel'] ?? NULL;
    if (is_array($user_mel)) {
      $candidates[] = $user_mel;
    }
    $value_mel = $form_state->getValue('mel');
    if (is_array($value_mel)) {
      $candidates[] = $value_mel;
    }

    foreach ($candidates as $mel) {
      $fragment = $this->brandingHeroInputFragmentWithFid($mel);
      if ($fragment === NULL) {
        continue;
      }
      $hero = EventStudioMelPayloadService::normalizeHeroFromMelFragment([
        'field_event_image' => $fragment,
        'field_event_image_alt' => $mel['field_event_image_alt'] ?? '',
      ]);
      if ($hero['fid'] < 1) {
        continue;
      }
      $target->set('field_event_image', [[
        'target_id' => $hero['fid'],
        'alt' => $hero['alt'],
      ]]);
      return;
    }
  }

  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $this->ensureInjectedServices();
    $request = $this->requestStack->getCurrentRequest();
    $restoreDraft = $request !== NULL && $request->query->getBoolean('restore_draft');

    $formNode = clone $node;
    if ($restoreDraft) {
      $this->applyDraftHeroOverlay($formNode, $melDefaults);
    }
    $this->applySubmittedHeroOverlay($formNode, $form_state);
    if ($this->saveService->isBrokenHeroImageReference($formNode)) {
      $formNode->set('field_event_image', []);
      $this->messenger()->addWarning($this->t('The previous cover image file is no longer available. Upload a new image, or use “Remove cover image” and save to clear the broken reference.'));
    }

    $form['mel']['#attached']['library'][] = 'myeventlane_event_studio/mel_branding_workspace';
    $form['mel']['#attached']['library'][] = 'myeventlane_event_studio/mel_branding_hero_tools';
    $form['mel']['#attributes']['class'][] = 'mel-event-branding-studio';
    $form['mel']['#attributes']['id'] = 'mel-branding-form-content';

    $form['mel']['branding_layout_open'] = [
      '#type' => 'markup',
      '#markup' => Markup::create(
        '<div class="mel-event-branding-studio__layout"><div class="mel-event-branding-studio__main">'
      ),
      '#weight' => -21,
    ];

    /** @var array<string, mixed> $main */
    $main = &$form['mel'];

    $main['branding_hero_shell'] = [
      '#type' => 'markup',
      '#markup' => Markup::create(
        '<section class="mel-es-field-group mel-es-field-group--branding" aria-labelledby="mel-es-branding-title">'
        . '<header class="mel-es-field-group__header">'
        . '<h3 class="mel-es-field-group__title" id="mel-es-branding-title">' . Html::escape((string) $this->t('Branding')) . '</h3>'
        . '<p class="mel-es-field-group__hint">' . Html::escape((string) $this->t('Shape how your event appears across MyEventLane and social sharing.')) . '</p>'
        . '<p class="mel-es-field-group__reassurance">' . Html::escape((string) $this->t('Choose a new or saved landscape image, adjust its 16:9 framing, describe it for screen readers, then save your changes.')) . '</p>'
        . '</header>'
        . '<div class="mel-es-field-group__body">'
        . '<ol class="mel-es-branding-workflow" aria-label="' . Html::escape((string) $this->t('Cover image steps')) . '">'
        . '<li><span>1</span><strong>' . Html::escape((string) $this->t('Choose')) . '</strong><small>' . Html::escape((string) $this->t('Upload new or use a saved image')) . '</small></li>'
        . '<li><span>2</span><strong>' . Html::escape((string) $this->t('Frame')) . '</strong><small>' . Html::escape((string) $this->t('Position the 16:9 crop')) . '</small></li>'
        . '<li><span>3</span><strong>' . Html::escape((string) $this->t('Describe')) . '</strong><small>' . Html::escape((string) $this->t('Add useful alt text')) . '</small></li>'
        . '<li><span>4</span><strong>' . Html::escape((string) $this->t('Save')) . '</strong><small>' . Html::escape((string) $this->t('Update the public preview')) . '</small></li>'
        . '</ol>'
        . '<div class="mel-es-branding-source mel-es-branding-source--upload">'
        . '<p class="mel-es-branding-source__eyebrow">' . Html::escape((string) $this->t('Option 1')) . '</p>'
        . '<h4 class="mel-es-branding-source__title">' . Html::escape((string) $this->t('Upload a new image')) . '</h4>'
        . '<p class="mel-es-branding-source__help">' . Html::escape((string) $this->t('For a sharp event page, use a landscape image at least 1600×900 px. Smaller accepted images may appear soft.')) . '</p>'
        . '<div class="mel-identity-media mel-identity-media--compact">'
        . '<div class="mel-identity-media__fields mel-builder-fields mel-builder-fields--stack">'
      ),
      '#weight' => -10,
    ];

    $form['mel']['#parents'] = ['mel'];

    $form_display = $this->entityTypeManager->getStorage('entity_form_display')->load('node.event.studio_branding');
    if (!$form_display instanceof EntityFormDisplay) {
      $this->logger->error('Missing entity form display node.event.studio_branding while building branding for node @nid.', ['@nid' => (string) $node->id()]);
      $form['mel']['field_event_image'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => Html::escape((string) $this->t('Hero image editor is not available. Import configuration or contact support.')),
        '#attributes' => ['class' => ['messages', 'messages--error']],
        '#weight' => 0,
      ];
    }
    else {
      $widget = $form_display->getRenderer('field_event_image');
      if ($widget === NULL) {
        $this->logger->error('Missing field_event_image widget on studio_branding display for node @nid.', ['@nid' => (string) $node->id()]);
        $form['mel']['field_event_image'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => Html::escape((string) $this->t('Hero image widget is not configured.')),
          '#attributes' => ['class' => ['messages', 'messages--error']],
          '#weight' => 0,
        ];
      }
      else {
        $form['mel']['field_event_image'] = $widget->form($formNode->get('field_event_image'), $form['mel'], $form_state);
        $form['mel']['field_event_image']['#weight'] = 0;
        $focal_override = NULL;
        if ($restoreDraft && is_array($melDefaults['field_event_image'] ?? NULL)) {
          $draft_delta = EventStudioMelPayloadService::imageWidgetDeltaFromRaw($melDefaults['field_event_image']);
          if (isset($draft_delta['focal_point']) && trim((string) $draft_delta['focal_point']) !== '') {
            $focal_override = trim((string) $draft_delta['focal_point']);
          }
        }
        $this->brandingHeroFocalAugmenter->attachAfterBuild($form['mel']['field_event_image'], $formNode, $focal_override);
      }
    }

    if ($form_display instanceof EntityFormDisplay) {
      $this->buildSavedCoverMediaSelector($form, $formNode, $form_state, $form_display);
    }

    $main['branding_hero_upload_notice'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'mel-es-branding-upload-notice',
        'class' => ['mel-es-branding-upload-notice'],
        'hidden' => 'hidden',
        'aria-live' => 'polite',
      ],
      '#weight' => 1,
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['mel-es-branding-upload-notice__title']],
        '#value' => Html::escape((string) $this->t('Cover image')),
      ],
      'checklist' => [
        '#type' => 'html_tag',
        '#tag' => 'ol',
        '#attributes' => ['class' => ['mel-es-branding-upload-notice__steps']],
        '#value' => Markup::create(
          '<li class="mel-es-branding-upload-notice__step" data-upload-step="choose">'
          . Html::escape((string) $this->t('Choose a landscape photo (at least 400×200 px).'))
          . '</li>'
          . '<li class="mel-es-branding-upload-notice__step" data-upload-step="upload">'
          . Html::escape((string) $this->t('Click Upload and wait for the preview to appear.'))
          . '</li>'
          . '<li class="mel-es-branding-upload-notice__step" data-upload-step="save">'
          . Html::escape((string) $this->t('Add alt text, then click Save branding.'))
          . '</li>'
        ),
      ],
      'message' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => [
          'class' => ['mel-es-branding-upload-notice__message'],
          'hidden' => 'hidden',
        ],
        '#value' => '',
      ],
    ];

    $main['branding_hero_crop_notice'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'mel-es-branding-crop-notice',
        'class' => ['mel-es-branding-crop-notice'],
        'hidden' => 'hidden',
        'aria-live' => 'polite',
      ],
      '#weight' => 2,
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['mel-es-branding-crop-notice__title']],
        '#value' => Html::escape((string) $this->t('Framing status')),
      ],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['mel-es-branding-crop-notice__text']],
        '#value' => Html::escape((string) $this->t('Adjust the 16:9 frame before saving. This framing is used on your public event and booking pages.')),
      ],
    ];

    $main['field_event_image_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image alt text'),
      '#description' => $this->t('Describe the important people, setting or action in the image. Required when a cover image is present. Example: “Three performers singing on a warmly lit stage.”'),
      '#default_value' => $this->resolveHeroAltDefault($node, $melDefaults),
      '#weight' => 6,
      '#attributes' => [
        'class' => ['mel-input'],
        // Event-image descriptions are authored content, never personal data.
        'autocomplete' => 'off',
      ],
    ];

    $main['branding_hero_tools'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-branding-hero-tools']],
      '#weight' => 10,
      'framing' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-es-branding-hero-framing']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['mel-es-branding-hero-framing__title']],
          '#value' => Html::escape((string) $this->t('Saved public hero preview')),
        ],
        'frame' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['mel-es-branding-hero-framing__frame', 'js-mel-branding-hero-framing-frame']],
        ],
        'hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['mel-es-branding-hero-framing__hint', 'mel-text--muted']],
          '#value' => Html::escape((string) $this->t('Updates after you click Save branding.')),
        ],
      ],
      'quality' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-es-branding-hero-quality']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['mel-es-branding-hero-quality__title']],
          '#value' => Html::escape((string) $this->t('Cover image tips')),
        ],
        'list' => [
          '#type' => 'html_tag',
          '#tag' => 'ul',
          '#attributes' => ['class' => ['mel-es-branding-hero-quality__list']],
          '#value' => Markup::create(
            '<li>' . Html::escape((string) $this->t('Best with cinematic landscape photos (16:9).')) . '</li>'
            . '<li>' . Html::escape((string) $this->t('Text-heavy posters may crop poorly — keep key detail near the centre.')) . '</li>'
            . '<li>' . Html::escape((string) $this->t('Use at least 1600×900 px; we warn below 1280×720 after save.')) . '</li>'
          ),
        ],
      ],
      'remove' => [
        '#type' => 'button',
        '#value' => $this->t('Remove cover image'),
        '#weight' => 10,
        '#attributes' => [
          'type' => 'button',
          'class' => ['button', 'button--secondary', 'mel-es-branding-remove', 'js-mel-branding-hero-remove'],
          'disabled' => 'disabled',
          'aria-disabled' => 'true',
        ],
      ],
    ];

    $can_customize = $this->eventStyleAccess->canCustomizeEventPage($this->currentUser);
    $main['branding_pro_colour_status'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'mel-es-branding-pro-status',
          $can_customize ? 'is-active' : 'is-locked',
        ],
        'role' => 'note',
      ],
      '#weight' => 10,
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['mel-es-branding-pro-status__title']],
        '#value' => Html::escape((string) ($can_customize
          ? $this->t('MEL Pro active')
          : $this->t('MEL Pro event colours'))),
      ],
      'message' => [
        '#type' => 'markup',
        '#markup' => Markup::create(
          '<p class="mel-es-branding-pro-status__message">'
          . Html::escape((string) ($can_customize
            ? $this->t('You can change this event’s page style and approved colour palette below.')
            : $this->t('The Coral event page is included. Upgrade to Pro to unlock Immersive styling and additional approved colour palettes.')))
          . ' <a href="#mel-es-page-style-title">'
          . Html::escape((string) ($can_customize
            ? $this->t('Choose page colours')
            : $this->t('See Pro options')))
          . '</a></p>'
        ),
      ],
    ];

    $main['branding_image_save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save changes'),
      '#name' => 'branding_image_save',
      '#submit' => ['::submitContinue'],
      '#weight' => 11,
      '#attributes' => [
        'class' => ['button', 'button--primary', 'js-mel-branding-save'],
        'data-mel-branding-save' => '1',
      ],
      '#suffix' => '<p class="mel-es-branding-save-help">' . Html::escape((string) $this->t('Saves your cover, gallery and page colours, then refreshes the public preview.')) . '</p>',
    ];

    $form['mel']['branding_hero_close'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('</div></div></div></div></section>'),
      '#weight' => 12,
    ];

    if ($form_display instanceof EntityFormDisplay) {
      $this->buildBrandingGalleryField($form, $formNode, $form_state, $form_display);
    }

    $this->buildPageStyleFields($form, $node, $melDefaults);
  }

  /**
   * Adds an image-only Media Library chooser for the event cover.
   *
   * The selected media file is applied to the existing image/crop widget so
   * public rendering continues to use field_event_image as its source of truth.
   *
   * @param array<string, mixed> $form
   */
  private function buildSavedCoverMediaSelector(
    array &$form,
    NodeInterface $formNode,
    FormStateInterface $form_state,
    EntityFormDisplay $form_display,
  ): void {
    if (!$formNode->hasField('field_mel_event_cover_media')) {
      return;
    }
    $widget = $form_display->getRenderer('field_mel_event_cover_media');
    if ($widget === NULL) {
      return;
    }

    $form['mel']['saved_cover_media'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-saved-cover-media']],
      '#weight' => 3,
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h4',
        '#attributes' => ['class' => ['mel-es-saved-cover-media__title']],
        '#value' => Html::escape((string) $this->t('Choose from saved images')),
      ],
      'help' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['mel-es-saved-cover-media__help']],
        '#value' => Html::escape((string) $this->t('Reuse an image you have already saved. Select it, then choose “Use and frame image” to prepare it for this event.')),
      ],
    ];

    $selector = $widget->form(
      $formNode->get('field_mel_event_cover_media'),
      $form['mel'],
      $form_state,
    );
    $this->renameMediaLibraryOpenButton($selector);
    $selector['#weight'] = 1;
    $form['mel']['saved_cover_media']['selector'] = $selector;

    $form['mel']['saved_cover_media']['apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('Use and frame image'),
      '#name' => 'apply_saved_cover_image',
      // String callbacks remain serializable in Drupal's AJAX form cache.
      '#validate' => [],
      '#submit' => ['::applySavedCoverImage'],
      '#limit_validation_errors' => [
        ['nid'],
        ['mel', 'field_mel_event_cover_media'],
      ],
      '#ajax' => [
        'callback' => '::refreshBrandingAfterSavedCover',
        'wrapper' => 'mel-branding-form-content',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Loading image into the framing editor…'),
        ],
      ],
      '#attributes' => ['class' => ['button', 'button--secondary']],
      '#weight' => 2,
    ];
  }

  /**
   * Uses the selected Media Library image in the existing crop widget.
   *
   * @param array<string, mixed> $form
   */
  public function applySavedCoverImage(array &$form, FormStateInterface $form_state): void {
    $this->ensureInjectedServices();
    $nid = (int) ($form_state->getValue('nid') ?? 0);
    $node = $nid > 0 ? $this->entityTypeManager->getStorage('node')->load($nid) : NULL;
    if (!$node instanceof NodeInterface || !$node->hasField('field_mel_event_cover_media')) {
      $this->messenger()->addWarning($this->t('Choose a saved image first.'));
      $form_state->setRebuild();
      return;
    }
    $this->assertVendorEvent($node);

    $selection_path = ['mel', 'field_mel_event_cover_media'];
    $selection_input = NestedArray::getValue($form_state->getUserInput(), $selection_path);
    $media_id = is_array($selection_input)
      ? (int) ($selection_input['selection'][0]['target_id'] ?? 0)
      : 0;
    $media = $media_id > 0
      ? $this->entityTypeManager->getStorage('media')->load($media_id)
      : NULL;
    if (!$media instanceof MediaInterface) {
      $this->messenger()->addWarning($this->t('Choose a saved image first.'));
      $form_state->setRebuild();
      return;
    }

    $result = $this->saveService->applyBrandingCoverMedia($node, $media);
    if ($result['errors'] !== []) {
      foreach ($result['errors'] as $error) {
        $this->messenger()->addError($this->t($error));
      }
      $form_state->setRebuild();
      return;
    }

    $this->messenger()->addStatus($this->t('Saved image loaded. Adjust the 16:9 framing, then choose Save image.'));
    $form_state->setRedirect('myeventlane_event_studio.workspace_branding', ['node' => $node->id()]);
  }

  /**
   * AJAX callback after applying a saved Media Library image.
   *
   * @param array<string, mixed> $form
   *
   * @return array<string, mixed>|\Drupal\Core\Ajax\AjaxResponse
   */
  public function refreshBrandingAfterSavedCover(array &$form, FormStateInterface $form_state): array|AjaxResponse {
    $redirect = $form_state->getRedirect();
    if ($redirect instanceof Url) {
      $response = new AjaxResponse();
      $response->addCommand(new RedirectCommand($redirect->toString()));
      return $response;
    }
    return is_array($form['mel'] ?? NULL) ? $form['mel'] : $form;
  }

  /**
   * Gives the standard Media Library opener task-specific wording.
   *
   * @param array<string, mixed> $element
   */
  private function renameMediaLibraryOpenButton(array &$element): void {
    foreach ($element as $key => &$child) {
      if (!is_array($child)) {
        continue;
      }
      if ($key === 'open_button') {
        $child['#value'] = $this->t('Choose from saved images');
      }
      elseif ($key === 'update_button') {
        $child['#value'] = $this->t('Replace saved image');
      }
      elseif ($key === 'remove_button') {
        $child['#value'] = $this->t('Clear saved choice');
      }
      $this->renameMediaLibraryOpenButton($child);
    }
  }

  /**
   * Gallery media field — separate from hero cover image.
   *
   * @param array<string, mixed> $form
   */
  private function buildBrandingGalleryField(
    array &$form,
    NodeInterface $formNode,
    FormStateInterface $form_state,
    EntityFormDisplay $form_display,
  ): void {
    if (!$formNode->hasField('field_mel_event_gallery')) {
      return;
    }

    $widget = $form_display->getRenderer('field_mel_event_gallery');
    if ($widget === NULL) {
      return;
    }

    $form['mel']['branding_gallery_shell'] = [
      '#type' => 'markup',
      '#markup' => Markup::create(
        '<section class="mel-es-field-group mel-es-field-group--gallery" aria-labelledby="mel-es-gallery-title">'
        . '<header class="mel-es-field-group__header">'
        . '<h3 class="mel-es-field-group__title" id="mel-es-gallery-title">' . Html::escape((string) $this->t('Event gallery')) . '</h3>'
        . '<p class="mel-es-field-group__hint">' . Html::escape((string) $this->t('Optional storytelling photos for your public event page — separate from your cover image.')) . '</p>'
        . '<p class="mel-es-field-group__reassurance">' . Html::escape((string) $this->t('Drag to reorder. Landscape photos work best. Save branding when you are done.')) . '</p>'
        . '</header>'
        . '<div class="mel-es-field-group__body mel-es-field-group__body--gallery">'
      ),
      '#weight' => 13,
    ];

    $form['mel']['field_mel_event_gallery'] = $widget->form(
      $formNode->get('field_mel_event_gallery'),
      $form['mel'],
      $form_state,
    );
    $form['mel']['field_mel_event_gallery']['#weight'] = 14;

    $form['mel']['branding_gallery_close'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('</div></section>'),
      '#weight' => 15,
    ];
  }

  /**
   * @param array<string, mixed> $form
   * @param array<string, mixed> $melDefaults
   */
  private function buildPageStyleFields(array &$form, NodeInterface $node, array $melDefaults): void {
    /** @var array<string, mixed> $main */
    $main = &$form['mel'];
    $can_customize = $this->eventStyleAccess->canCustomizeEventPage($this->currentUser);
    $colour_labels = $this->eventPageStyleResolver->colourOptions();

    if ($can_customize) {
      $style_default = $melDefaults['field_mel_page_style'] ?? EventPageStyleResolver::STYLE_CLASSIC;
      if ($node->hasField('field_mel_page_style') && !$node->get('field_mel_page_style')->isEmpty()) {
        $style_default = (string) $node->get('field_mel_page_style')->value;
      }
      $colour_default = $melDefaults['field_mel_theme_colour'] ?? EventPageStyleResolver::COLOUR_CORAL;
      if ($node->hasField('field_mel_theme_colour') && !$node->get('field_mel_theme_colour')->isEmpty()) {
        $colour_default = (string) $node->get('field_mel_theme_colour')->value;
      }
    }
    else {
      $style_default = EventPageStyleResolver::STYLE_CLASSIC;
      $colour_default = EventPageStyleResolver::COLOUR_CORAL;
    }
    $style_default = $this->eventPageStyleResolver->sanitizeStyle((string) $style_default);
    $colour_default = $this->eventPageStyleResolver->sanitizeColour((string) $colour_default);

    $main['page_style_shell'] = [
      '#type' => 'markup',
      '#markup' => Markup::create(
        '<section class="mel-es-field-group mel-es-field-group--page-style" aria-labelledby="mel-es-page-style-title">'
        . '<header class="mel-es-field-group__header">'
        . '<h3 class="mel-es-field-group__title" id="mel-es-page-style-title">' . Html::escape((string) $this->t('Choose your event page style')) . '</h3>'
        . '<p class="mel-es-field-group__hint">' . Html::escape((string) $this->t('Every event uses the Classic MyEventLane page by default. Pro organisers can unlock Immersive styling and approved colour palettes.')) . '</p>'
        . '</header>'
        . '<div class="mel-es-field-group__body mel-es-field-group__body--page-style">'
      ),
      '#weight' => 15,
    ];

    $style_options = [
      EventPageStyleResolver::STYLE_CLASSIC => $this->t('Classic MyEventLane'),
      EventPageStyleResolver::STYLE_IMMERSIVE => $this->t('Immersive'),
    ];

    $main['field_mel_page_style'] = [
      '#type' => 'radios',
      '#title' => $this->t('Page style'),
      '#weight' => 20,
      '#mel_option_cards' => TRUE,
      '#mel_option_descriptions' => [
        EventPageStyleResolver::STYLE_CLASSIC => $this->t('Warm, clear and conversion-focused. Included with every event.'),
        EventPageStyleResolver::STYLE_IMMERSIVE => $this->t('Premium cinematic layout with rich colour palettes. Built for Pro organisers.'),
      ],
      '#mel_option_badges' => [
        EventPageStyleResolver::STYLE_CLASSIC => $this->t('Included'),
        EventPageStyleResolver::STYLE_IMMERSIVE => $this->t('Pro Experience'),
      ],
      '#options' => $style_options,
      '#default_value' => $style_default,
      '#attributes' => ['class' => ['mel-page-style-radios']],
    ];

    foreach (array_keys($style_options) as $style_key) {
      $main['field_mel_page_style'][$style_key]['#wrapper_attributes'] = [
        'class' => [
          'mel-page-style-card',
          'mel-page-style-card--' . $style_key,
        ],
      ];
    }

    if (!$can_customize) {
      $main['field_mel_page_style'][EventPageStyleResolver::STYLE_IMMERSIVE]['#disabled'] = TRUE;
      $main['field_mel_page_style'][EventPageStyleResolver::STYLE_IMMERSIVE]['#attributes']['class'][] = 'mel-option-card--locked';
    }

    if (!$can_customize) {
      $main['page_style_upgrade'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => Html::escape((string) $this->t('Upgrade to Pro to customise your event page.')),
        '#attributes' => [
          'class' => ['mel-page-style-upgrade', 'mel-es-field-group__reassurance'],
          'role' => 'note',
        ],
        '#weight' => 21,
      ];
    }

    $colour_options = [];
    foreach ($colour_labels as $key => $label) {
      $colour_options[$key] = $this->t($label);
    }

    $main['field_mel_theme_colour'] = [
      '#type' => 'radios',
      '#title' => $this->t('Colour mood'),
      '#title_display' => 'invisible',
      '#weight' => 22,
      '#mel_option_cards' => TRUE,
      '#options' => $colour_options,
      '#default_value' => $colour_default,
      '#attributes' => ['class' => ['mel-page-style-colours']],
      '#prefix' => '<div class="mel-page-style-colour-block" aria-labelledby="mel-es-theme-colour-title">'
        . '<h4 class="mel-es-field-group__title mel-page-style-colour-block__title" id="mel-es-theme-colour-title">'
        . Html::escape((string) $this->t('Choose an approved colour palette'))
        . '</h4>'
        . '<p class="mel-es-field-group__hint mel-page-style-colour-block__hint">'
        . Html::escape((string) $this->t('Curated palettes keep your event page accessible and on-brand.'))
        . '</p>',
      '#suffix' => '</div>',
    ];

    foreach (array_keys($colour_options) as $colour_key) {
      if ($colour_key !== EventPageStyleResolver::COLOUR_CORAL && !$can_customize) {
        $main['field_mel_theme_colour'][$colour_key]['#disabled'] = TRUE;
        $main['field_mel_theme_colour'][$colour_key]['#attributes']['class'][] = 'mel-option-card--locked';
      }
      $main['field_mel_theme_colour'][$colour_key]['#wrapper_attributes']['class'][] = 'mel-page-style-colour-card';
      $main['field_mel_theme_colour'][$colour_key]['#wrapper_attributes']['class'][] = 'mel-page-style-colour-card--' . $colour_key;
    }

    $main['page_style_close'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('</div></section>'),
      '#weight' => 23,
    ];

    $main['branding_aside_open'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('</div><div class="mel-event-branding-studio__aside">'),
      '#weight' => 24,
    ];

    $main['branding_preview'] = $this->eventBrandingPreviewBuilder->build($node);
    $main['branding_preview']['#weight'] = 25;

    $main['branding_layout_close'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('</div></div>'),
      '#weight' => 26,
    ];
  }

  /**
   * Default alt text for the dedicated MEL field (entity, baseline, or draft mel).
   *
   * @param array<string, mixed> $melDefaults
   */
  private function resolveHeroAltDefault(NodeInterface $node, array $melDefaults): string {
    if (array_key_exists('field_event_image_alt', $melDefaults)) {
      return trim((string) ($melDefaults['field_event_image_alt'] ?? ''));
    }
    $hero = EventStudioMelPayloadService::normalizeHeroFromMelFragment($melDefaults);
    if ($hero['alt'] !== '') {
      return $hero['alt'];
    }
    if ($node->hasField('field_event_image') && !$node->get('field_event_image')->isEmpty()) {
      $item = $node->get('field_event_image')->first();
      if ($item !== NULL) {
        return trim((string) ($item->alt ?? ''));
      }
    }
    return '';
  }

}
