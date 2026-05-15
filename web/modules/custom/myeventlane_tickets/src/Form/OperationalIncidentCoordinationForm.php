<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_tickets\Entity\OperationalIncident;
use Drupal\myeventlane_tickets\Service\OperationalIncidentLifecycleManager;
use Drupal\myeventlane_tickets\Service\OperationalIncidentWorkflowNormalizer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Staff coordination form (mutates operational incident entity only).
 */
final class OperationalIncidentCoordinationForm extends FormBase {

  public function __construct(
    private readonly OperationalIncidentLifecycleManager $lifecycleManager,
    private readonly OperationalIncidentWorkflowNormalizer $workflowNormalizer,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_tickets.operational_incident_lifecycle_manager'),
      $container->get('myeventlane_tickets.operational_incident_workflow_normalizer'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_operational_incident_coordination';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $entity = $this->routeMatch->getParameter('mel_operational_incident');
    if (!$entity instanceof OperationalIncident) {
      throw new \InvalidArgumentException('Operational incident parameter missing.');
    }

    $form['#title'] = $this->t('Coordinate incident @id', ['@id' => $entity->get('incident_id')->value]);

    $form['help'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Coordination only. This form does not repair tickets, orders, or scanner state.') . '</p>',
    ];

    $existing_notes = (string) $entity->get('resolution_notes')->value;
    if ($existing_notes !== '') {
      $form['resolution_notes_display'] = [
        '#type' => 'item',
        '#title' => $this->t('Existing coordination notes'),
        '#markup' => '<pre class="mel-ops-coordination-notes">' . $this->escapeHtml($existing_notes) . '</pre>',
      ];
    }

    $lifecycle_options = array_combine(
      $this->workflowNormalizer->allowedLifecycleStates(),
      $this->workflowNormalizer->allowedLifecycleStates(),
    );
    $form['lifecycle_state'] = [
      '#type' => 'select',
      '#title' => $this->t('Lifecycle state'),
      '#options' => $lifecycle_options,
      '#default_value' => (string) $entity->get('lifecycle_state')->value,
      '#required' => TRUE,
    ];

    $escalation_options = array_combine(
      $this->workflowNormalizer->allowedEscalationStates(),
      $this->workflowNormalizer->allowedEscalationStates(),
    );
    $form['escalation_state'] = [
      '#type' => 'select',
      '#title' => $this->t('Escalation state'),
      '#options' => $escalation_options,
      '#default_value' => (string) $entity->get('escalation_state')->value,
      '#required' => TRUE,
    ];

    $ack_options = array_combine(
      $this->workflowNormalizer->allowedAcknowledgementStates(),
      $this->workflowNormalizer->allowedAcknowledgementStates(),
    );
    $form['acknowledgement_state'] = [
      '#type' => 'select',
      '#title' => $this->t('Acknowledgement state'),
      '#options' => $ack_options,
      '#default_value' => (string) $entity->get('acknowledgement_state')->value,
      '#required' => TRUE,
    ];

    $assigned = $entity->get('assigned_uid')->value;
    $form['assigned_uid'] = [
      '#type' => 'number',
      '#title' => $this->t('Assigned user id'),
      '#description' => $this->t('Enter a staff uid, or leave empty to clear assignment.'),
      '#min' => 1,
      '#step' => 1,
      '#default_value' => ($assigned === NULL || $assigned === '') ? NULL : (int) $assigned,
    ];

    $form['resolution_append'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Append coordination note'),
      '#description' => $this->t('Optional. Appended to the coordination log with a timestamp boundary. Do not enter purchaser PII.'),
    ];

    $form['resolve_notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Resolution notes (when moving to resolved)'),
      '#description' => $this->t('Used only when lifecycle transitions to resolved; stored as resolution notes.'),
    ];

    $form['entity_id'] = [
      '#type' => 'value',
      '#value' => $entity->id(),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save coordination'),
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to workspace'),
      '#url' => Url::fromRoute('myeventlane_tickets.venue_operations_workspace'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $storage = $this->entityTypeManager()->getStorage('mel_operational_incident');
    $entity = $storage->load($form_state->getValue('entity_id'));
    if (!$entity instanceof OperationalIncident) {
      return;
    }

    $account = $this->currentUser();

    $target_lifecycle = (string) $form_state->getValue('lifecycle_state');
    $current_lifecycle = (string) $entity->get('lifecycle_state')->value;
    if ($target_lifecycle !== $current_lifecycle) {
      $resolve_notes = NULL;
      if ($this->workflowNormalizer->normalizeLifecycleState($target_lifecycle) === OperationalIncidentWorkflowNormalizer::LIFECYCLE_RESOLVED) {
        $resolve_notes = trim((string) $form_state->getValue('resolve_notes'));
        $resolve_notes = $resolve_notes === '' ? NULL : $resolve_notes;
      }
      $this->lifecycleManager->transitionLifecycle($entity, $target_lifecycle, $account, $resolve_notes);
    }

    $entity = $storage->load($entity->id());
    if (!$entity instanceof OperationalIncident) {
      return;
    }

    $this->lifecycleManager->setEscalationState(
      $entity,
      (string) $form_state->getValue('escalation_state'),
      $account,
    );

    $entity = $storage->load($entity->id());
    if (!$entity instanceof OperationalIncident) {
      return;
    }

    $this->lifecycleManager->setAcknowledgementState(
      $entity,
      (string) $form_state->getValue('acknowledgement_state'),
      $account,
    );

    $entity = $storage->load($entity->id());
    if (!$entity instanceof OperationalIncident) {
      return;
    }

    $raw_assign = $form_state->getValue('assigned_uid');
    $uid = ($raw_assign === NULL || $raw_assign === '') ? NULL : (int) $raw_assign;
    $this->lifecycleManager->assignOwner($entity, $uid, $account);

    $entity = $storage->load($entity->id());
    if (!$entity instanceof OperationalIncident) {
      return;
    }

    $append = trim((string) $form_state->getValue('resolution_append'));
    if ($append !== '') {
      $this->lifecycleManager->appendResolutionNotes($entity, $append, $account);
    }

    $this->messenger()->addStatus($this->t('Operational coordination saved.'));
    $form_state->setRedirect('myeventlane_tickets.venue_operations_workspace');
  }

  private function escapeHtml(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

}
