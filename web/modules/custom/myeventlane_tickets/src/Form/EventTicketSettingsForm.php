<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for Event Ticket Settings edit form.
 */
final class EventTicketSettingsForm extends ContentEntityForm {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

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
    $form['#attributes']['class'][] = 'mel-ticket-tool-form';

    // Boolean settings must allow either choice. A required checkbox is
    // interpreted by browsers as "must be checked", which prevents organisers
    // from deliberately turning the setting off.
    foreach ([
      'show_tickets_left',
      'show_prices_before_tax',
      'enable_unique_answers',
      'status',
    ] as $optional_boolean_field) {
      if (isset($form[$optional_boolean_field]['widget']['value'])) {
        $form[$optional_boolean_field]['widget']['value']['#required'] = FALSE;
        unset($form[$optional_boolean_field]['widget']['value']['#attributes']['required']);
      }
    }

    // Pre-populate event if not set (from route parameter).
    /** @var \Drupal\myeventlane_tickets\Entity\EventTicketSettings $entity */
    $entity = $this->entity;
    if ($entity->isNew() && $entity->get('event')->isEmpty()) {
      $event = $this->routeMatch->getParameter('event');
      if ($event && $event->id()) {
        $entity->set('event', $event->id());
      }
    }

    // The selected workspace event owns these settings and cannot be changed
    // from this event-scoped route.
    if (isset($form['event'])) {
      $form['event']['#access'] = FALSE;
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
      $this->messenger()->addStatus($this->t('Created the ticket settings for this event.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Saved the ticket settings.'));
    }

    $form_state->setRedirect('myeventlane_tickets.event_tickets_settings', ['event' => $event_id]);
  }

}
