<?php

namespace Drupal\myeventlane_rsvp\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public RSVP form for MyEventLane.
 */
class RsvpPublicForm extends FormBase {

  use StringTranslationTrait;

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Must NOT have type because FormBase defines this untyped.
   */
  protected $routeMatch;

  /**
   * Logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * Messenger service.
   */
  protected MessengerInterface $messengerService;

  /**
   * Constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RouteMatchInterface $route_match,
    LoggerInterface $logger,
    MessengerInterface $messenger
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
    $this->logger = $logger;
    $this->messengerService = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('logger.factory')->get('myeventlane_rsvp'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_rsvp_public_form';
  }

  /**
   * Tries to detect the event from the route.
   */
  protected function getEventFromRoute(): ?NodeInterface {
    $candidate = $this->routeMatch->getParameter('node');
    if ($candidate instanceof NodeInterface && $candidate->bundle() === 'event') {
      return $candidate;
    }

    $candidate = $this->routeMatch->getParameter('event');
    if ($candidate instanceof NodeInterface && $candidate->bundle() === 'event') {
      return $candidate;
    }

    return null;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $event = null): array {
    $event = $event ?: $this->getEventFromRoute();
    $event_id = $event instanceof NodeInterface ? $event->id() : null;

    $form['event_id'] = [
      '#type' => 'hidden',
      '#value' => $event_id,
    ];

    if (!$event_id) {
      $this->logger->warning('RSVP form built without event.');
      $this->messengerService->addWarning($this->t('We could not determine the event.'));
    }

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Your name'),
      '#required' => true,
      '#maxlength' => 255,
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => true,
    ];

    $form['guests'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of guests'),
      '#required' => true,
      '#min' => 1,
      '#default_value' => 1,
    ];

    $form['actions'] = ['#type' => 'actions'];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('RSVP now'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ((int) $form_state->getValue('guests') < 1) {
      $form_state->setErrorByName('guests', $this->t('Please RSVP for at least one guest.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $event_id = $values['event_id'] ?? null;

    if (!$event_id) {
      $this->logger->error('RSVP submission missing event_id.');
      $this->messengerService->addError($this->t('Event not found.'));
      return;
    }

    $event = $this->entityTypeManager->getStorage('node')->load($event_id);

    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      $this->logger->error('Invalid event ID @id.', ['@id' => $event_id]);
      $this->messengerService->addError($this->t('Event not found.'));
      return;
    }

    $event_nid = $event->id();

    try {
      $storage = $this->entityTypeManager->getStorage('rsvp_submission');

      $submission = $storage->create([
        'event_id' => $event_nid,
        'name' => $values['name'] ?? '',
        'email' => $values['email'] ?? '',
        'guests' => (int) ($values['guests'] ?? 1),
      ]);

      $submission->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('RSVP save failed for event @id: @m', [
        '@id' => $event_nid,
        '@m' => $e->getMessage(),
      ]);
      $this->messengerService->addError($this->t('We could not save your RSVP.'));
      return;
    }

    $this->messengerService->addStatus(
      $this->t('Your RSVP has been recorded for @event.', ['@event' => $event->label()])
    );

    $form_state->setRedirect('entity.node.canonical', ['node' => $event_nid]);
  }

}