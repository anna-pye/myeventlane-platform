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

    // Template-specific context.
    $specific = match ($template) {
      'order_receipt' => [
        'events' => [
          [
            'title' => 'Test Event',
            'start_date' => 'January 30, 2026',
            'start_time' => '7:00 PM',
            'location' => '123 Test St, City, State',
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
        'donation_total' => 0,
      ],
      default => [],
    };

    return array_merge($base, $specific);
  }

}
