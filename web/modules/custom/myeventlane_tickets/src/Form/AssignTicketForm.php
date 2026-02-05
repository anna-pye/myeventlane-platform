<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\TicketAssignmentTokenService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Form for buyers to assign holder details to an unassigned ticket.
 */
final class AssignTicketForm extends FormBase {

  /**
   * Constructs AssignTicketForm.
   */
  public function __construct(
    private readonly TicketAssignmentTokenService $tokenService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_tickets.ticket_assignment_token'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_tickets_assign_ticket_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $token = $this->getRouteMatch()->getParameter('token');
    if (!$token || !is_string($token)) {
      throw new NotFoundHttpException();
    }

    $result = $this->tokenService->validateToken($token);
    if (!$result) {
      throw new AccessDeniedHttpException($this->t('This link has expired or is invalid.'));
    }

    $ticket = $this->entityTypeManager->getStorage('myeventlane_ticket')->load($result['ticket_id']);
    if (!$ticket instanceof Ticket) {
      throw new NotFoundHttpException();
    }

    if ($ticket->get('status')->value !== Ticket::STATUS_ISSUED_UNASSIGNED) {
      $form['already_assigned'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('This ticket is already assigned.') . ' <a href="' . Url::fromRoute('myeventlane_checkout_flow.my_tickets')->toString() . '">' . $this->t('View My Tickets') . '</a></p>',
        '#weight' => -10,
      ];
      return $form;
    }

    $account = $this->currentUser();
    $purchaser_id = (int) $ticket->get('purchaser_uid')->target_id;
    if (!$account->hasPermission('administer myeventlane tickets') && (int) $account->id() !== $purchaser_id) {
      throw new AccessDeniedHttpException($this->t('You do not have permission to assign this ticket.'));
    }

    $form['#ticket'] = $ticket;
    $form['#token'] = $token;

    $event = $ticket->get('event_id')->entity;
    $form['info'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Assign holder details for your ticket to <strong>@event</strong>.', [
        '@event' => $event ? $event->label() : $this->t('this event'),
      ]) . '</p>',
      '#weight' => -10,
    ];

    $form['holder_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Holder name'),
      '#required' => TRUE,
      '#default_value' => $ticket->get('holder_name')->value ?? '',
      '#maxlength' => 255,
    ];

    $form['holder_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Holder email'),
      '#required' => TRUE,
      '#default_value' => $ticket->get('holder_email')->value ?? '',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Assign ticket'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\myeventlane_tickets\Entity\Ticket $ticket */
    $ticket = $form['#ticket'];
    if (!$ticket instanceof Ticket) {
      return;
    }

    $ticket->set('holder_name', trim((string) $form_state->getValue('holder_name')));
    $ticket->set('holder_email', trim((string) $form_state->getValue('holder_email')));
    $ticket->set('status', Ticket::STATUS_ASSIGNED);
    $ticket->save();

    $this->messenger()->addStatus($this->t('Ticket assigned successfully. You can now download your ticket.'));
    $form_state->setRedirectUrl(Url::fromRoute('myeventlane_checkout_flow.my_tickets'));
  }

}
