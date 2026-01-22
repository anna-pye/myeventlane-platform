<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_state\Form;

use Drupal\myeventlane_automation\Service\AutomationDispatchService;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to cancel an event.
 */
final class EventCancelForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_event_state_cancel_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $form['#node'] = $node;

    $form['warning'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' . $this->t('Cancelling this event will prevent new bookings. Existing attendees will be notified if you choose to send notifications.') . '</div>',
    ];

    $form['cancel_reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cancellation Reason'),
      '#description' => $this->t('Provide a reason for cancelling this event. This will be shown to attendees.'),
      '#required' => TRUE,
      '#rows' => 5,
    ];

    $form['notify_attendees'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Notify attendees by email'),
      '#description' => $this->t('Send cancellation emails to all confirmed attendees.'),
      '#default_value' => TRUE,
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
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $node = $form['#node'];
    if (!$node) {
      $form_state->setErrorByName('', $this->t('Event not found.'));
      return;
    }

    // Check ownership.
    if ((int) $node->getOwnerId() !== (int) $this->currentUser->id()) {
      $form_state->setErrorByName('', $this->t('You can only cancel your own events.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $form['#node'];
    $reason = $form_state->getValue('cancel_reason');
    $notify = (bool) $form_state->getValue('notify_attendees');

    // Set cancellation fields.
    if ($node->hasField('field_event_state_override')) {
      $node->set('field_event_state_override', 'cancelled');
    }
    if ($node->hasField('field_cancel_reason')) {
      $node->set('field_cancel_reason', $reason);
    }
    if ($node->hasField('field_cancelled_at')) {
      $node->set('field_cancelled_at', \Drupal::time()->getRequestTime());
    }

    // Resync state.
    if (\Drupal::hasService('myeventlane_event_state.resolver')) {
      $resolver = \Drupal::service('myeventlane_event_state.resolver');
      $state = $resolver->resolveState($node);
      if ($node->hasField('field_event_state')) {
        $node->set('field_event_state', $state);
      }
    }

    $node->save();

    // Log action.
    if (\Drupal::hasService('myeventlane_event_state.audit_logger')) {
      \Drupal::service('myeventlane_event_state.audit_logger')->log($node->id(), 'cancelled', [
        'reason' => $reason,
        'notify' => $notify,
        'uid' => $this->currentUser->id(),
      ]);
    }

    // Notify attendees if requested via automation queue.
    if ($notify && \Drupal::hasService('myeventlane_automation.dispatch') && \Drupal::hasService('myeventlane_attendee.repository_resolver')) {
      _myeventlane_event_state_notify_cancellation($node, $reason);
    }

    $this->messenger()->addStatus($this->t('Event has been cancelled.'));
    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

}

/**
 * Notifies attendees of cancellation via automation queue.
 */
function _myeventlane_event_state_notify_cancellation(NodeInterface $event, string $reason): void {
  try {
    $dispatchService = \Drupal::service('myeventlane_automation.dispatch');
    $repositoryResolver = \Drupal::service('myeventlane_attendee.repository_resolver');
    $queueFactory = \Drupal::service('queue');

    // Get all attendees for this event.
    $repository = $repositoryResolver->getRepository($event);
    $attendees = $repository->loadByEvent($event);

    $eventId = (int) $event->id();
    $now = \Drupal::time()->getRequestTime();

    foreach ($attendees as $attendee) {
      $email = $attendee->getEmail();
      if (empty($email)) {
        continue;
      }

      // Check idempotency.
      $recipientHash = $dispatchService->hashRecipient($email);
      if ($dispatchService->isAlreadySent($eventId, AutomationDispatchService::TYPE_EVENT_CANCELLED, $recipientHash)) {
        continue;
      }

      // Create dispatch record.
      $dispatchId = $dispatchService->createDispatch(
        $eventId,
        AutomationDispatchService::TYPE_EVENT_CANCELLED,
        $recipientHash,
        $now,
        ['cancel_reason' => $reason]
      );

      // Enqueue cancellation notification.
      $queue = $queueFactory->get('automation_event_cancelled');
      $queue->createItem([
        'dispatch_id' => $dispatchId,
        'event_id' => $eventId,
        'recipient_email' => $email,
        'cancel_reason' => $reason,
      ]);
    }

    \Drupal::logger('myeventlane_event_state')->info('Queued cancellation notifications for @count attendees', [
      '@count' => count($attendees),
    ]);
  }
  catch (\Exception $e) {
    \Drupal::logger('myeventlane_event_state')->error('Failed to queue cancellation notifications: @message', [
      '@message' => $e->getMessage(),
    ]);
  }
}
