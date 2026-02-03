<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_messaging\Service\AttendeeRecipientResolver;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Form for vendors to notify event attendees.
 */
final class VendorNotifyForm extends FormBase {

  /**
   * Constructs VendorNotifyForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\myeventlane_messaging\Service\AttendeeRecipientResolver $recipientResolver
   *   The recipient resolver.
   * @param \Drupal\myeventlane_messaging\Service\MessagingManager $messagingManager
   *   The messaging manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly AttendeeRecipientResolver $recipientResolver,
    private readonly MessagingManager $messagingManager,
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('myeventlane_messaging.attendee_recipient_resolver'),
      $container->get('myeventlane_messaging.manager'),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_messaging_vendor_notify_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $event = NULL): array {
    if (!$event) {
      throw new AccessDeniedHttpException('Event not found.');
    }

    // Verify vendor owns the event.
    $this->assertEventOwnership($event);

    $recipientCount = $this->recipientResolver->getCount($event);

    $form['#event'] = $event;
    $form['#recipient_count'] = $recipientCount;

    $form['info'] = [
      '#type' => 'item',
      '#markup' => '<p><strong>' . $this->t('This will notify @count attendees.', [
        '@count' => $recipientCount,
      ]) . '</strong></p>',
    ];

    $form['message_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Message type'),
      '#description' => $this->t('Select the type of notification to send.'),
      '#required' => TRUE,
      '#options' => [
        'vendor_event_update' => $this->t('Update'),
        'vendor_event_important_change' => $this->t('Important change'),
        'vendor_event_cancellation' => $this->t('Cancellation'),
      ],
      '#default_value' => $form_state->getValue('message_type', 'vendor_event_update'),
    ];

    $form['subject_override'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject override (optional)'),
      '#description' => $this->t('Override the default subject line. Leave blank to use template default.'),
      '#default_value' => $form_state->getValue('subject_override', ''),
    ];

    $form['vendor_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Vendor note (optional)'),
      '#description' => $this->t('This note will be appended to the template body.'),
      '#default_value' => $form_state->getValue('vendor_note', ''),
      '#rows' => 5,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Send notifications'),
      ],
      'cancel' => [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => Url::fromRoute('entity.node.canonical', ['node' => $event->id()]),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $recipientCount = $form['#recipient_count'];
    if ($recipientCount === 0) {
      $form_state->setError($form, $this->t('No recipients found for this event.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $form['#event'];
    $messageType = (string) $form_state->getValue('message_type');
    $subjectOverride = trim((string) $form_state->getValue('subject_override'));
    $vendorNote = trim((string) $form_state->getValue('vendor_note'));

    // Resolve recipients (emails only to backend).
    $emails = $this->recipientResolver->resolveEmails($event);
    $recipientCount = count($emails);

    if ($recipientCount === 0) {
      $this->messenger()->addError($this->t('No recipients found for this event.'));
      return;
    }

    // Create audit record.
    $auditId = $this->createAuditRecord($event, $messageType, $vendorNote, $recipientCount);

    // Queue messages for each recipient.
    $queued = 0;
    foreach ($emails as $email) {
      $context = [
        'event_id' => (int) $event->id(),
        'event_title' => $event->getTitle(),
        'event_url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'audit_id' => $auditId,
      ];

      // Add subject override if provided.
      if (!empty($subjectOverride)) {
        $context['custom_subject'] = $subjectOverride;
      }

      // Add vendor note if provided.
      if (!empty($vendorNote)) {
        $context['message_body'] = '<p>' . nl2br(htmlspecialchars($vendorNote)) . '</p>';
      }

      // Queue via MessagingManager (idempotent).
      $this->messagingManager->queue($messageType, $email, $context);
      $queued++;
    }

    $this->messenger()->addStatus($this->t('Queued @count notification(s) for delivery.', [
      '@count' => $queued,
    ]));

    $form_state->setRedirect('entity.node.canonical', ['node' => $event->id()]);
  }

  /**
   * Creates an audit record for vendor-triggered send.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $template
   *   The template key.
   * @param string $note
   *   Optional vendor note.
   * @param int $recipientCount
   *   Number of recipients.
   *
   * @return int
   *   The audit record ID.
   */
  private function createAuditRecord(NodeInterface $event, string $template, string $note, int $recipientCount): int {
    $uid = (int) $this->currentUser->id();
    $eventId = (int) $event->id();
    $now = (int) time();

    $id = $this->database->insert('myeventlane_message_audit')
      ->fields([
        'uid' => $uid,
        'event_nid' => $eventId,
        'template' => $template,
        'note' => $note ?: NULL,
        'recipient_count' => $recipientCount,
        'created' => $now,
      ])
      ->execute();

    return (int) $id;
  }

  /**
   * Asserts that the current user can manage the given event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When access is denied.
   */
  private function assertEventOwnership(NodeInterface $event): void {
    $uid = (int) $this->currentUser->id();

    // Administrators always have access.
    if ($this->currentUser->hasPermission('administer nodes') || $uid === 1) {
      return;
    }

    // Owner check.
    if ((int) $event->getOwnerId() === $uid) {
      return;
    }

    // Vendor membership check via field_event_vendor -> field_vendor_users.
    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor && $vendor->hasField('field_vendor_users')) {
        foreach ($vendor->get('field_vendor_users')->getValue() as $item) {
          if (isset($item['target_id']) && (int) $item['target_id'] === $uid) {
            return;
          }
        }
      }
    }

    throw new AccessDeniedHttpException('You do not have permission to notify attendees for this event.');
  }

}
