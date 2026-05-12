<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\myeventlane_event_studio\Service\EntityAutocompleteMelNormalizer;
use Drupal\myeventlane_event_studio\Service\EventStudioAiAssistBuilder;
use Drupal\myeventlane_event_studio\Service\EventStudioMelPayloadService;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\myeventlane_event_studio\Service\EventStudioWizardMelBaseline;
use Drupal\myeventlane_location\Service\LocationProviderManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Shared route-based Event Studio wizard (save + redirect per step).
 */
abstract class EventStudioBaseForm extends FormBase {

  public function __construct(
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected EventStudioSaveService $saveService,
    protected EventStudioMelPayloadService $melPayloadService,
    protected EventStudioWizardMelBaseline $wizardMelBaseline,
    protected EntityAutocompleteMelNormalizer $entityAutocompleteMelNormalizer,
    protected EventVendorAccessChecker $eventVendorAccessChecker,
    protected EventStudioAutosaveService $autosaveService,
    protected EventStudioAiAssistBuilder $aiAssistBuilder,
    RequestStack $request_stack,
    protected LoggerInterface $logger,
    protected ?LocationProviderManager $eventStudioLocationProvider = NULL,
  ) {
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
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
    );
  }

  /**
   * Route name for the next step (Continue).
   */
  abstract protected function getNextRouteName(): string;

  /**
   * Route name for the previous step, or NULL on first step.
   */
  abstract protected function getPreviousRouteName(): ?string;

  /**
   * Build step-specific fields under $form['mel'].
   *
   * @param array<string, mixed> $melDefaults
   *   Baseline mel from {@see EventStudioWizardMelBaseline::getBaselineMel()}.
   */
  abstract protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void;

  protected function getRouteNode(?NodeInterface $parameter_node = NULL): NodeInterface {
    if ($parameter_node instanceof NodeInterface) {
      return $parameter_node;
    }
    $node = $this->getRouteMatch()->getParameter('node');
    return $node instanceof NodeInterface ? $node : throw new NotFoundHttpException();
  }

  protected function assertVendorEvent(NodeInterface $node): void {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    if (!$this->currentUser->hasPermission('administer nodes')
      && !$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($node, $this->currentUser)) {
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * Deep-merge wizard mel: submitted keys override baseline (associative only).
   *
   * @param array<string, mixed> $base
   * @param array<string, mixed> $override
   *
   * @return array<string, mixed>
   */
  protected function mergeMel(array $base, array $override): array {
    foreach ($override as $k => $v) {
      if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && $this->isAssociativeArray($v) && $this->isAssociativeArray($base[$k])) {
        $base[$k] = $this->mergeMel($base[$k], $v);
      }
      else {
        $base[$k] = $v;
      }
    }
    return $base;
  }

  /**
   * @param array<mixed> $a
   */
  private function isAssociativeArray(array $a): bool {
    return array_keys($a) !== range(0, count($a) - 1);
  }

  /**
   * Label for the primary Continue submit (set after $form['actions'] is built).
   */
  protected function getContinueButtonLabel() {
    return $this->t('Continue');
  }

  /**
   * Whether saves on Continue are draft (TRUE) or apply publish/status (FALSE).
   */
  protected function isDraftWizardSave(): bool {
    return TRUE;
  }

  /**
   * Redirect and messaging after a successful wizard step save.
   */
  protected function onWizardStepSaveSuccess(NodeInterface $saved, FormStateInterface $form_state): void {
    $message = $this->isDraftWizardSave() && !$saved->isPublished()
      ? $this->t('Saved as draft.')
      : $this->t('Saved.');
    $this->messenger()->addStatus($message);
    $form_state->setRedirect($this->getNextRouteName(), ['node' => $saved->id()]);
  }

  /**
   * Loads the event, merges mel with baseline, persists via save service.
   *
   * @return array{errors: list<string>, node: ?\Drupal\node\NodeInterface}|null
   *   Null when preconditions fail (invalid nid or missing node).
   */
  protected function persistWizardMel(FormStateInterface $form_state, bool $draft): ?array {
    $nid = (int) ($form_state->getValue('nid') ?? 0);
    if ($nid < 1) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $loaded = $storage->load($nid);
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
    $form_state->setValue('mel', $merged);

    $payload = $this->melPayloadService->buildFromFormState($form_state, $this->entityTypeManager);
    return $this->saveService->save($payload, $loaded, $this->currentUser(), $draft);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $node = $this->getRouteNode($node);
    $this->assertVendorEvent($node);

    $form['#attributes']['class'][] = 'mel-event-studio-wizard-form';
    $form['#attributes']['data-mel-event-studio-wizard'] = '1';
    $form['#attributes']['data-mel-event-studio-form'] = '1';
    $form['#attributes']['data-mel-event-studio-section'] = $this->getCurrentStepId();
    $form['#tree'] = TRUE;
    if ($this->eventStudioLocationProvider instanceof LocationProviderManager) {
      $form['#attached']['drupalSettings']['myeventlaneLocation'] = $this->eventStudioLocationProvider->getFrontendSettings();
    }

    $form['nid'] = [
      '#type' => 'hidden',
      '#default_value' => (string) (int) $node->id(),
    ];
    $form['mel_studio_section'] = [
      '#type' => 'hidden',
      '#default_value' => $this->getCurrentStepId(),
    ];
    $form['mel_studio_changed'] = [
      '#type' => 'hidden',
      '#default_value' => (string) $node->getChangedTime(),
    ];
    $form['mel_studio_revision'] = [
      '#type' => 'hidden',
      '#default_value' => (string) (int) $node->getRevisionId(),
    ];

    $form['wizard_nav'] = [
      '#theme' => 'mel_event_studio_wizard_nav',
      '#node' => $node,
      '#current_step' => $this->getCurrentStepId(),
    ];

    $melDefaults = $this->wizardMelBaseline->getBaselineMel($node);
    $draft = $this->autosaveService->getDraft($node, $this->getCurrentStepId());
    $request = $this->requestStack->getCurrentRequest();
    if ($draft !== NULL && $request !== NULL && $request->query->getBoolean('restore_draft')) {
      $draftMel = $draft['mel'] ?? [];
      if (is_array($draftMel)) {
        $melDefaults = $this->mergeMel($melDefaults, $draftMel);
        $this->messenger()->addStatus($this->t('Restored your autosaved draft for this section. Save when you are ready to keep it.'));
      }
    }

    $form['mel'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-event-studio', 'mel-wizard-step-content']],
    ];

    $this->buildWizardStepContent($form, $form_state, $node, $melDefaults);
    $this->aiAssistBuilder->attachSupportedFields($form['mel'], $node, $this->getCurrentStepId());
    $this->entityAutocompleteMelNormalizer->normalizeDefaultValuesForForm($form['mel'], [
      'section' => $this->getCurrentStepId(),
      'event_id' => (int) $node->id(),
      'uid' => (int) $this->currentUser->id(),
    ]);

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
    ];

    $prev = $this->getPreviousRouteName();
    if ($prev !== NULL) {
      $form['actions']['back'] = [
        '#type' => 'link',
        '#title' => $this->t('Back'),
        '#url' => Url::fromRoute($prev, ['node' => $node->id()]),
        '#attributes' => ['class' => ['button', 'mel-btn']],
      ];
    }

    $form['actions']['continue'] = [
      '#type' => 'submit',
      '#value' => $this->getContinueButtonLabel(),
      '#button_type' => 'primary',
      '#submit' => ['::submitContinue'],
    ];

    $form['#attached']['library'][] = 'myeventlane_event_studio/mel_event_studio';

    return $form;
  }

  /**
   * Machine id for nav highlighting (basic, datetime, tickets, …).
   */
  abstract protected function getCurrentStepId(): string;

  /**
   * Options for a list_string field on the event bundle (empty if unavailable).
   *
   * @return array<string, string>
   */
  protected function listStringFieldOptions(string $field_name): array {
    try {
      $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'event');
    }
    catch (\Throwable) {
      return [];
    }
    $def = $definitions[$field_name] ?? NULL;
    if ($def === NULL || $def->getType() !== 'list_string') {
      return [];
    }
    $allowed = $def->getSetting('allowed_values');
    if (!is_array($allowed)) {
      return [];
    }
    $out = [];
    foreach ($allowed as $key => $item) {
      if (is_array($item) && isset($item['value'])) {
        $out[(string) $item['value']] = (string) ($item['label'] ?? $item['value']);
      }
      elseif (!is_array($item) && !is_int($key)) {
        $out[(string) $key] = (string) $item;
      }
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   *
   * Primary submit path is {@see submitContinue()} on the Continue button.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $nid = (int) ($form_state->getValue('nid') ?? 0);
    if ($nid < 1) {
      return;
    }
    $loaded = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$loaded instanceof NodeInterface || $loaded->bundle() !== 'event') {
      return;
    }

    $baseChanged = (int) ($form_state->getValue('mel_studio_changed') ?? 0);
    $baseRevisionId = (int) ($form_state->getValue('mel_studio_revision') ?? 0);
    if ($this->autosaveService->isStaleSubmission($loaded, $baseChanged, $baseRevisionId)) {
      $form_state->setErrorByName('', $this->t('This section was updated elsewhere. Refresh to continue editing safely.'));
    }
  }

  /**
   * Saves merged mel via EventStudioSaveService and redirects to the next route.
   */
  public function submitContinue(array &$form, FormStateInterface $form_state): void {
    $submitted = $form_state->getValue('mel') ?? [];
    if (is_array($submitted) && isset($form['mel']) && is_array($form['mel'])) {
      $form_state->setValue('mel', $this->entityAutocompleteMelNormalizer->normalizeValuesForForm($form['mel'], $submitted, [
        'section' => $this->getCurrentStepId(),
        'event_id' => (int) ($form_state->getValue('nid') ?? 0),
        'uid' => (int) $this->currentUser->id(),
      ]));
    }

    $result = $this->persistWizardMel($form_state, $this->isDraftWizardSave());
    if ($result === NULL) {
      return;
    }
    if ($result['errors'] !== []) {
      foreach ($result['errors'] as $msg) {
        $this->messenger()->addError($msg);
      }
      return;
    }

    $saved = $result['node'];
    if (!$saved instanceof NodeInterface) {
      $this->messenger()->addError($this->t('Could not save the event.'));
      return;
    }

    $this->autosaveService->clearDraft($saved, $this->getCurrentStepId());
    $this->onWizardStepSaveSuccess($saved, $form_state);
  }

}
