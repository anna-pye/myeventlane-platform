<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_comms\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_messaging\Service\AttendeeRecipientResolver;
use Drupal\myeventlane_vendor_comms\Service\CommsRateLimiter;
use Drupal\myeventlane_vendor_comms\Service\EventRecipientResolver;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Compose form for organisers to message event guests.
 *
 * Canonical writer for VX2-06 Messages. Product language only — never
 * Vendor Comms / queue / mail plugin jargon in the UI.
 */
final class VendorEventCommsForm extends FormBase {

  /**
   * Maps organiser-facing types to messaging template suffixes.
   */
  private const TYPE_TEMPLATE_MAP = [
    'announcement' => 'update',
    'reminder' => 'update',
    'important_update' => 'important_change',
    'cancellation' => 'cancellation',
    'thank_you' => 'update',
    // Legacy values still accepted.
    'update' => 'update',
    'important_change' => 'important_change',
  ];

  public function __construct(
    private readonly AccountProxyInterface $currentUserAccount,
    private readonly QueueFactory $queueFactory,
    private readonly EventRecipientResolver $ticketRecipientResolver,
    private readonly AttendeeRecipientResolver $attendeeRecipientResolver,
    private readonly CommsRateLimiter $rateLimiter,
    private readonly Connection $database,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly LoggerInterface $logger,
    private readonly RequestStack $requestStackService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('queue'),
      $container->get('myeventlane_vendor_comms.recipient_resolver'),
      $container->get('myeventlane_messaging.attendee_recipient_resolver'),
      $container->get('myeventlane_vendor_comms.rate_limiter'),
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('logger.factory')->get('myeventlane_vendor_comms'),
      $container->get('request_stack'),
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
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    $node = $event;
    if (!$node) {
      return ['#markup' => $this->t('Event not found.')];
    }

    if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      $store = $vendorResolver->getStoreForUser($this->currentUserAccount);
      if (!$store || !$vendorResolver->vendorOwnsEvent($store, $node)) {
        return ['#markup' => $this->t('You do not have access to message guests for this event.')];
      }
    }

    $request = $this->requestStackService->getCurrentRequest();
    $defaultType = (string) ($request?->query->get('type') ?? 'announcement');
    if (!isset(self::TYPE_TEMPLATE_MAP[$defaultType])) {
      $defaultType = 'announcement';
    }

    $audience = (string) ($form_state->getValue('audience') ?? 'everyone');
    $recipientCount = $this->resolveRecipientCount($node, $audience);
    $rateLimitCheck = $this->rateLimiter->checkRateLimit((int) $node->id(), (int) $this->currentUserAccount->id());

    $form['#node'] = $node;
    $form['#recipient_count'] = $recipientCount;
    $form['#rate_limit'] = $rateLimitCheck;
    $form['#attributes']['class'][] = 'mel-messages-compose';

