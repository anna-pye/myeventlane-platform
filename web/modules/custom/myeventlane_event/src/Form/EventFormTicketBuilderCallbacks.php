<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_vendor\Ticketing\EventTicketsBuilder;
use Drupal\node\NodeInterface;

/**
 * Bridges EventTicketsBuilder AJAX/submit callbacks onto the node event form.
 *
 * EventTicketsBuilder defaults to ::handleAction on the root form object
 * (wizard forms). Node forms use this service instead.
 *
 * Static entrypoints exist so the cached form structure never holds service
 * instances (PHP cannot serialize those reliably for FormCache).
 */
final class EventFormTicketBuilderCallbacks {

  public function __construct(
    private readonly EventTicketsBuilder $ticketBuilder,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Submit handler reference for serializable Form API (#submit).
   *
   * @param array<string, mixed> $form
   */
  public static function submitTicketActionFormCached(array &$form, FormStateInterface $form_state): void {
    \Drupal::service('myeventlane_event.event_form_ticket_builder_callbacks')->submitTicketAction($form, $form_state);
  }

  /**
   * AJAX callback reference for serializable Form API (#ajax).
   *
   * @param array<string, mixed> $form
   *
   * @return array<string, mixed>
   */
  public static function ajaxTicketRebuildFormCached(array &$form, FormStateInterface $form_state): array {
    return \Drupal::service('myeventlane_event.event_form_ticket_builder_callbacks')->ajaxTicketRebuild($form, $form_state);
  }

  /**
   * Submit handler wired into ticket builder buttons on the node form.
   */
  public function submitTicketAction(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    $entity = $form_object->getEntity();
    if (!$entity instanceof NodeInterface) {
      return;
    }
    $this->ticketBuilder->handleAction($form, $form_state, $entity);

    // Ticket pipeline saves the node with a new revision; reload the default
    // revision into the entity form so the next full submit matches storage.
    $nid = (int) $entity->id();
    if ($nid > 0) {
      $storage = $this->entityTypeManager->getStorage('node');
      $fresh = $storage->load($nid);
      if ($fresh instanceof NodeInterface && $form_object instanceof EntityFormInterface) {
        $form_object->setEntity($fresh);
      }
    }
  }

  /**
   * AJAX callback returning the ticket builder shell for replacement.
   */
  public function ajaxTicketRebuild(array &$form, FormStateInterface $form_state): array {
    // Replace entire mel_builder so mel_ops_after_tiers, questions,
    // and capacity blocks re-render after ticket actions (wrapper id on mel_builder).
    return $form['mel_layout']['mel_main']['mel_builder'] ?? ['#markup' => ''];
  }

}
