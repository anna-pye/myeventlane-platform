<?php

namespace Drupal\myeventlane_escalations_portal\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\myeventlane_escalations\Entity\Escalation;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin workflow actions for an escalation (controlled transitions).
 *
 * Status transitions (ONLY these change status):
 * - Mark in progress: status=in_progress, field_waiting_on=staff
 * - Resolve: status=resolved, field_waiting_on=staff, resolved_at set
 * - Reopen: status=in_progress, resolved_at cleared, field_waiting_on=vendor|staff
 *
 * Waiting buttons ONLY set field_waiting_on (customer|vendor|staff). Never set
 * status to waiting_vendor or waiting_customer. Entity allowed_values include
 * those for display/legacy; this form uses field_waiting_on as source of truth.
 */
final class AdminEscalationActionsForm extends FormBase {

  public function __construct(
    private readonly MessengerInterface $messenger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('messenger'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
    );
  }

  public function getFormId(): string {
    return 'myeventlane_admin_escalation_actions_form';
  }

  /**
   * @param \Drupal\myeventlane_escalations\Entity\Escalation $escalation
   */
  public function buildForm(array $form, FormStateInterface $form_state, Escalation $escalation = NULL): array {
    if (!$escalation) {
      $form['error'] = ['#markup' => $this->t('Escalation not found.')];
      return $form;
    }

    // Access: must be admin + have permission + update access.
    if (!$this->currentUser()->hasPermission('manage escalation workflow') || !$escalation->access('update')) {
      $form['error'] = ['#markup' => $this->t('You do not have access to manage this escalation.')];
      return $form;
    }

    $form['#attributes']['class'][] = 'mel-escalation-actions';
    $form['escalation_id'] = [
      '#type' => 'hidden',
      '#value' => (string) $escalation->id(),
    ];

    $status = (string) $escalation->get('status')->value;
    $vendor_exists = !$escalation->get('vendor_id')->isEmpty() && (bool) $escalation->get('vendor_id')->entity;

    // Button helpers.
    $primary = 'mel-button mel-button--primary';
    $secondary = 'mel-button mel-button--secondary';

    // Status transitions (best-practice: controlled, not free edit).
    $form['buttons'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-escalation-actions__buttons']],
    ];

    // Always allow "In progress" from new.
    if ($status === 'new') {
      $form['buttons']['mark_in_progress'] = [
        '#type' => 'submit',
        '#value' => $this->t('Mark in progress'),
        '#name' => 'mark_in_progress',
        '#attributes' => ['class' => [$primary]],
        '#submit' => ['::submitMarkInProgress'],
      ];
    }

    // While in progress, allow waiting state toggles + resolve.
    if (in_array($status, ['new', 'in_progress'], TRUE) || in_array($status, ['waiting_vendor', 'waiting_customer'], TRUE)) {
      $form['buttons']['waiting_on_customer'] = [
        '#type' => 'submit',
        '#value' => $this->t('Waiting on customer'),
        '#name' => 'waiting_on_customer',
        '#attributes' => ['class' => [$secondary]],
        '#submit' => ['::submitWaitingOnCustomer'],
      ];

      // Only show vendor waiting action if vendor exists.
      if ($vendor_exists) {
        $form['buttons']['waiting_on_vendor'] = [
          '#type' => 'submit',
          '#value' => $this->t('Waiting on vendor'),
          '#name' => 'waiting_on_vendor',
          '#attributes' => ['class' => [$secondary]],
          '#submit' => ['::submitWaitingOnVendor'],
        ];
      }

      $form['buttons']['waiting_on_staff'] = [
        '#type' => 'submit',
        '#value' => $this->t('Internal review'),
        '#name' => 'waiting_on_staff',
        '#attributes' => ['class' => [$secondary]],
        '#submit' => ['::submitWaitingOnStaff'],
      ];

      $form['buttons']['resolve'] = [
        '#type' => 'submit',
        '#value' => $this->t('Resolve'),
        '#name' => 'resolve',
        '#attributes' => ['class' => [$primary]],
        '#submit' => ['::submitResolve'],
      ];
    }

    // Reopen from resolved.
    if ($status === 'resolved' || $status === 'closed') {
      $form['buttons']['reopen'] = [
        '#type' => 'submit',
        '#value' => $this->t('Reopen'),
        '#name' => 'reopen',
        '#attributes' => ['class' => [$primary]],
        '#submit' => ['::submitReopen'],
      ];
    }

    return $form;
  }

