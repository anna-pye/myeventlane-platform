<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementFulfilmentManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Manual recovery when a customer cannot present or scan their pass.
 */
final class OperationalEntitlementRecoveryForm extends FormBase {

  private NodeInterface $event;

  public function __construct(
    private readonly OperationalEntitlementFulfilmentManager $fulfilmentManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('myeventlane_tickets.operational_entitlement_fulfilment'));
  }

  public function getFormId(): string {
    return 'myeventlane_operational_entitlement_recovery';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      throw new AccessDeniedHttpException();
    }
    $this->event = $event;
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Use this only when the customer’s Wallet or QR pass cannot be used. Check the booking first. Every recovery is logged.') . '</p>',
    ];
    $form['code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pass code'),
      '#required' => TRUE,
      '#maxlength' => 64,
      '#description' => $this->t('Enter the full code shown in My Bookings or the confirmation email.'),
    ];
    $form['target'] = [
      '#type' => 'select',
      '#title' => $this->t('Recovery action'),
      '#required' => TRUE,
      '#options' => [
        Ticket::FULFILMENT_READY => $this->t('Mark ready to collect'),
        Ticket::FULFILMENT_COLLECTED => $this->t('Mark collected'),
      ],
    ];
    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason'),
      '#required' => TRUE,
      '#maxlength' => 500,
      '#description' => $this->t('Explain what you checked and why the normal pass flow could not be used.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Record manual recovery'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $ticket = $this->fulfilmentManager->loadByCodeForEvent((string) $form_state->getValue('code'), (int) $this->event->id());
    if (!$ticket instanceof Ticket) {
      $form_state->setErrorByName('code', $this->t('No add-on pass with that code belongs to this event.'));
    }
    else {
      $form_state->set('ticket_id', (int) $ticket->id());
    }
    if (mb_strlen(trim((string) $form_state->getValue('reason'))) < 10) {
      $form_state->setErrorByName('reason', $this->t('Give a clear reason of at least 10 characters.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ticket = $this->fulfilmentManager->loadByIdForEvent(
      (int) $form_state->get('ticket_id'),
      (int) $this->event->id(),
    );
    if (!$ticket instanceof Ticket) {
      $this->messenger()->addError($this->t('The add-on pass could not be loaded.'));
      return;
    }
    try {
      $updated = $this->fulfilmentManager->transition(
        $ticket,
        $this->event,
        (string) $form_state->getValue('target'),
        (string) $form_state->getValue('reason'),
        TRUE,
      );
      $this->messenger()->addStatus($this->t('Manual recovery recorded. Pass status: @status.', [
        '@status' => $this->fulfilmentManager->statusLabel($updated->getFulfilmentStatus()),
      ]));
      $form_state->setRedirect('myeventlane_vendor.console.event_operational_addon_orders', ['event' => $this->event->id()]);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

}
