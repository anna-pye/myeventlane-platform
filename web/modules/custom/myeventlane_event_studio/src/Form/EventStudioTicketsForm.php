<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_vendor\Ticketing\EventTicketsBuilder;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Wizard step: tickets — reuses EventTicketsBuilder (same as Event Studio).
 */
final class EventStudioTicketsForm extends EventStudioBaseForm {

  protected EventTicketsBuilder $eventTicketsBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->eventTicketsBuilder = $container->get('myeventlane_vendor.ticket_builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_event_studio_wizard_tickets';
  }

  /**
   * {@inheritdoc}
   */
  protected function getNextRouteName(): string {
    return 'myeventlane_event_studio.edit_description';
  }

  /**
   * {@inheritdoc}
   */
  protected function getPreviousRouteName(): ?string {
    return 'myeventlane_event_studio.edit_datetime';
  }

  /**
   * {@inheritdoc}
   */
  protected function getCurrentStepId(): string {
    return 'tickets';
  }

  /**
   * {@inheritdoc}
   */
  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $form_state->set('event', $node);
    $form_state->set('studio_node', $node);

    $form['mel']['tickets_intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('How will people join?'),
      '#attributes' => ['class' => ['mel-tickets-intro']],
    ];

    $form['mel']['field_event_type'] = [
      '#type' => 'radios',
      '#title' => '',
      '#options' => [
        'rsvp' => $this->t('Free RSVP'),
        'paid' => $this->t('Paid tickets'),
        'external' => $this->t('External link'),
      ],
      '#default_value' => $melDefaults['field_event_type'] ?? 'rsvp',
      '#attributes' => ['class' => ['mel-radios', 'mel-radios--tickets']],
    ];

    $form['mel']['studio_ticket_tiers'] = [
      '#type' => 'hidden',
      '#default_value' => $melDefaults['studio_ticket_tiers'] ?? '[]',
      '#attributes' => [
        'id' => 'mel-studio-ticket-tiers-json',
        'data-mel-studio-ticket-tiers' => '1',
      ],
    ];

    $form_state->set('mel_ticket_builder_value_prefix', ['mel', 'embedded_ticket_wizard']);
    $form['mel']['embedded_ticket_wizard'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-ticket-wizard-embed']],
    ];
    $this->eventTicketsBuilder->build($form['mel']['embedded_ticket_wizard'], $form_state, $node);

    $form['mel']['rsvp_capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('RSVP capacity'),
      '#min' => 0,
      '#default_value' => $melDefaults['rsvp_capacity'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[field_event_type]"]' => ['value' => 'rsvp'],
        ],
      ],
    ];

    $form['mel']['field_product_target'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Ticket product'),
      '#target_type' => 'commerce_product',
      '#selection_handler' => 'default',
      '#selection_settings' => [
        'target_bundles' => ['ticket' => 'ticket'],
      ],
      '#default_value' => $melDefaults['field_product_target'] ?? NULL,
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[field_event_type]"]' => ['value' => 'paid'],
        ],
      ],
    ];

    $form['mel']['external_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Booking or registration URL'),
      '#default_value' => $melDefaults['external_url'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[field_event_type]"]' => ['value' => 'external'],
        ],
      ],
    ];

    $form['mel']['collect_attendee_questions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Collect extra attendee details'),
      '#default_value' => !empty($melDefaults['collect_attendee_questions']),
    ];

    $form['#attached']['library'][] = 'myeventlane_vendor/ticket_cards';
    $form['#attached']['library'][] = 'core/drupal.ajax';
  }

  /**
   * Ticket builder AJAX actions (same contract as EventStudioForm).
   */
  public function handleAction(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('studio_node');
    if (!$event instanceof NodeInterface) {
      return;
    }
    $this->eventTicketsBuilder->handleAction($form, $form_state, $event);
    $nid = (int) $event->id();
    if ($nid > 0) {
      $fresh = $this->entityTypeManager->getStorage('node')->load($nid);
      if ($fresh instanceof NodeInterface) {
        $form_state->set('studio_node', $fresh);
        $form_state->set('event', $fresh);
      }
    }
  }

  /**
   * AJAX replace target for the embedded ticket builder shell.
   */
  public function ajaxRebuildTicketBuilder(array &$form, FormStateInterface $form_state): array {
    return $form['mel']['embedded_ticket_wizard']['builder_shell'] ?? [];
  }

}