  private function setWaitingOn(Escalation $escalation, string $waiting_on): void {
    $vendor_exists = !$escalation->get('vendor_id')->isEmpty() && (bool) $escalation->get('vendor_id')->entity;

    // Critical fix: never set vendor waiting_on unless vendor exists.
    if ($waiting_on === 'vendor' && !$vendor_exists) {
      $waiting_on = 'staff';
    }
    if ($escalation->hasField('field_waiting_on')) {
      $escalation->set('field_waiting_on', $waiting_on);
    }
  }

  private function save(Escalation $escalation, string $message): void {
    $escalation->save();
    $this->messenger->addStatus($message);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Unused. Submit handlers per button.
  }

  public function submitMarkInProgress(array &$form, FormStateInterface $form_state): void {
    $id = (int) $form_state->getValue('escalation_id');
    /** @var \Drupal\myeventlane_escalations\Entity\Escalation|null $escalation */
    $escalation = $this->entityTypeManager->getStorage('escalation')->load($id);
    if (!$escalation) {
      return;
    }

    $escalation->set('status', 'in_progress');
    $this->setWaitingOn($escalation, 'staff');
    $this->save($escalation, (string) $this->t('Escalation marked in progress.'));
    $form_state->setRebuild(FALSE);
    $form_state->setRedirect('<current>');
  }

  public function submitWaitingOnCustomer(array &$form, FormStateInterface $form_state): void {
    $id = (int) $form_state->getValue('escalation_id');
    /** @var \Drupal\myeventlane_escalations\Entity\Escalation|null $escalation */
    $escalation = $this->entityTypeManager->getStorage('escalation')->load($id);
    if (!$escalation) {
      return;
    }

    // Waiting buttons ONLY set field_waiting_on. Use Mark in progress for status.
    $this->setWaitingOn($escalation, 'customer');
    $this->save($escalation, (string) $this->t('Set to waiting on customer.'));
    $form_state->setRedirect('<current>');
  }

  public function submitWaitingOnVendor(array &$form, FormStateInterface $form_state): void {
    $id = (int) $form_state->getValue('escalation_id');
    /** @var \Drupal\myeventlane_escalations\Entity\Escalation|null $escalation */
    $escalation = $this->entityTypeManager->getStorage('escalation')->load($id);
    if (!$escalation) {
      return;
    }

    // Waiting buttons ONLY set field_waiting_on. Use Mark in progress for status.
    $this->setWaitingOn($escalation, 'vendor');
    $this->save($escalation, (string) $this->t('Set to waiting on vendor.'));
    $form_state->setRedirect('<current>');
  }

  public function submitWaitingOnStaff(array &$form, FormStateInterface $form_state): void {
    $id = (int) $form_state->getValue('escalation_id');
    /** @var \Drupal\myeventlane_escalations\Entity\Escalation|null $escalation */
    $escalation = $this->entityTypeManager->getStorage('escalation')->load($id);
    if (!$escalation) {
      return;
    }

    // Waiting buttons ONLY set field_waiting_on. Use Mark in progress for status.
    $this->setWaitingOn($escalation, 'staff');
    $this->save($escalation, (string) $this->t('Set to internal review.'));
    $form_state->setRedirect('<current>');
  }

  public function submitResolve(array &$form, FormStateInterface $form_state): void {
    $id = (int) $form_state->getValue('escalation_id');
    /** @var \Drupal\myeventlane_escalations\Entity\Escalation|null $escalation */
    $escalation = $this->entityTypeManager->getStorage('escalation')->load($id);
    if (!$escalation) {
      return;
    }

    $escalation->set('status', 'resolved');
    $this->setWaitingOn($escalation, 'staff');

    // Set resolved_at if field exists.
    if ($escalation->hasField('resolved_at')) {
      $escalation->set('resolved_at', $this->time->getRequestTime());
    }

    $this->save($escalation, (string) $this->t('Escalation resolved.'));
    $form_state->setRedirect('<current>');
  }

  public function submitReopen(array &$form, FormStateInterface $form_state): void {
    $id = (int) $form_state->getValue('escalation_id');
    /** @var \Drupal\myeventlane_escalations\Entity\Escalation|null $escalation */
    $escalation = $this->entityTypeManager->getStorage('escalation')->load($id);
    if (!$escalation) {
      return;
    }

    $escalation->set('status', 'in_progress');

    // Clear resolved_at if present.
    if ($escalation->hasField('resolved_at')) {
      $escalation->set('resolved_at', NULL);
    }

    // Waiting_on should be vendor IF vendor exists; else staff.
    $this->setWaitingOn($escalation, 'vendor');

    $this->save($escalation, (string) $this->t('Escalation reopened.'));
    $form_state->setRedirect('<current>');
  }

}
