<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for sending test emails.
 */
final class TemplateTestForm extends FormBase {

  /**
   * Constructs TemplateTestForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\myeventlane_messaging\Service\MessagingManager $messagingManager
   *   The messaging manager.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MessagingManager $messagingManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('myeventlane_messaging.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_messaging_template_test_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $template = NULL): array {
    if (!$template) {
      $form['error'] = [
        '#markup' => $this->t('Template not specified.'),
      ];
      return $form;
    }

    $configName = "myeventlane_messaging.template.{$template}";
    $config = $this->configFactory->get($configName);

    if ($config->isNew()) {
      $form['error'] = [
        '#markup' => $this->t('Template @template does not exist.', ['@template' => $template]),
      ];
      return $form;
    }

    $form['#template'] = $template;

    $form['description'] = [
      '#type' => 'item',
      '#markup' => '<p>' . $this->t('Send a test email using the @template template. The message will be queued and sent through the normal messaging system.', [
        '@template' => $template,
      ]) . '</p>',
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Recipient email'),
      '#description' => $this->t('Email address to send the test message to.'),
      '#required' => TRUE,
      '#default_value' => $this->currentUser()->getEmail(),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Send test email'),
      ],
      'cancel' => [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => Url::fromRoute('myeventlane_messaging.templates'),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $email = trim((string) $form_state->getValue('email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $form_state->setError($form['email'], $this->t('Please enter a valid email address.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $template = $form['#template'];
    $email = trim((string) $form_state->getValue('email'));

    // Build sample context for test.
    $context = $this->getSampleContext($template);
    $context['is_test'] = TRUE;

    // Queue the test message.
    $this->messagingManager->queue($template, $email, $context, [
      'is_test' => TRUE,
    ]);

    $this->messenger()->addStatus($this->t('Test email queued for @email. It will be sent through the normal messaging queue.', [
      '@email' => $email,
    ]));

    $form_state->setRedirect('myeventlane_messaging.templates');
  }

  /**
   * Gets sample context for test email.
   *
   * @param string $template
   *   The template key.
   *
   * @return array
   *   Sample context array.
   */
  private function getSampleContext(string $template): array {
    $base = [
      'first_name' => 'Test',
      'last_name' => 'User',
      'email' => 'test@example.com',
      'event_title' => 'Test Event',
      'event_url' => 'https://example.com/events/test',
      'event_name' => 'Test Event',
      'order_number' => 'TEST-12345',
      'order_email' => 'test@example.com',
      'order_url' => 'https://example.com/orders/test',
      'total_paid' => '$50.00',
      'message_body' => '<p>This is a test message from the template test form.</p>',
    ];

    // Template-specific context (merged second so it overrides $base keys).
    $specific = match ($template) {
      'order_confirmation', 'order_receipt' => [
        'events' => [
          [
            'title' => 'Test Event',
            'url' => 'https://example.com/events/test',
            'start_date' => '30 January 2026',
            'start_time' => '7:00 pm',
            'venue_name' => 'Test Venue',
            'location' => '123 Test St, City, State',
            'organiser_name' => 'Sample Organiser',
            'organiser_url' => 'https://example.com/vendors/sample',
          ],
        ],
        'ticket_items' => [
          [
            'title' => 'General Admission',
            'quantity' => 1,
            'price' => '$50.00',
            'attendees' => [
              ['name' => 'Test User', 'email' => 'test@example.com'],
            ],
          ],
        ],
        'donation_total' => NULL,
        'tickets_need_assignment' => FALSE,
        'booking_total' => '$50.00',
        'is_guest' => FALSE,
        'is_paid' => TRUE,
        'event_url' => 'https://example.com/events/test',
        'organiser_name' => 'Sample Organiser',
        'organiser_url' => 'https://example.com/vendors/sample',
        'help_centre_url' => 'https://example.com/help',
        'refund_policy_url' => 'https://example.com/help/policies/refund-policy',
        'support_url' => 'https://example.com/support',
        'vendor_name' => 'Sample Organiser Pty Ltd',
        'vendor_abn' => '12 345 678 901',
        'order_total_gst' => '$9.00',
        'order_total' => '$99.00',
        'invoice_date_short' => '29 Apr 2026',
        'invoice_lines' => [
          [
            'title' => 'General Admission',
            'quantity' => 2,
            'unit_price' => '$45.00',
            'line_total' => '$90.00',
          ],
        ],
        'invoice_fee_lines' => [],
        'platform_name' => 'MyEventLane Inc',
        'platform_abn' => '11 304 813 593',
        'platform_fee_lines' => [
          ['label' => 'Platform fee (1.5%)', 'amount' => '$1.50', 'gst' => '$0.14'],
        ],
        'platform_total_gst' => '$0.14',
        'invoice_tax_lines' => [
          ['label' => 'GST', 'amount' => '$9.00'],
        ],
        'tax_lines' => [
          ['label' => 'GST', 'amount' => '$9.00'],
        ],
        'show_includes_gst_note' => TRUE,
      ],
      'order_invoice' => [
        'invoice_date' => 'April 9, 2026',
        'vendor_name' => 'Sample Organiser Pty Ltd',
        'vendor_abn' => '12 345 678 901',
        'order_total_gst' => '$5.00',
        'order_total' => '$57.00',
        'events' => [
          ['title' => 'Test Event'],
        ],
        'line_items' => [
          [
            'title' => 'General Admission',
            'quantity' => 2,
            'unit_price' => '$25.00',
            'line_total' => '$50.00',
          ],
        ],
        'invoice_lines' => [
          [
            'title' => 'General Admission',
            'quantity' => 2,
            'unit_price' => '$25.00',
            'line_total' => '$50.00',
          ],
        ],
        'fee_lines' => [
          ['label' => 'Processing fee', 'amount' => '$2.00'],
        ],
        'invoice_fee_lines' => [
          ['label' => 'Processing fee', 'amount' => '$2.00'],
        ],
        'platform_name' => 'MyEventLane Inc',
        'platform_abn' => '11 304 813 593',
        'platform_fee_lines' => [
          ['label' => 'Platform fee (1.5%)', 'amount' => '$0.75', 'gst' => '$0.07'],
        ],
        'platform_total_gst' => '$0.07',
        'tax_lines' => [
          ['label' => 'GST', 'amount' => '$5.00'],
        ],
        'invoice_tax_lines' => [
          ['label' => 'GST', 'amount' => '$5.00'],
        ],
        'total_paid' => '$57.00',
      ],
      'rsvp_confirmation' => [
        'guests' => 1,
        'event_date' => 'January 30, 2026 at 7:00 PM',
        'event_location' => '123 Test St',
        'attendee_email' => 'test@example.com',
      ],
      default => [],
    };

    return array_merge($base, $specific);
  }

}
