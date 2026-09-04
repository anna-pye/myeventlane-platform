<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementFulfilmentManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Confirms one normal Prepare -> Ready -> Collect transition.
 */
final class OperationalEntitlementTransitionForm extends ConfirmFormBase {

  private NodeInterface $event;
  private Ticket $ticket;
  private string $target;

  public function __construct(
    private readonly OperationalEntitlementFulfilmentManager $fulfilmentManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('myeventlane_tickets.operational_entitlement_fulfilment'));
  }

  public function getFormId(): string {
    return 'myeventlane_operational_entitlement_transition';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL, ?Ticket $ticket = NULL, string $target = ''): array {
    if (!$event instanceof NodeInterface || !$ticket instanceof Ticket
      || (int) $ticket->get('event_id')->target_id !== (int) $event->id()
      || $ticket->getEntitlementType() === Ticket::ENTITLEMENT_TICKET
      || $this->fulfilmentManager->nextState($ticket->getFulfilmentStatus()) !== $target) {
      throw new AccessDeniedHttpException();
    }
    $this->event = $event;
    $this->ticket = $ticket;
    $this->target = $target;
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): string {
    return $this->fulfilmentManager->nextActionLabel($this->ticket->getFulfilmentStatus()) . '?';
  }

  public function getDescription(): string {
    return (string) $this->t('This updates the customer’s Your extras pass and records the staff action.');
  }

  public function getConfirmText(): string {
    return $this->fulfilmentManager->nextActionLabel($this->ticket->getFulfilmentStatus());
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('myeventlane_vendor.console.event_operational_addon_orders', ['event' => $this->event->id()]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $updated = $this->fulfilmentManager->transition($this->ticket, $this->event, $this->target);
      $this->messenger()->addStatus($this->t('Extra updated to @status.', [
        '@status' => $this->fulfilmentManager->statusLabel($updated->getFulfilmentStatus()),
      ]));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRedirect('myeventlane_vendor.console.event_operational_addon_orders', ['event' => $this->event->id()]);
  }

}
