<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\myeventlane_event_studio\Service\EventStudioMelPayloadService;
use Drupal\node\NodeInterface;

/**
 * Isolated Event Studio form for event branding.
 */
final class EventBrandingForm extends EventStudioBaseForm {

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
   * Applies restored-draft hero defaults onto a clone for widget rendering only.
   */
  private function applyDraftHeroOverlay(NodeInterface $target, array $melDefaults): void {
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

    $formNode = $node;
    if ($restoreDraft) {
      $formNode = clone $node;
      $this->applyDraftHeroOverlay($formNode, $melDefaults);
    }

    $form['mel']['branding_hero_shell'] = [
      '#type' => 'markup',
      '#markup' => Markup::create(
        '<section class="mel-es-field-group mel-es-field-group--branding" aria-labelledby="mel-es-branding-title">'
        . '<header class="mel-es-field-group__header">'
        . '<h3 class="mel-es-field-group__title" id="mel-es-branding-title">' . Html::escape((string) $this->t('Branding')) . '</h3>'
        . '<p class="mel-es-field-group__hint">' . Html::escape((string) $this->t('Shape how your event appears across MyEventLane and social sharing.')) . '</p>'
        . '<p class="mel-es-field-group__reassurance">' . Html::escape((string) $this->t('Use the crop frame for a 1200×630 hero. Alt text in the image field is required for accessibility.')) . '</p>'
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
        $widget->form($formNode->get('field_event_image'), $form['mel'], $form_state);
        if (isset($form['mel']['field_event_image'])) {
          $form['mel']['field_event_image']['#weight'] = 0;
        }
      }
    }

    $form['mel']['branding_hero_close'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('</div></div></div></section>'),
      '#weight' => 5,
    ];

    $form['unavailable'] = [
      '#type' => 'container',
      '#weight' => 20,
      '#attributes' => ['class' => ['mel-event-studio-section__placeholder']],
      'copy' => [
        '#markup' => '<p>' . $this->t('More brand controls will appear here only when they are ready for creators. For now, the hero image and crop are enough for most events.') . '</p>',
      ],
    ];
  }

}
