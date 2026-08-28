<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_tickets\Entity\TicketGroup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for Ticket Group add/edit forms.
 */
final class TicketGroupForm extends ContentEntityForm {

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
    /** @var \Drupal\myeventlane_tickets\Entity\TicketGroup $entity */
    $entity = $this->entity;
    if ($entity->isNew() && $entity->get('event')->isEmpty()) {
      $event = $this->routeMatch->getParameter('event');
      if ($event instanceof NodeInterface && $event->id()) {
        $entity->set('event', $event->id());
      }
    }

    $event = $entity->getEvent();
    $form['group_help'] = [
      '#type' => 'container',
      '#weight' => -30,
      '#attributes' => [
        'class' => ['mel-alert', 'mel-alert--info', 'mel-ticket-group-help'],
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => $this->t('Choose how these tickets should be offered to buyers.'),
      ],
      'detail' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('A section organises tickets under a heading. A bundle sells a fixed mix of tickets for one total price, such as two adults and two children.'),
      ],
    ];

    // The event comes from the workspace route. Organisers should not have to
    // search for it again or risk attaching this group to another event.
    if (isset($form['event'])) {
      $form['event']['#access'] = FALSE;
    }
    if (isset($form['ticket_products'])) {
      $form['ticket_products']['#access'] = FALSE;
    }

    $this->improveFieldCopy($form);

    $ticket_options = [];
    if ($event instanceof NodeInterface && $event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      foreach ($event->get('field_ticket_types')->referencedEntities() as $ticket) {
        if (!$ticket instanceof TicketTypeInterface || $ticket->isArchived() || $ticket->getTicketKind() !== 'paid') {
          continue;
        }
        $ticket_options[(int) $ticket->id()] = $ticket->label();
      }
    }

    $selected = [];
    if ($entity->hasField('ticket_types') && !$entity->get('ticket_types')->isEmpty()) {
      $selected = array_map('intval', array_column($entity->get('ticket_types')->getValue(), 'target_id'));
    }

    $form['ticket_type_picker'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Tickets in this section'),
      '#description' => $this->t('Select the ticket types that should appear below this heading. Each ticket can belong to one group.'),
      '#options' => $ticket_options,
      '#default_value' => $selected,
      '#weight' => 5,
      '#empty_option' => $this->t('No paid ticket types are available. Add tickets in Event Studio first.'),
      '#states' => [
        'visible' => [
          ':input[name="group_mode"]' => ['value' => 'section'],
        ],
      ],
    ];

    $component_defaults = [];
    if ($entity->hasField('bundle_components') && !$entity->get('bundle_components')->isEmpty()) {
      $stored_components = $entity->get('bundle_components')->first()?->getValue() ?? [];
      $component_defaults = is_array($stored_components['components'] ?? NULL)
        ? $stored_components['components']
        : [];
    }
    $form['bundle_components_picker'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#weight' => 6,
      '#attributes' => ['class' => ['mel-ticket-bundle-components']],
      '#states' => [
        'visible' => [
          ':input[name="group_mode"]' => ['value' => 'bundle'],
        ],
      ],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Set how many of each event ticket are included in one bundle. Use 0 for tickets that are not included.'),
      ],
    ];
    foreach ($ticket_options as $ticket_id => $ticket_label) {
      $form['bundle_components_picker'][(string) $ticket_id] = [
        '#type' => 'number',
        '#title' => $ticket_label,
        '#default_value' => max(0, (int) ($component_defaults[(string) $ticket_id] ?? $component_defaults[$ticket_id] ?? 0)),
        '#min' => 0,
        '#max' => 50,
        '#step' => 1,
      ];
    }

    if (isset($form['bundle_price'])) {
      $form['bundle_price']['#states'] = [
        'visible' => [
          ':input[name="group_mode"]' => ['value' => 'bundle'],
        ],
      ];
    }

    $form['#entity_builders'][] = '::buildTicketGroupEntity';
    $form['#after_build'][] = '::afterBuildImproveFieldCopy';

    return $form;
  }

  /**
   * Reapplies labels after field widgets finish their own processing.
   */
  public function afterBuildImproveFieldCopy(array $form, FormStateInterface $form_state): array {
    $this->improveFieldCopy($form);
    return $form;
  }

  /**
   * Uses plain organiser-facing labels for inherited base-field widgets.
   */
  private function improveFieldCopy(array &$form): void {
    if (isset($form['name']['widget'][0]['value'])) {
      $form['name']['widget'][0]['value']['#title'] = $this->t('Name shown to buyers');
      $form['name']['widget'][0]['value']['#description'] = $this->t('For example: Family pass, VIP package or Evening tickets.');
    }
    if (isset($form['description']['widget'][0]['value'])) {
      $form['description']['widget']['#title'] = $this->t('Short explanation (optional)');
      $form['description']['widget']['#description'] = $this->t('Explain who this option is for or what the bundle includes.');
      $form['description']['widget'][0]['#title'] = $this->t('Short explanation (optional)');
      $form['description']['widget'][0]['#description'] = $this->t('Explain who this option is for or what the bundle includes.');
      $form['description']['widget'][0]['value']['#title'] = $this->t('Short explanation (optional)');
      $form['description']['widget'][0]['value']['#description'] = $this->t('Explain who this option is for or what the bundle includes.');
    }
    if (isset($form['group_mode']['widget'])) {
      $form['group_mode']['widget']['#title'] = $this->t('How buyers purchase this');
      $form['group_mode']['widget']['#description'] = $this->t('Choose a heading-only section or a complete bundle with fixed ticket quantities and one price.');
    }
    if (isset($form['bundle_price']['widget'])) {
      $form['bundle_price']['widget']['#title'] = $this->t('Price for one complete bundle');
      $form['bundle_price']['widget']['#description'] = $this->t('Buyers pay this total for all tickets included in one bundle.');
    }
    if (isset($form['bundle_price']['widget'][0]['number'])) {
      $form['bundle_price']['widget'][0]['number']['#title'] = $this->t('Price for one complete bundle');
      $form['bundle_price']['widget'][0]['number']['#description'] = $this->t('Buyers pay this total for all tickets included in one bundle.');
    }
    if (isset($form['weight']['widget'][0]['value'])) {
      $form['weight']['widget'][0]['value']['#title'] = $this->t('Display order');
      $form['weight']['widget'][0]['value']['#description'] = $this->t('Use 0 for the first group, 1 for the next group, and so on.');
    }
    if (isset($form['status']['widget']['value'])) {
      $form['status']['widget']['value']['#title'] = $this->t('Show this group on the booking page');
      $form['status']['widget']['value']['#description'] = $this->t('Turn this off to keep the group without showing it to buyers.');
    }
  }

  /**
   * Copies the event-scoped checkbox values to the entity reference field.
   */
  public function buildTicketGroupEntity(string $entity_type_id, EntityInterface $entity, array &$form, FormStateInterface $form_state): void {
    if (!$entity instanceof TicketGroup || !$entity->hasField('ticket_types')) {
      return;
    }
    $mode = $this->submittedGroupMode($form_state, $entity);
    $selected = [];
    if ($mode === 'bundle') {
      $components = $this->submittedBundleComponents($form_state);
      $selected = array_keys($components);
      $entity->set('bundle_components', [
        'components' => array_combine(
          array_map('strval', $selected),
          array_values($components),
        ) ?: [],
      ]);
    }
    else {
      $selected = array_values(array_filter(
        array_map('intval', (array) $form_state->getValue('ticket_type_picker', [])),
      ));
      $entity->set('bundle_components', []);
      $entity->set('bundle_price', NULL);
    }
    $entity->set('ticket_types', array_map(
      static fn (int $ticket_id): array => ['target_id' => $ticket_id],
      $selected,
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): EntityInterface {
    $entity = parent::validateForm($form, $form_state);
    if (!$entity instanceof TicketGroup) {
      return $entity;
    }

    $event = $entity->getEvent();
    $mode = $this->submittedGroupMode($form_state, $entity);
    $components = $this->submittedBundleComponents($form_state);
    $selected = $mode === 'bundle'
      ? array_keys($components)
      : array_values(array_filter(array_map('intval', (array) $form_state->getValue('ticket_type_picker', []))));
    if (!$event instanceof NodeInterface) {
      return $entity;
    }

    if ($selected === []) {
      $form_state->setErrorByName(
        $mode === 'bundle' ? 'bundle_components_picker' : 'ticket_type_picker',
        $mode === 'bundle'
          ? $this->t('Include at least one ticket in the bundle.')
          : $this->t('Choose at least one ticket for this section.'),
      );
      return $entity;
    }

    $allowed = [];
    foreach ($event->get('field_ticket_types')->referencedEntities() as $ticket) {
      if ($ticket instanceof TicketTypeInterface && !$ticket->isArchived() && $ticket->getTicketKind() === 'paid') {
        $allowed[(int) $ticket->id()] = TRUE;
      }
    }
    if (array_diff($selected, array_keys($allowed)) !== []) {
      $form_state->setErrorByName(
        $mode === 'bundle' ? 'bundle_components_picker' : 'ticket_type_picker',
        $this->t('Choose only active paid tickets from this event.'),
      );
      return $entity;
    }

    if ($mode === 'bundle') {
      $price_item = $entity->get('bundle_price')->first();
      $bundle_price = $price_item && method_exists($price_item, 'toPrice') ? $price_item->toPrice() : NULL;
      if ($bundle_price === NULL || (float) $bundle_price->getNumber() <= 0) {
        $form_state->setErrorByName('bundle_price', $this->t('Enter a bundle price greater than zero.'));
        return $entity;
      }
      foreach ($event->get('field_ticket_types')->referencedEntities() as $ticket) {
        if (!$ticket instanceof TicketTypeInterface || !isset($components[(int) $ticket->id()])) {
          continue;
        }
        $ticket_price = $ticket->toPriceValue();
        if ($ticket_price !== NULL && strtoupper($ticket_price->getCurrencyCode()) !== strtoupper($bundle_price->getCurrencyCode())) {
          $form_state->setErrorByName('bundle_price', $this->t('The bundle price must use the same currency as its tickets.'));
          return $entity;
        }
      }
    }

    if ($mode !== 'section') {
      return $entity;
    }

    $storage = $this->entityTypeManager->getStorage('mel_ticket_group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event->id())
      ->execute();
    if ($entity->id() !== NULL) {
      unset($ids[$entity->id()]);
    }
    foreach ($storage->loadMultiple($ids) as $other_group) {
      if ($other_group->hasField('group_mode') && (string) ($other_group->get('group_mode')->value ?? 'section') !== 'section') {
        continue;
      }
      if (!$other_group->hasField('ticket_types') || $other_group->get('ticket_types')->isEmpty()) {
        continue;
      }
      $other_ids = array_map('intval', array_column($other_group->get('ticket_types')->getValue(), 'target_id'));
      if (array_intersect($selected, $other_ids) !== []) {
        $form_state->setErrorByName('ticket_type_picker', $this->t('A selected ticket is already in the “@group” group. Edit that group first.', [
          '@group' => $other_group->label(),
        ]));
        break;
      }
    }

    return $entity;
  }

  private function submittedGroupMode(FormStateInterface $form_state, TicketGroup $entity): string {
    $value = $form_state->getValue('group_mode');
    $mode = is_array($value)
      ? (string) ($value[0]['value'] ?? $value['value'] ?? '')
      : (string) $value;
    if ($mode === '') {
      $mode = (string) ($entity->get('group_mode')->value ?? 'section');
    }
    return $mode === 'bundle' ? 'bundle' : 'section';
  }

  /**
   * @return array<int, int>
   *   Ticket type ID => quantity in one bundle.
   */
  private function submittedBundleComponents(FormStateInterface $form_state): array {
    $values = (array) $form_state->getValue('bundle_components_picker', []);
    $components = [];
    foreach ($values as $ticket_id => $quantity) {
      if (!is_numeric($ticket_id) || !is_numeric($quantity) || (int) $quantity < 1) {
        continue;
      }
      $components[(int) $ticket_id] = min(50, (int) $quantity);
    }
    return $components;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): void {
    $entity = $this->entity;
    $status = $entity->save();

    $event_id = $entity->getEventId();

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Created the %label ticket group.', [
        '%label' => $entity->getName(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Saved the %label ticket group.', [
        '%label' => $entity->getName(),
      ]));
    }

    $form_state->setRedirect('myeventlane_tickets.event_tickets_groups', ['event' => $event_id]);
  }

}
