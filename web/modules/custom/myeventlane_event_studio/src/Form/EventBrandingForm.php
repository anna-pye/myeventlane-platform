<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\myeventlane_event_studio\Service\EventPageStyleResolver;
use Drupal\myeventlane_event_studio\Service\EventStudioMelPayloadService;
use Drupal\myeventlane_event_studio\Service\EventStyleAccessManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Isolated Event Studio form for event branding.
 */
final class EventBrandingForm extends EventStudioBaseForm {

  private EventStyleAccessManager $eventStyleAccess;

  private EventPageStyleResolver $eventPageStyleResolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->eventStyleAccess = $container->get('myeventlane_event_studio.event_style_access');
    $instance->eventPageStyleResolver = $container->get('myeventlane_event_studio.event_page_style_resolver');
    return $instance;
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
    parent::validateForm($form, $form_state);

    $nid = (int) ($form_state->getValue('nid') ?? 0);
    if ($nid < 1) {
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

    if ($sanitized_style === EventPageStyleResolver::STYLE_IMMERSIVE
      && !$this->eventStyleAccess->canUseImmersiveStyle($loaded, $this->currentUser)) {
      $form_state->setErrorByName(
        'mel][field_mel_page_style',
        $this->t('MEL Immersive requires MyEventLane Pro. Choose MEL Classic or upgrade to unlock Immersive styling.')
      );
    }
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

  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $request = $this->requestStack->getCurrentRequest();
    $restoreDraft = $request !== NULL && $request->query->getBoolean('restore_draft');

    $formNode = clone $node;
    if ($restoreDraft) {
      $this->applyDraftHeroOverlay($formNode, $melDefaults);
    }
    if ($this->saveService->isBrokenHeroImageReference($formNode)) {
      $formNode->set('field_event_image', []);
      $this->messenger()->addWarning($this->t('The previous cover image file is no longer available. Upload a new image, or use “Remove cover image” and save to clear the broken reference.'));
    }

    $form['mel']['#attached']['library'][] = 'myeventlane_event_studio/mel_branding_hero_tools';

    $form['mel']['branding_hero_shell'] = [
      '#type' => 'markup',
      '#markup' => Markup::create(
        '<section class="mel-es-field-group mel-es-field-group--branding" aria-labelledby="mel-es-branding-title">'
        . '<header class="mel-es-field-group__header">'
        . '<h3 class="mel-es-field-group__title" id="mel-es-branding-title">' . Html::escape((string) $this->t('Branding')) . '</h3>'
        . '<p class="mel-es-field-group__hint">' . Html::escape((string) $this->t('Shape how your event appears across MyEventLane and social sharing.')) . '</p>'
        . '<p class="mel-es-field-group__reassurance">' . Html::escape((string) $this->t('Click the image preview to set the focal point, or use the shortcuts below. Alt text in the image field is required when a cover image is present.')) . '</p>'
        . '</header>'
        . '<div class="mel-es-field-group__body">'
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
      }
    }

    $form['mel']['branding_hero_tools'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-branding-hero-tools']],
      '#weight' => 2,
      'size_hint' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['mel-es-branding-hero-size-hint']],
        '#value' => Html::escape((string) $this->t('For the clearest hero on event and book pages, use at least 1600×900 pixels (16:9). We warn after save if the file is under 1280×720; very small images may look soft when scaled up. Minimum upload size enforced by the field is 400×200.')),
      ],
      'presets' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-es-branding-hero-focal-presets']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['mel-es-branding-hero-focal-presets__label']],
          '#value' => Html::escape((string) $this->t('Focal shortcuts')),
        ],
        'buttons' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-es-branding-hero-focal-presets__buttons']],
          'c' => $this->buildFocalPresetButton($this->t('Centre'), '50,50'),
          't' => $this->buildFocalPresetButton($this->t('Top'), '50,18'),
          'b' => $this->buildFocalPresetButton($this->t('Bottom'), '50,82'),
          'l' => $this->buildFocalPresetButton($this->t('Left'), '18,50'),
          'r' => $this->buildFocalPresetButton($this->t('Right'), '82,50'),
        ],
      ],
      'remove' => [
        '#type' => 'button',
        '#value' => $this->t('Remove cover image'),
        '#weight' => 10,
        '#attributes' => [
          'type' => 'button',
          'class' => ['button', 'button--danger', 'js-mel-branding-hero-remove'],
          'disabled' => 'disabled',
          'aria-disabled' => 'true',
        ],
      ],
    ];

    $form['mel']['branding_hero_close'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('</div></div></div></section>'),
      '#weight' => 5,
    ];

    $this->buildPageStyleFields($form, $node, $melDefaults);
  }

  /**
   * @param array<string, mixed> $form
   * @param array<string, mixed> $melDefaults
   */
  private function buildPageStyleFields(array &$form, NodeInterface $node, array $melDefaults): void {
    $can_immersive = $this->eventStyleAccess->canUseImmersiveStyle($node, $this->currentUser);

    $style_default = $melDefaults['field_mel_page_style'] ?? EventPageStyleResolver::STYLE_CLASSIC;
    if ($node->hasField('field_mel_page_style') && !$node->get('field_mel_page_style')->isEmpty()) {
      $style_default = (string) $node->get('field_mel_page_style')->value;
    }
    $style_default = $this->eventPageStyleResolver->sanitizeStyle((string) $style_default);

    $colour_default = $melDefaults['field_mel_theme_colour'] ?? EventPageStyleResolver::COLOUR_CORAL;
    if ($node->hasField('field_mel_theme_colour') && !$node->get('field_mel_theme_colour')->isEmpty()) {
      $colour_default = (string) $node->get('field_mel_theme_colour')->value;
    }
    $colour_default = $this->eventPageStyleResolver->sanitizeColour((string) $colour_default);

    $form['mel']['page_style_shell'] = [
      '#type' => 'markup',
      '#markup' => Markup::create(
        '<section class="mel-es-field-group mel-es-field-group--page-style" aria-labelledby="mel-es-page-style-title">'
        . '<header class="mel-es-field-group__header">'
        . '<h3 class="mel-es-field-group__title" id="mel-es-page-style-title">' . Html::escape((string) $this->t('Event page style')) . '</h3>'
        . '<p class="mel-es-field-group__hint">' . Html::escape((string) $this->t('What feeling should this event page have?')) . '</p>'
        . '</header>'
        . '<div class="mel-es-field-group__body mel-es-field-group__body--page-style">'
      ),
      '#weight' => 15,
    ];

    $classic_desc = $this->t('Included — friendly, bright, community-first. Best for workshops, markets, and community events.');
    $immersive_desc = $can_immersive
      ? $this->t('Pro — bold, image-led, high-impact. Best for music, nightlife, launches, and premium events.')
      : $this->t('Pro — bold, image-led, high-impact. Best for music, nightlife, launches, and premium events. Unlock Immersive styling with MyEventLane Pro.');

    $form['mel']['field_mel_page_style'] = [
      '#type' => 'radios',
      '#title' => $this->t('Page style'),
      '#mel_option_cards' => TRUE,
      '#mel_option_cards_tickets_layout' => TRUE,
      '#mel_option_descriptions' => [
        EventPageStyleResolver::STYLE_CLASSIC => $classic_desc,
        EventPageStyleResolver::STYLE_IMMERSIVE => $immersive_desc,
      ],
      '#options' => [
        EventPageStyleResolver::STYLE_CLASSIC => $this->t('MEL Classic'),
        EventPageStyleResolver::STYLE_IMMERSIVE => $this->t('MEL Immersive'),
      ],
      '#default_value' => $style_default,
      '#attributes' => ['class' => ['mel-page-style-radios']],
    ];

    if (!$can_immersive) {
      $form['mel']['field_mel_page_style'][EventPageStyleResolver::STYLE_IMMERSIVE]['#disabled'] = TRUE;
      $form['mel']['field_mel_page_style'][EventPageStyleResolver::STYLE_IMMERSIVE]['#attributes']['class'][] = 'mel-option-card--locked';
    }

    if (!$can_immersive) {
      $form['mel']['immersive_upgrade'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => Html::escape((string) $this->t('Unlock Immersive styling with MyEventLane Pro.')),
        '#attributes' => [
          'class' => ['mel-page-style-upgrade', 'mel-es-field-group__reassurance'],
          'role' => 'note',
        ],
        '#weight' => 16,
      ];
    }

    $colour_options = [
      EventPageStyleResolver::COLOUR_CORAL => $this->t('Coral'),
      EventPageStyleResolver::COLOUR_PURPLE => $this->t('Purple'),
      EventPageStyleResolver::COLOUR_MINT => $this->t('Mint'),
      EventPageStyleResolver::COLOUR_GOLD => $this->t('Gold'),
      EventPageStyleResolver::COLOUR_BLUE => $this->t('Deep Blue'),
    ];

    $form['mel']['field_mel_theme_colour'] = [
      '#type' => 'radios',
      '#title' => $this->t('Theme colour'),
      '#title_display' => 'invisible',
      '#mel_option_cards' => TRUE,
      '#options' => $colour_options,
      '#default_value' => $colour_default,
      '#attributes' => ['class' => ['mel-page-style-colours']],
      '#prefix' => '<div class="mel-page-style-colour-block" aria-labelledby="mel-es-theme-colour-title">'
        . '<h4 class="mel-es-field-group__title mel-page-style-colour-block__title" id="mel-es-theme-colour-title">'
        . Html::escape((string) $this->t('Theme colour'))
        . '</h4>',
      '#suffix' => '</div>',
    ];

    foreach (array_keys($colour_options) as $colour_key) {
      $form['mel']['field_mel_theme_colour'][$colour_key]['#wrapper_attributes']['class'][] = 'mel-page-style-colour-card';
      $form['mel']['field_mel_theme_colour'][$colour_key]['#wrapper_attributes']['class'][] = 'mel-page-style-colour-card--' . $colour_key;
    }

    $form['mel']['page_style_close'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('</div></div></section>'),
      '#weight' => 17,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildFocalPresetButton($title, string $preset): array {
    return [
      '#type' => 'button',
      '#value' => $title,
      '#attributes' => [
        'type' => 'button',
        'class' => ['button', 'button--small', 'button--secondary', 'mel-es-branding-focal-preset'],
        'data-focal-preset' => $preset,
      ],
    ];
  }

}
