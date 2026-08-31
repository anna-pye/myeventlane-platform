<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for Purchase Surface add/edit forms.
 */
final class PurchaseSurfaceForm extends ContentEntityForm {

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
    // Pre-populate event if not set (from route parameter).
    /** @var \Drupal\myeventlane_tickets\Entity\PurchaseSurface $entity */
    $entity = $this->entity;
    if ($entity->isNew() && $entity->get('event')->isEmpty()) {
      $event = $this->routeMatch->getParameter('event');
      if ($event && $event->id()) {
        $entity->set('event', $event->id());
      }
    }

    $form = parent::form($form, $form_state);
    $form['#attributes']['class'][] = 'mel-ticket-widget-entity-form';

    // Event scope and public tokens are controlled by the selected route and
    // the entity. Organisers should never need to edit those internals.
    if (isset($form['event'])) {
      $form['event']['#access'] = FALSE;
    }
    if (isset($form['embed_token'])) {
      $form['embed_token']['#access'] = FALSE;
    }

    if (isset($form['label']['widget'][0]['value'])) {
      $form['label']['widget'][0]['value']['#title'] = $this->t('Widget name');
      $form['label']['widget'][0]['value']['#description'] = $this->t('An internal name that helps you recognise this widget later.');
    }
    if (isset($form['surface_type']['widget'])) {
      $form['surface_type']['widget']['#title'] = $this->t('Widget style');
      $form['surface_type']['widget']['#description'] = $this->t('Choose how the link to this event should appear on your website.');
      $form['surface_type']['widget']['#options'] = [
        'popup' => $this->t('Booking button'),
        'embedded_checkout' => $this->t('Event card'),
        'collection' => $this->t('Compact event card'),
      ];
    }
    if (isset($form['status']['widget']['value'])) {
      $form['status']['widget']['value']['#title'] = $this->t('Make this widget active');
      $form['status']['widget']['value']['#description'] = $this->t('Pause it to stop the widget loading without deleting its saved setup.');
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    /** @var \Drupal\myeventlane_tickets\Entity\PurchaseSurface $entity */
    $entity = $this->entity;

    if (isset($actions['submit'])) {
      $actions['submit']['#value'] = $entity->isNew() ? $this->t('Create widget') : $this->t('Save widget');
    }
    if (!$entity->isNew() && isset($actions['delete'])) {
      $actions['delete']['#url'] = Url::fromRoute('entity.mel_purchase_surface.delete_form', [
        'event' => $entity->getEventId(),
        'mel_purchase_surface' => $entity->id(),
      ]);
    }

    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): void {
    $entity = $this->entity;
    $status = $entity->save();

    $event_id = $entity->getEventId();

    if ($status === SAVED_NEW) {
      $this->logger('myeventlane_tickets')->info('MEL ticket analytics hook widget_created for event @nid.', [
        '@nid' => (string) $event_id,
        'mel_analytics_event' => 'widget_created',
        'event_id' => (int) $event_id,
      ]);
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
