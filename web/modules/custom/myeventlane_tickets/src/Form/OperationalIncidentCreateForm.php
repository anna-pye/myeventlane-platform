<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_tickets\Service\OperationalIncidentLifecycleManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Registers a new operational coordination row.
 */
final class OperationalIncidentCreateForm extends FormBase {

  public function __construct(
    private readonly OperationalIncidentLifecycleManager $lifecycleManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_tickets.operational_incident_lifecycle_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_operational_incident_create';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['help'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Creates an operational coordination record. Optional correlation id defaults to a new UUID.') . '</p>',
    ];

    $form['incident_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Incident correlation id'),
      '#maxlength' => 128,
      '#required' => FALSE,
    ];

    $form['incident_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Incident type token'),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];

    $form['severity'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Severity'),
      '#default_value' => 'info',
      '#maxlength' => 32,
      '#required' => TRUE,
    ];

    $form['event_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Event node id'),
      '#min' => 1,
      '#step' => 1,
    ];

    $form['order_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Order id'),
      '#min' => 1,
      '#step' => 1,
    ];

    $form['ticket_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Ticket id'),
      '#min' => 1,
      '#step' => 1,
    ];

    $form['operational_descriptors'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Operational descriptors'),
      '#description' => $this->t('Comma or newline separated machine tokens.'),
    ];

    $form['diagnostic_snapshot_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Diagnostic snapshot JSON (optional)'),
      '#description' => $this->t('Machine-safe JSON only; sensitive keys are stripped on save.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Register incident'),
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('entity.mel_operational_incident.collection'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $descriptors_raw = (string) $form_state->getValue('operational_descriptors');
    $tokens = preg_split('/[\s,]+/', trim($descriptors_raw)) ?: [];

    $snapshot_raw = trim((string) $form_state->getValue('diagnostic_snapshot_json'));
    $snapshot = $snapshot_raw === '' ? NULL : json_decode($snapshot_raw, TRUE);
    if (!is_array($snapshot) && $snapshot_raw !== '') {
      $this->messenger()->addError($this->t('Diagnostic snapshot must be valid JSON when provided.'));
      return;
    }

    $entity = $this->lifecycleManager->register([
      'incident_id' => trim((string) $form_state->getValue('incident_id')),
      'incident_type' => (string) $form_state->getValue('incident_type'),
      'severity' => (string) $form_state->getValue('severity'),
      'event_id' => $form_state->getValue('event_id'),
      'order_id' => $form_state->getValue('order_id'),
      'ticket_id' => $form_state->getValue('ticket_id'),
      'operational_descriptors' => $tokens,
      'diagnostic_snapshot' => is_array($snapshot) ? $snapshot : NULL,
    ], $this->currentUser());

    $this->messenger()->addStatus($this->t('Registered operational incident @id.', [
      '@id' => $entity->get('incident_id')->value,
    ]));
    $form_state->setRedirect('myeventlane_tickets.operational_incident_coordination', [
      'mel_operational_incident' => $entity->id(),
    ]);
  }

}
