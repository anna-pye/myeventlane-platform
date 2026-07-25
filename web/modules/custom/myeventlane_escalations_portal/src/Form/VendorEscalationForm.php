<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_portal\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Organiser support request form — always stamps vendor_id.
 *
 * Distinct from CustomerEscalationForm (user_id only). Open requests on
 * /vendor/support are filtered by vendor_id, so hub-created cases must set it.
 */
final class VendorEscalationForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('myeventlane_vendor.current_vendor_resolver'),
      $container->get('logger.channel.myeventlane_escalations_portal'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_escalations_portal_vendor_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $vendor = $this->vendorResolver->resolveFromUser($this->currentUser);
    if (!$vendor) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('You are not associated with an organiser account.') . '</p>',
      ];
      return $form;
    }

    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('A brief summary of what you need help with.'),
    ];

    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Issue type'),
      '#required' => TRUE,
      '#options' => [
        '' => $this->t('- Select -'),
        'payment' => $this->t('Payments or payouts'),
        'refund' => $this->t('Refunds'),
        'ticket' => $this->t('Tickets or bookings'),
        'event' => $this->t('Event setup or publishing'),
        'technical' => $this->t('Technical issue'),
        'other' => $this->t('Other'),
      ],
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#required' => TRUE,
      '#rows' => 6,
      '#description' => $this->t('Include event names, order numbers, or dates if they help us help you faster.'),
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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $vendor = $this->vendorResolver->resolveFromUser($this->currentUser);
    if (!$vendor) {
      $form_state->setErrorByName('subject', $this->t('You are not associated with an organiser account.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $vendor = $this->vendorResolver->resolveFromUser($this->currentUser);
    if (!$vendor) {
      $this->messenger()->addError($this->t('You are not associated with an organiser account.'));
      $this->logger->error('Organiser support create failed: no vendor for uid @uid.', [
        '@uid' => (string) $this->currentUser->id(),
      ]);
      return;
    }

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
      'vendor_id' => $vendor->id(),
    ]);

    // Organiser asked MEL for help — ball starts with staff.
    if ($escalation->hasField('field_waiting_on')) {
      $escalation->set('field_waiting_on', 'staff');
    }

    $escalation->save();

    $this->logger->info('Organiser support request @id created for vendor @vid by uid @uid.', [
      '@id' => (string) $escalation->id(),
      '@vid' => (string) $vendor->id(),
      '@uid' => (string) $this->currentUser->id(),
    ]);

    $this->messenger()->addStatus($this->t('Your support request has been received. We’ll reply by email — please keep an eye on your inbox.'));

    $form_state->setRedirect('myeventlane_escalations_portal.vendor_view', [
      'escalation' => $escalation->id(),
    ]);
  }

}
