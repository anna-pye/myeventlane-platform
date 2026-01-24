<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for Purchase Surface add/edit forms.
 */
final class PurchaseSurfaceForm extends ContentEntityForm {

  /**
   * The current route match.
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $instance */
    $instance = parent::create($container);
    $instance->routeMatch = $container->get('current_route_match');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    // Pre-populate event if not set (from route parameter).
    /** @var \Drupal\myeventlane_tickets\Entity\PurchaseSurface $entity */
    $entity = $this->entity;
    if ($entity->isNew() && $entity->get('event')->isEmpty()) {
      $event = $this->routeMatch->getParameter('event');
      if ($event && $event->id()) {
        $entity->set('event', $event->id());
      }
    }

    // Make embed_token read-only.
    if (!$entity->isNew() && isset($form['embed_token'])) {
      $form['embed_token']['#disabled'] = TRUE;
      $form['embed_token']['#description'] = $this->t('This token is auto-generated and cannot be changed.');
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): void {
    $entity = $this->entity;
    $status = $entity->save();

    $event_id = $entity->getEventId();

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Created the %label widget.', [
        '%label' => $entity->getLabel(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Saved the %label widget.', [
        '%label' => $entity->getLabel(),
      ]));
    }

    $form_state->setRedirect('myeventlane_tickets.event_tickets_widgets', ['event' => $event_id]);
  }

}
