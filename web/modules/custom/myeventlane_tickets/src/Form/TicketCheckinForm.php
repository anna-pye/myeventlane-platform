<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_tickets\Service\EventAccess;
use Drupal\myeventlane_tickets\Service\TicketCheckinService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Vendor/staff ticket check-in form.
 */
final class TicketCheckinForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventAccess $eventAccess,
    private readonly TicketCheckinService $ticketCheckinService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_tickets.event_access'),
      $container->get('myeventlane_tickets.ticket_checkin_service'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_tickets_checkin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    if (!$event || $event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    if (!$this->eventAccess->canManageEventTickets($event)) {
      throw new AccessDeniedHttpException();
    }

    $form['event_id'] = [
      '#type' => 'hidden',
      '#value' => (int) $event->id(),
    ];

    $form['ticket_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ticket code or QR payload'),
      '#description' => $this->t('Paste the ticket code (e.g. MEL-...) or the signed QR payload (mel:v1:...).'),
      '#required' => TRUE,
      '#maxlength' => 512,
      '#attributes' => [
        'autocomplete' => 'off',
        'autofocus' => 'autofocus',
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Validate and check in'),
      '#button_type' => 'primary',
    ];

    $form['#action'] = Url::fromRoute('myeventlane_tickets.ticket_checkin_validate', ['event' => $event->id()])->toString();
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event_id = (int) $form_state->getValue('event_id');
    $event = $this->entityTypeManager->getStorage('node')->load($event_id);
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    if (!$this->eventAccess->canManageEventTickets($event)) {
      throw new AccessDeniedHttpException();
    }

    $result = $this->ticketCheckinService->checkIn(
      $event_id,
      trim((string) $form_state->getValue('ticket_code')),
      'web-form',
      'online',
    );

    if ($result['ok']) {
      $this->messenger()->addStatus($this->t('Checked in ticket @code successfully.', [
        '@code' => (string) $result['ticket_label'],
      ]));
      if (!empty($result['ticket_id'])) {
        $resend_url = Url::fromRoute(
          'myeventlane_tickets.ticket_resend',
          ['myeventlane_ticket' => $result['ticket_id']],
          ['absolute' => TRUE],
        )->toString();
        $this->messenger()->addStatus($this->t('Resend link: @url', ['@url' => $resend_url]));
      }
    }
    elseif (in_array((string) $result['result'], ['already_checked_in'], TRUE)) {
      $this->messenger()->addWarning((string) $result['message']);
    }
    else {
      $this->messenger()->addError((string) $result['message']);
    }

    $form_state->setRedirect('myeventlane_tickets.ticket_checkin', ['event' => $event_id]);
  }

}
