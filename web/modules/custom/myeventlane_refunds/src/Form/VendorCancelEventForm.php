<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_refunds\Service\RefundAccessResolver;
use Drupal\myeventlane_vendor_comms\Service\EventRecipientResolver;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for vendors to cancel events.
 */
final class VendorCancelEventForm extends FormBase {

  /**
   * Constructs VendorCancelEventForm.
   *
   * @param \Drupal\myeventlane_refunds\Service\RefundAccessResolver $accessResolver
   *   The access resolver.
   * @param \Drupal\myeventlane_vendor_comms\Service\EventRecipientResolver $recipientResolver
   *   The recipient resolver.
   * @param \Drupal\myeventlane_messaging\Service\MessagingManager $messagingManager
   *   The messaging manager.
   */
  public function __construct(
    private readonly RefundAccessResolver $accessResolver,
    private readonly EventRecipientResolver $recipientResolver,
    private readonly MessagingManager $messagingManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_refunds.access_resolver'),
      $container->get('myeventlane_vendor_comms.recipient_resolver'),
      $container->get('myeventlane_messaging.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_refunds_vendor_cancel_event_form';
  }

  /**
   * Access callback.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access(NodeInterface $node): AccessResult {
    return $this->accessResolver->accessManageEvent($node, $this->currentUser());
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL): array {
    $form['#event'] = $node;

    $form['warning'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning"><p><strong>' . $this->t('Warning:') . '</strong> ' . $this->t('Canceling an event will notify all attendees. This action cannot be undone.') . '</p></div>',
    ];

    $recipientCount = $this->recipientResolver->getRecipientCount($node);
    $form['recipient_info'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('This will send cancellation emails to @count attendee(s).', ['@count' => $recipientCount]) . '</p>',
    ];

    $form['cancel_action'] = [
      '#type' => 'radios',
      '#title' => $this->t('Cancellation Action'),
      '#options' => [
        'cancel_only' => $this->t('Cancel only (send cancellation email)'),
        'cancel_and_refund' => $this->t('Cancel and auto-refund all orders'),
      ],
      '#default_value' => 'cancel_only',
      '#required' => TRUE,
    ];

    $form['confirm_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Type CANCEL to confirm'),
      '#required' => TRUE,
      '#description' => $this->t('You must type "CANCEL" (all caps) to proceed.'),
    ];

    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand this action cannot be undone.'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel Event'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Back'),
      '#url' => $node->toUrl(),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $confirmText = $form_state->getValue('confirm_text');
    if ($confirmText !== 'CANCEL') {
      $form_state->setError($form['confirm_text'], $this->t('You must type "CANCEL" (all caps) to confirm.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $form['#event'];
    $cancelAction = $form_state->getValue('cancel_action');

    // Queue cancellation emails.
    $recipientEmails = $this->recipientResolver->getRecipientEmails($event);
    $eventDate = $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()
      ? $event->get('field_event_start')->date->format('F j, Y g:ia')
      : '';
    $eventLocation = $event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()
      ? $event->get('field_venue_name')->value
      : '';

    $context = [
      'event_title' => $event->label(),
      'event_date' => $eventDate,
      'event_location' => $eventLocation,
      'event_url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];

    foreach ($recipientEmails as $email) {
      $this->messagingManager->queue('event_cancelled', $email, $context);
    }

    // If cancel and refund, queue refund jobs for all orders.
    if ($cancelAction === 'cancel_and_refund') {
      $queue = \Drupal::service('queue')->get('event_cancel_refund_worker');
      $queue->createItem([
        'event_id' => $event->id(),
        'vendor_uid' => $this->currentUser()->id(),
      ]);
    }

    $this->messenger()->addStatus($this->t('Event cancellation processed. @count email(s) queued.', ['@count' => count($recipientEmails)]));
    $form_state->setRedirect('entity.node.canonical', ['node' => $event->id()]);
  }

}

