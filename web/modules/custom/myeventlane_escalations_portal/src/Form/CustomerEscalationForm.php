<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_portal\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_escalations_portal\Service\EscalationMailer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Simplified escalation creation form for customers.
 */
final class CustomerEscalationForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly EscalationMailer $mailer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('myeventlane_escalations_portal.mailer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_escalations_portal_customer_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('A brief summary of your issue.'),
    ];

    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Issue type'),
      '#required' => TRUE,
      '#options' => [
        '' => $this->t('- Select -'),
        'payment' => $this->t('Payment Issue'),
        'refund' => $this->t('Refund Request'),
        'ticket' => $this->t('Ticket Issue'),
        'event' => $this->t('Event Related'),
        'vendor' => $this->t('Vendor Dispute'),
        'customer' => $this->t('Customer Complaint'),
        'technical' => $this->t('Technical Issue'),
        'other' => $this->t('Other'),
      ],
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#required' => TRUE,
      '#rows' => 6,
      '#description' => $this->t('Please describe your issue in detail. Include any relevant order numbers, dates, or other information that may help us assist you.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit support request'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $storage = $this->entityTypeManager->getStorage('escalation');

    /** @var \Drupal\myeventlane_escalations\Entity\Escalation $escalation */
    $escalation = $storage->create([
      'subject' => $form_state->getValue('subject'),
      'description' => [
        'value' => $form_state->getValue('description'),
        'format' => 'plain_text',
      ],
      'type' => $form_state->getValue('type'),
      'status' => 'new',
      'priority' => 'normal',
      'user_id' => $this->currentUser->id(),
    ]);

    $escalation->save();

    $this->messenger()->addStatus($this->t('Your support request has been submitted. We will get back to you as soon as possible.'));

    // Notify vendor if one is assigned (unlikely from customer form, but safe).
    $this->mailer->notifyVendorNewEscalation($escalation);

    $form_state->setRedirect('myeventlane_escalations_portal.customer_view', [
      'escalation' => $escalation->id(),
    ]);
  }

}
