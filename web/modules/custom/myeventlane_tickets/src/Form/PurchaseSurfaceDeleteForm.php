<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Keeps widget deletion inside the selected event's widget list.
 */
final class PurchaseSurfaceDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\myeventlane_tickets\Entity\PurchaseSurface $entity */
    $entity = $this->getEntity();
    $route_event = $this->getRouteMatch()->getParameter('event');
    $route_event_id = $route_event instanceof NodeInterface
      ? (int) $route_event->id()
      : (int) $route_event;

    if ($route_event_id !== $entity->getEventId()) {
      throw new NotFoundHttpException();
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    /** @var \Drupal\myeventlane_tickets\Entity\PurchaseSurface $entity */
    $entity = $this->getEntity();
    return Url::fromRoute('myeventlane_tickets.event_tickets_widgets', [
      'event' => $entity->getEventId(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\myeventlane_tickets\Entity\PurchaseSurface $entity */
    $entity = $this->getEntity();
    $event_id = $entity->getEventId();
    parent::submitForm($form, $form_state);
    $form_state->setRedirect('myeventlane_tickets.event_tickets_widgets', [
      'event' => $event_id,
    ]);
  }

}
