<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_pro\Service\ProEntitlementReconciler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form for Pro reconciliation and billing overrides.
 */
final class ProDiagnosticsForm extends FormBase {

  public function __construct(
    private readonly ProEntitlementReconciler $reconciler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_pro.entitlement_reconciler'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_pro_diagnostics_actions';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['target_uid'] = [
      '#type' => 'number',
      '#title' => $this->t('User ID'),
      '#description' => $this->t('Target user for grace extension or single-user reconcile.'),
      '#min' => 1,
      '#required' => FALSE,
    ];

    $form['extend_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Extend grace (days)'),
      '#description' => $this->t('Adds this many days to the user’s Pro grace expiry from the later of now or current grace end.'),
      '#min' => 1,
      '#max' => 90,
      '#default_value' => 7,
    ];

    $form['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['pro-diagnostics-actions']],
    ];

    $form['actions']['reconcile_all'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reconcile all Pro users'),
      '#submit' => ['::submitReconcileAll'],
      '#button_type' => 'primary',
    ];

    $form['actions']['dry_run'] = [
      '#type' => 'submit',
      '#value' => $this->t('Dry-run reconcile all'),
      '#submit' => ['::submitDryRun'],
    ];

    $form['actions']['reconcile_user'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reconcile selected user'),
      '#submit' => ['::submitReconcileUser'],
    ];

    $form['actions']['extend_grace'] = [
      '#type' => 'submit',
      '#value' => $this->t('Extend grace for selected user'),
      '#submit' => ['::submitExtendGrace'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

  /**
   * Submits reconcile all.
   */
  public function submitReconcileAll(array &$form, FormStateInterface $form_state): void {
    $stats = $this->reconciler->reconcile();
    $this->messenger()->addStatus($this->t(
      'Reconciliation complete: @checked checked, @added granted, @removed revoked.',
      [
        '@checked' => $stats['active_checked'],
        '@added' => $stats['roles_added'],
        '@removed' => $stats['roles_removed'],
      ]
    ));
  }

  /**
   * Submits dry-run: shows what would happen without applying changes.
   */
  public function submitDryRun(array &$form, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($this->t(
      'The table above shows current state. "Drift detected" indicates users where role or vendor.is_pro does not match subscription state. Use "Reconcile all Pro users" to apply fixes.'
    ));
  }

  /**
   * Reconciles a single user by UID.
   */
  public function submitReconcileUser(array &$form, FormStateInterface $form_state): void {
    $uid = (int) ($form_state->getValue('target_uid') ?? 0);
    if ($uid <= 0) {
      $this->messenger()->addError($this->t('Enter a valid user ID.'));
      return;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if ($user === NULL) {
      $this->messenger()->addError($this->t('User @uid not found.', ['@uid' => (string) $uid]));
      return;
    }

    $this->reconciler->reconcileUser($user);
    $this->messenger()->addStatus($this->t('Reconciled user @uid.', ['@uid' => (string) $uid]));
  }

  /**
   * Extends grace period for a managed Pro user.
   */
  public function submitExtendGrace(array &$form, FormStateInterface $form_state): void {
    $uid = (int) ($form_state->getValue('target_uid') ?? 0);
    $days = (int) ($form_state->getValue('extend_days') ?? 0);
    if ($uid <= 0) {
      $this->messenger()->addError($this->t('Enter a valid user ID.'));
      return;
    }
    if ($days <= 0) {
      $this->messenger()->addError($this->t('Enter a positive number of days.'));
      return;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if ($user === NULL) {
      $this->messenger()->addError($this->t('User @uid not found.', ['@uid' => (string) $uid]));
      return;
    }

    $seconds = $days * 86400;
    $changed = $this->reconciler->extendGracePeriodBySeconds($user, $seconds);
    if ($changed) {
      $this->messenger()->addStatus($this->t('Extended Pro grace for user @uid by @days day(s).', [
        '@uid' => (string) $uid,
        '@days' => (string) $days,
      ]));
      $this->reconciler->reconcileUser($user);
    }
    else {
      $this->messenger()->addWarning($this->t('Grace was not changed for user @uid (check required fields or managed flag).', [
        '@uid' => (string) $uid,
      ]));
    }
  }

}
