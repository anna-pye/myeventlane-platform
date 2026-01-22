<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_comms\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for vendors to send event communications.
 */
final class VendorEventCommsForm extends FormBase {

  /**
   * Constructs VendorEventCommsForm.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly QueueFactory $queueFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('queue')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'vendor_event_comms_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node) {
      return ['#markup' => $this->t('Event not found.')];
    }

    // Verify vendor owns event.
    if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      $store = $vendorResolver->getStoreForUser($this->currentUser);
      if (!$store || !$vendorResolver->vendorOwnsEvent($store, $node)) {
        return ['#markup' => $this->t('Access denied. You do not own this event.')];
      }
    }

    // Get recipient count.
    $recipientResolver = \Drupal::service('myeventlane_vendor_comms.recipient_resolver');
    $recipientCount = $recipientResolver->getRecipientCount($node);

    // Check rate limit.
    $rateLimiter = \Drupal::service('myeventlane_vendor_comms.rate_limiter');
    $rateLimitCheck = $rateLimiter->checkRateLimit((int) $node->id(), (int) $this->currentUser->id());

    $form['#node'] = $node;
    $form['#recipient_count'] = $recipientCount;
    $form['#rate_limit'] = $rateLimitCheck;

    $form['info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="mel-comms-info"><p><strong>' . $this->t('Recipients: @count', ['@count' => $recipientCount]) . '</strong></p></div>',
    ];

    if (!$rateLimitCheck['allowed']) {
      $form['rate_limit_warning'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . $this->t('@reason', ['@reason' => $rateLimitCheck['reason']]) . '</div>',
      ];
      $form['#disabled'] = TRUE;
    }

    if ($recipientCount === 0) {
      $form['no_recipients'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . $this->t('No recipients found for this event.') . '</div>',
      ];
      $form['#disabled'] = TRUE;
    }

    $form['message_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Message Type'),
      '#required' => TRUE,
      '#options' => [
        'update' => $this->t('Event Update'),
        'important_change' => $this->t('Important Change'),
        'cancellation' => $this->t('Cancellation'),
      ],
      '#default_value' => $form_state->getValue('message_type', 'update'),
    ];

    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $form_state->getValue('subject', ''),
    ];

    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message Body'),
      '#required' => TRUE,
      '#rows' => 10,
      '#maxlength' => 5000,
      '#description' => $this->t('Plain text or safe HTML. Maximum 5000 characters.'),
      '#default_value' => $form_state->getValue('body', ''),
    ];

    $form['confirmation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I confirm this is essential event information'),
      '#required' => TRUE,
      '#description' => $this->t('Only send essential updates. This is not for marketing.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['preview'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview'),
      '#submit' => ['::previewSubmit'],
      '#limit_validation_errors' => [['message_type'], ['subject'], ['body']],
    ];

    if ($form_state->get('preview')) {
      $form['preview'] = [
        '#type' => 'markup',
        '#markup' => '<div class="mel-comms-preview"><h3>' . $this->t('Preview') . '</h3><div class="preview-content">' . $form_state->get('preview') . '</div></div>',
      ];

      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Confirm and Send'),
        '#button_type' => 'primary',
      ];
    }
    else {
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Preview'),
        '#button_type' => 'primary',
      ];
    }

    // Show past sends.
    $form['past_sends'] = [
      '#type' => 'markup',
      '#markup' => $this->getPastSendsMarkup((int) $node->id()),
      '#weight' => 100,
    ];

    return $form;
  }

  /**
   * Preview submit handler.
   */
  public function previewSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->set('preview', TRUE);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $recipientCount = $form['#recipient_count'];
    if ($recipientCount === 0) {
      $form_state->setError($form, $this->t('No recipients found for this event.'));
    }

    $rateLimitCheck = $form['#rate_limit'];
    if (!$rateLimitCheck['allowed']) {
      $form_state->setError($form, $rateLimitCheck['reason']);
    }

