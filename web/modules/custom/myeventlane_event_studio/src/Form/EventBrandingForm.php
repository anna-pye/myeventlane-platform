<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\myeventlane_event_studio\Service\EntityAutocompleteMelNormalizer;
use Drupal\myeventlane_event_studio\Service\EventStudioAiAssistBuilder;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\myeventlane_event_studio\Service\EventStudioMelPayloadService;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\myeventlane_event_studio\Service\EventStudioWizardMelBaseline;
use Drupal\myeventlane_location\Service\LocationProviderManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Isolated Event Studio form for event branding.
 */
final class EventBrandingForm extends EventStudioBaseForm {

  public function __construct(
    EntityFieldManagerInterface $entityFieldManager,
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    EventStudioSaveService $saveService,
    EventStudioMelPayloadService $melPayloadService,
    EventStudioWizardMelBaseline $wizardMelBaseline,
    EntityAutocompleteMelNormalizer $entityAutocompleteMelNormalizer,
    EventVendorAccessChecker $eventVendorAccessChecker,
    EventStudioAutosaveService $autosaveService,
    EventStudioAiAssistBuilder $aiAssistBuilder,
    RequestStack $request_stack,
    LoggerInterface $logger,
    ?LocationProviderManager $eventStudioLocationProvider,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {
    parent::__construct(
      $entityFieldManager,
      $entityTypeManager,
      $currentUser,
      $saveService,
      $melPayloadService,
      $wizardMelBaseline,
      $entityAutocompleteMelNormalizer,
      $eventVendorAccessChecker,
      $autosaveService,
      $aiAssistBuilder,
      $request_stack,
      $logger,
      $eventStudioLocationProvider,
    );
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('myeventlane_event_studio.save'),
      $container->get('myeventlane_event_studio.mel_payload'),
      $container->get('myeventlane_event_studio.wizard_mel_baseline'),
      $container->get('myeventlane_event_studio.entity_autocomplete_mel_normalizer'),
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->get('myeventlane_event_studio.autosave'),
      $container->get('myeventlane_event_studio.ai_assist_builder'),
      $container->get('request_stack'),
      $container->get('logger.factory')->get('myeventlane_event_studio'),
      $container->has('myeventlane_location.provider_manager')
        ? $container->get('myeventlane_location.provider_manager')
        : NULL,
      $container->get('file_url_generator'),
    );
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
    return $this->t('Save branding');
  }

  protected function onWizardStepSaveSuccess(NodeInterface $saved, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($this->t('Branding saved.'));
    $form_state->setRedirect('myeventlane_event_studio.workspace_branding', ['node' => $saved->id()]);
  }

  /**
   * Resolves a usable image URL for the hero preview from mel baseline fids.
   */
  private function getHeroPreviewUrlFromMelDefaults(array $melDefaults): ?string {
    $raw = $melDefaults['field_event_image'] ?? [];
    if (!is_array($raw)) {
      return NULL;
    }
    $fid = 0;
    foreach ($raw as $item) {
      $fid = (int) $item;
      if ($fid > 0) {
        break;
      }
    }
    if ($fid < 1) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    $uri = $file->getFileUri();
    if ($uri === '') {
      return NULL;
    }
    return $this->fileUrlGenerator->generateString($uri);
  }

  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    // Never put hero preview markup in #prefix on managed_file: core's
    // ManagedFile::processManagedFile() replaces #prefix/#suffix with the AJAX
    // wrapper (see web/core/modules/file/src/Element/ManagedFile.php).
    $placeholder_src = Html::escape(myeventlane_event_studio_hero_placeholder_url());

    $heroPreviewUrl = $this->getHeroPreviewUrlFromMelDefaults($melDefaults);
    $hero_alt = Html::escape(trim((string) ($melDefaults['field_event_image_alt'] ?? '')));

    if ($heroPreviewUrl !== NULL && $heroPreviewUrl !== '') {
      $preview_src = Html::escape($heroPreviewUrl);
      $preview_img_markup = '<img class="mel-cover-preview__img" id="mel-cover-preview-img" src="' . $preview_src . '" alt="' . $hero_alt . '" width="1200" height="630" loading="lazy" />';
      $empty_wrapper_attrs = ' id="mel-cover-preview-empty" class="mel-cover-preview__empty" hidden';
    }
    else {
      $preview_img_markup = '<img class="mel-cover-preview__img" id="mel-cover-preview-img" src="" alt="" width="1200" height="630" loading="lazy" hidden />';
      $empty_wrapper_attrs = ' id="mel-cover-preview-empty" class="mel-cover-preview__empty"';
    }

    $form['mel']['branding_hero_shell'] = [
      '#type' => 'markup',
      '#markup' => Markup::create(
        '<section class="mel-es-field-group mel-es-field-group--branding" aria-labelledby="mel-es-branding-title">'
        . '<header class="mel-es-field-group__header">'
        . '<h3 class="mel-es-field-group__title" id="mel-es-branding-title">' . Html::escape((string) $this->t('Branding')) . '</h3>'
        . '<p class="mel-es-field-group__hint">' . Html::escape((string) $this->t('Shape how your event appears across MyEventLane and social sharing.')) . '</p>'
        . '<p class="mel-es-field-group__reassurance">' . Html::escape((string) $this->t('You can change visuals later. Accessibility text helps everyone understand the image.')) . '</p>'
        . '</header>'
        . '<div class="mel-es-field-group__body">'
        . '<div class="mel-identity-media mel-identity-media--compact">'
        . '<div class="mel-cover-preview" id="mel-cover-preview" data-mel-cover-preview>'
        . $preview_img_markup
        . '<div' . $empty_wrapper_attrs . '>'
        . '<img class="mel-cover-preview__placeholder" src="' . $placeholder_src . '" width="800" height="450" loading="lazy" decoding="async" alt="" />'
        . '<span class="mel-cover-preview__empty-icon" aria-hidden="true"></span>'
        . '<span class="mel-cover-preview__empty-text">' . Html::escape((string) $this->t('No cover image yet — upload a hero image so attendees recognise your event.')) . '</span>'
        . '</div></div>'
        . '<div class="mel-identity-media__fields mel-builder-fields mel-builder-fields--stack">'
      ),
      '#weight' => -10,
    ];

    $form['mel']['field_event_image'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Hero image'),
      '#upload_location' => 'public://events',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png gif jpg jpeg webp'],
        'FileSizeLimit' => ['fileLimit' => 5 * 1024 * 1024],
      ],
      '#default_value' => $melDefaults['field_event_image'] ?? [],
      '#attributes' => ['class' => ['mel-input-file']],
      '#description' => $this->t('Optional, but recommended. A clear image helps attendees recognise your event.'),
      '#weight' => 0,
    ];

    $form['mel']['field_event_image_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image alt text'),
      '#default_value' => $melDefaults['field_event_image_alt'] ?? '',
      '#description' => $this->t('Briefly describe the image for screen readers.'),
      '#attributes' => ['class' => ['mel-input']],
      '#suffix' => '</div></div></div></section>',
      '#weight' => 1,
    ];

    $form['unavailable'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-studio-section__placeholder']],
      'copy' => [
        '#markup' => '<p>' . $this->t('More brand controls will appear here only when they are ready for creators. For now, image and alt text are enough for most events.') . '</p>',
      ],
    ];
  }

}
