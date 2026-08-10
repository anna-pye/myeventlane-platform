<?php

declare(strict_types=1);

namespace Drupal\mel_ticket\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Standalone add/edit form for ticket types (admin UI or deep links).
 */
final class TicketTypeForm extends ContentEntityForm {

  private TicketTierLifecycleService $ticketTierLifecycle;

  private LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $instance */
    $instance = parent::create($container);
    $instance->ticketTierLifecycle = $container->get('myeventlane_event.ticket_tier_lifecycle');
    $instance->logger = $container->get('logger.factory')->get('mel_ticket');
    $instance->routeMatch = $container->get('current_route_match');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    if (!$this->entity instanceof TicketTypeInterface) {
      throw new \LogicException('TicketTypeForm can only save mel_ticket_type entities.');
    }

    $event = $this->resolveEvent();
    try {
      if ($this->entity->isNew()) {
        $values = $this->entity->toArray();
        if ($event instanceof NodeInterface && empty($values['event'])) {
          $values['event'] = ['target_id' => (int) $event->id()];
        }
        $this->entity = $this->ticketTierLifecycle->persistNewTicketType($values, $event);
        $status = SAVED_NEW;
      }
      else {
        $this->ticketTierLifecycle->updateTicketType($this->entity, $event);
        $status = SAVED_UPDATED;
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Ticket type form save failed for @id: @message', [
        '@id' => (string) ($this->entity->id() ?? 'new'),
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }

    $this->messenger()->addStatus($this->t('The ticket type %label was saved.', ['%label' => $this->entity->label()]));
    $form_state->setRedirect('entity.mel_ticket_type.canonical', ['mel_ticket_type' => $this->entity->id()]);
    return $status;
  }

  private function resolveEvent(): ?NodeInterface {
    $event = $this->routeMatch->getParameter('event');
    if ($event instanceof NodeInterface && $event->bundle() === 'event') {
      return $event;
    }

    if (!$this->entity instanceof TicketTypeInterface || $this->entity->get('event')->isEmpty()) {
      return NULL;
    }

    $event = $this->entity->get('event')->entity;
    return $event instanceof NodeInterface && $event->bundle() === 'event' ? $event : NULL;
  }

}