    if (!$form_state->getValue('confirmation')) {
      $form_state->setError($form['confirmation'], $this->t('You must confirm this is essential event information.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $form['#node'];
    $messageType = $form_state->getValue('message_type');
    $subject = $form_state->getValue('subject');
    $body = $form_state->getValue('body');

    // If preview, show preview.
    if (!$form_state->get('preview')) {
      $preview = $this->buildPreview($subject, $body, $messageType);
      $form_state->set('preview', $preview);
      $form_state->setRebuild();
      return;
    }

    // Create log entry.
    $now = \Drupal::time()->getRequestTime();
    $recipientCount = $form['#recipient_count'];

    $logId = \Drupal::database()->insert('myeventlane_event_comms_log')
      ->fields([
        'event_id' => (int) $node->id(),
        'vendor_uid' => (int) $this->currentUser->id(),
        'message_type' => $messageType,
        'subject' => $subject,
        'body' => $body,
        'recipient_count' => $recipientCount,
        'sent_count' => 0,
        'failed_count' => 0,
        'status' => 'pending',
        'sent_at' => $now,
      ])
      ->execute();

    // Enqueue send job.
    $queue = $this->queueFactory->get('vendor_event_comms');
    $queue->createItem([
      'log_id' => $logId,
      'event_id' => (int) $node->id(),
      'message_type' => $messageType,
      'subject' => $subject,
      'body' => $body,
    ]);

    $this->messenger()->addStatus($this->t('Message queued for sending to @count recipient(s).', ['@count' => $recipientCount]));

    // Redirect back to form.
    $form_state->setRedirect('myeventlane_vendor_comms.send', ['node' => $node->id()]);
  }

  /**
   * Builds preview HTML.
   */
  private function buildPreview(string $subject, string $body, string $messageType): string {
    $typeLabels = [
      'update' => $this->t('Event Update'),
      'important_change' => $this->t('Important Change'),
      'cancellation' => $this->t('Cancellation'),
    ];

    $output = '<div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; background: #fff;">';
    $output .= '<h2 style="color: #2c3e50;">' . $this->t('Subject: @subject', ['@subject' => $subject]) . '</h2>';
    $output .= '<p><strong>' . $this->t('Type:') . '</strong> ' . ($typeLabels[$messageType] ?? $messageType) . '</p>';
    $output .= '<div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-left: 4px solid #ff6b9d;">';
    $output .= $body;
    $output .= '</div>';
    $output .= '</div>';

    return $output;
  }

  /**
   * Gets past sends markup.
   */
  private function getPastSendsMarkup(int $eventId): string {
    $sends = \Drupal::database()->select('myeventlane_event_comms_log', 'log')
      ->fields('log', ['id', 'subject', 'message_type', 'recipient_count', 'sent_count', 'status', 'sent_at'])
      ->condition('event_id', $eventId)
      ->orderBy('sent_at', 'DESC')
      ->range(0, 10)
      ->execute()
      ->fetchAll();

    if (empty($sends)) {
      return '';
    }

    $output = '<div class="mel-past-sends"><h3>' . $this->t('Recent Messages') . '</h3><table><thead><tr><th>Date</th><th>Subject</th><th>Type</th><th>Recipients</th><th>Status</th></tr></thead><tbody>';

    foreach ($sends as $send) {
      $date = \Drupal::service('date.formatter')->format($send->sent_at, 'short');
      $output .= '<tr>';
      $output .= '<td>' . $date . '</td>';
      $output .= '<td>' . htmlspecialchars($send->subject) . '</td>';
      $output .= '<td>' . htmlspecialchars($send->message_type) . '</td>';
      $output .= '<td>' . $send->sent_count . '/' . $send->recipient_count . '</td>';
      $output .= '<td>' . htmlspecialchars($send->status) . '</td>';
      $output .= '</tr>';
    }

    $output .= '</tbody></table></div>';

    return $output;
  }

}