    $hubUrl = NULL;
    try {
      $hubUrl = Url::fromRoute('myeventlane_event_studio.workspace_messaging', ['node' => $node->id()])->toString();
    }
    catch (\Throwable) {
    }

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-messages-compose__intro']],
      'title' => [
        '#markup' => '<h2 class="mel-messages-compose__title">' . $this->t('New message') . '</h2>',
      ],
      'lede' => [
        '#markup' => '<p>' . $this->t('Send a clear update to guests for @event. This is for important information — not marketing.', [
          '@event' => $node->label(),
        ]) . '</p>',
      ],
    ];

    if ($hubUrl) {
      $form['intro']['back'] = [
        '#markup' => '<p><a class="mel-messages-hub__link" href="' . $hubUrl . '">' . $this->t('Back to Event Messages') . '</a></p>',
      ];
    }

    $form['audience_summary'] = [
      '#type' => 'markup',
      '#markup' => '<div class="mel-comms-info" role="status"><p><strong>' . $this->t('@count guest(s) will receive this message', [
        '@count' => $recipientCount,
      ]) . '</strong></p></div>',
    ];

    if (!$rateLimitCheck['allowed']) {
      $form['rate_limit_warning'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning" role="alert">' . $this->t('@reason', [
          '@reason' => $rateLimitCheck['reason'],
        ]) . '</div>',
      ];
      $form['#disabled'] = TRUE;
    }

    if ($recipientCount === 0) {
      $form['no_recipients'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning" role="status">' . $this->t('No guests to message yet. People appear here after they book a ticket or RSVP.') . '</div>',
      ];
      $form['#disabled'] = TRUE;
    }

    $form['message_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Message type'),
      '#required' => TRUE,
      '#options' => [
        'announcement' => $this->t('Announcement'),
        'reminder' => $this->t('Reminder'),
        'important_update' => $this->t('Important update'),
        'cancellation' => $this->t('Cancellation'),
        'thank_you' => $this->t('Thank you'),
      ],
      '#default_value' => $form_state->getValue('message_type', $defaultType),
      '#description' => $this->t('Choose the tone that matches why you are writing.'),
    ];

    $form['message_type_help'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-messages-compose__type-help']],
      'copy' => [
        '#markup' => '<ul>'
        . '<li><strong>' . $this->t('Announcement') . '</strong> — ' . $this->t('Helpful news guests will appreciate.') . '</li>'
        . '<li><strong>' . $this->t('Reminder') . '</strong> — ' . $this->t('Start time, doors, or how to get there.') . '</li>'
        . '<li><strong>' . $this->t('Important update') . '</strong> — ' . $this->t('Material changes guests must know.') . '</li>'
        . '<li><strong>' . $this->t('Cancellation') . '</strong> — ' . $this->t('The event will not go ahead. Prefer Cancel event when refunds apply.') . '</li>'
        . '<li><strong>' . $this->t('Thank you') . '</strong> — ' . $this->t('A warm note after the event.') . '</li>'
        . '</ul>',
      ],
    ];

    $form['audience'] = [
      '#type' => 'radios',
      '#title' => $this->t('Who should receive this?'),
      '#required' => TRUE,
      '#options' => [
        'everyone' => $this->t('Everyone — ticket holders and RSVP guests'),
        'ticket_holders' => $this->t('Ticket holders only'),
        'rsvp' => $this->t('RSVP guests (included in Everyone; choose Everyone for mixed events)'),
      ],
      '#default_value' => $form_state->getValue('audience', 'everyone'),
      '#description' => $this->t('Ticket type, checked in, waitlist, and custom selection are coming soon.'),
      '#ajax' => [
        'callback' => '::audienceAjax',
        'wrapper' => 'mel-messages-audience-count',
        'event' => 'change',
      ],
    ];

    $form['audience_summary']['#prefix'] = '<div id="mel-messages-audience-count">';
    $form['audience_summary']['#suffix'] = '</div>';

    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $form_state->getValue('subject', ''),
      '#description' => $this->t('Keep it short and clear — guests should know why you wrote.'),
    ];

    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
      '#rows' => 10,
      '#maxlength' => 5000,
      '#description' => $this->t('Write like you are talking to your community. Maximum 5000 characters.'),
      '#default_value' => $form_state->getValue('body', ''),
    ];

    $form['confirmation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I confirm this is essential event information — not a promotion'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['preview'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview'),
      '#submit' => ['::previewSubmit'],
      '#limit_validation_errors' => [['message_type'], ['audience'], ['subject'], ['body']],
    ];

    if ($form_state->get('preview')) {
      $form['preview'] = [
        '#type' => 'markup',
        '#markup' => '<div class="mel-comms-preview" role="region" aria-label="' . $this->t('Message preview') . '"><h3>' . $this->t('Preview') . '</h3><div class="preview-content">' . $form_state->get('preview') . '</div></div>',
      ];

      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Send message'),
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

    $form['past_sends'] = [
      '#type' => 'markup',
      '#markup' => $this->getPastSendsMarkup((int) $node->id()),
      '#weight' => 100,
    ];

    return $form;
  }

  /**
   * Ajax callback to refresh recipient count when audience changes.
   */
  public function audienceAjax(array &$form, FormStateInterface $form_state): array {
    $node = $form['#node'] ?? NULL;
    if ($node instanceof NodeInterface) {
      $audience = (string) $form_state->getValue('audience', 'everyone');
      $count = $this->resolveRecipientCount($node, $audience);
      $form['#recipient_count'] = $count;
      $form['audience_summary']['#markup'] = '<div class="mel-comms-info" role="status"><p><strong>' . $this->t('@count guest(s) will receive this message', [
        '@count' => $count,
      ]) . '</strong></p></div>';
    }
    return $form['audience_summary'];
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
    $recipientCount = (int) ($form['#recipient_count'] ?? 0);
    if ($recipientCount === 0) {
      $form_state->setError($form, $this->t('No guests to message yet for this audience.'));
    }

    $rateLimitCheck = $form['#rate_limit'] ?? ['allowed' => TRUE];
    if (empty($rateLimitCheck['allowed'])) {
      $form_state->setError($form, $rateLimitCheck['reason'] ?? $this->t('Please wait before sending another message.'));
    }

    if (!$form_state->getValue('confirmation')) {
      $form_state->setError($form['confirmation'], $this->t('Please confirm this is essential event information.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $form['#node'];
    $messageType = (string) $form_state->getValue('message_type');
    $audience = (string) $form_state->getValue('audience', 'everyone');
    $subject = (string) $form_state->getValue('subject');
    $body = (string) $form_state->getValue('body');
    $templateType = self::TYPE_TEMPLATE_MAP[$messageType] ?? 'update';

    if (!$form_state->get('preview')) {
      $preview = $this->buildPreview($subject, $body, $messageType);
      $form_state->set('preview', $preview);
      $form_state->setRebuild();
      return;
    }

    $now = \Drupal::time()->getRequestTime();
    $recipientCount = $this->resolveRecipientCount($node, $audience);
    $form['#recipient_count'] = $recipientCount;

    $logId = $this->database->insert('myeventlane_event_comms_log')
      ->fields([
        'event_id' => (int) $node->id(),
        'vendor_uid' => (int) $this->currentUserAccount->id(),
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

    $queue = $this->queueFactory->get('vendor_event_comms');
    $queue->createItem([
      'log_id' => $logId,
      'event_id' => (int) $node->id(),
      'message_type' => $templateType,
      'organiser_type' => $messageType,
      'audience' => $audience,
      'subject' => $subject,
      'body' => $body,
    ]);

    $this->logger->info('message_scheduled event=@eid type=@type audience=@audience recipients=@count', [
      '@eid' => (string) $node->id(),
      '@type' => $messageType,
      '@audience' => $audience,
      '@count' => (string) $recipientCount,
    ]);

    $this->messenger()->addStatus($this->t('Your message is on its way to @count guest(s).', [
      '@count' => $recipientCount,
    ]));

    try {
      $form_state->setRedirect('myeventlane_event_studio.workspace_messaging', ['node' => $node->id()]);
    }
    catch (\Throwable) {
      $form_state->setRedirect('myeventlane_vendor.console.event_promotion', ['event' => $node->id()]);
    }
  }

  /**
   * Builds preview HTML.
   */
  private function buildPreview(string $subject, string $body, string $messageType): string {
    $typeLabels = [
      'announcement' => $this->t('Announcement'),
      'reminder' => $this->t('Reminder'),
      'important_update' => $this->t('Important update'),
      'cancellation' => $this->t('Cancellation'),
      'thank_you' => $this->t('Thank you'),
      'update' => $this->t('Announcement'),
      'important_change' => $this->t('Important update'),
    ];

    $safeSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeBody = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $typeLabel = $typeLabels[$messageType] ?? $messageType;

    $output = '<div class="mel-messages-compose__preview-card">';
    $output .= '<p><strong>' . $this->t('Subject') . ':</strong> ' . $safeSubject . '</p>';
    $output .= '<p><strong>' . $this->t('Type') . ':</strong> ' . $typeLabel . '</p>';
    $output .= '<div class="mel-messages-compose__preview-body">' . $safeBody . '</div>';
    $output .= '</div>';

    return $output;
  }

  /**
   * Gets past sends markup.
   */
  private function getPastSendsMarkup(int $eventId): string {
    $sends = $this->database->select('myeventlane_event_comms_log', 'log')
      ->fields('log', ['id', 'subject', 'message_type', 'recipient_count', 'sent_count', 'status', 'sent_at'])
      ->condition('event_id', $eventId)
      ->orderBy('sent_at', 'DESC')
      ->range(0, 10)
      ->execute()
      ->fetchAll();

    if (empty($sends)) {
      return '';
    }

    $output = '<div class="mel-past-sends"><h3>' . $this->t('Recent messages') . '</h3>';
    $output .= '<table><thead><tr><th>' . $this->t('Date') . '</th><th>' . $this->t('Subject') . '</th><th>' . $this->t('Type') . '</th><th>' . $this->t('Guests') . '</th><th>' . $this->t('Status') . '</th></tr></thead><tbody>';

    foreach ($sends as $send) {
      $date = $this->dateFormatter->format((int) $send->sent_at, 'custom', 'j M Y');
      $status = match ((string) $send->status) {
        'pending', 'sending' => (string) $this->t('Sending'),
        'failed' => (string) $this->t('Failed'),
        default => (string) $this->t('Sent'),
      };
      $type = match ((string) $send->message_type) {
        'reminder' => (string) $this->t('Reminder'),
        'important_update', 'important_change' => (string) $this->t('Important update'),
        'cancellation' => (string) $this->t('Cancellation'),
        'thank_you' => (string) $this->t('Thank you'),
        default => (string) $this->t('Announcement'),
      };
      $output .= '<tr>';
      $output .= '<td>' . $date . '</td>';
      $output .= '<td>' . htmlspecialchars((string) $send->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
      $output .= '<td>' . htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
      $output .= '<td>' . (int) $send->sent_count . '/' . (int) $send->recipient_count . '</td>';
      $output .= '<td>' . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
      $output .= '</tr>';
    }

    $output .= '</tbody></table></div>';

    return $output;
  }

  /**
   * Resolves recipient count for an audience option.
   */
  private function resolveRecipientCount(NodeInterface $event, string $audience): int {
    return count($this->resolveRecipientEmails($event, $audience));
  }

  /**
   * Resolves recipient emails for an audience option.
   *
   * @return list<string>
   *   Emails.
   */
  private function resolveRecipientEmails(NodeInterface $event, string $audience): array {
    return match ($audience) {
      'ticket_holders' => $this->ticketRecipientResolver->getRecipientEmails($event),
      // RSVP-only is approximated as Everyone for mixed events until a dedicated
      // RSVP-only API is exposed; Everyone already includes RSVP + tickets.
      'rsvp', 'everyone' => $this->attendeeRecipientResolver->resolveEmails($event),
      default => $this->attendeeRecipientResolver->resolveEmails($event),
    };
  }

}
