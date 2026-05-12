<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_event\Service\TicketTypeManager;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_vendor\Service\PaidPublishStripeGate;
use Drupal\myeventlane_vendor\Service\VendorPublishRequirementsGate;
use Drupal\node\NodeInterface;

/**
 * Evaluates whether an event is ready to publish without changing state.
 */
final class EventReadinessService {

  use StringTranslationTrait;

  public function __construct(
    private readonly TicketTypeManager $ticketTypeManager,
    private readonly VendorPublishRequirementsGate $publishRequirementsGate,
    private readonly PaidPublishStripeGate $paidPublishStripeGate,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  public function evaluate(NodeInterface $event, AccountInterface $account): EventReadinessResult {
    if ($event->bundle() !== 'event') {
      return EventReadinessResult::create([(string) $this->t('Invalid event.')]);
    }

    $errors = [];
    $warnings = [];
    $completed = [];
    $recommendations = [];

    $title = trim($event->label());
    if ($title === '' || strcasecmp($title, 'Untitled event') === 0) {
      $errors[] = (string) $this->t('Add an event title.');
    }
    else {
      $completed[] = (string) $this->t('Event title added.');
    }

    if ($this->validateDates($event, $errors)) {
      $completed[] = (string) $this->t('Event dates complete.');
    }

    $event_type = $this->eventType($event);
    if (!in_array($event_type, ['rsvp', 'paid', 'both', 'external'], TRUE)) {
      $errors[] = (string) $this->t('Choose how attendees will join this event.');
    }
    else {
      $completed[] = (string) $this->t('Booking mode selected.');
    }

    if ($event_type === 'external') {
      if (!$event->hasField('field_external_url') || $event->get('field_external_url')->isEmpty()) {
        $errors[] = (string) $this->t('Add the external booking URL.');
      }
      else {
        $completed[] = (string) $this->t('External booking URL added.');
      }
    }

    if (in_array($event_type, ['paid', 'both'], TRUE)) {
      if ($this->validatePaidTickets($event, $errors)) {
        $completed[] = (string) $this->t('Ticketing configured.');
      }
      $stripe = $this->paidPublishStripeGate->validatePaidPublishAllowed($account, (int) $event->id());
      if ($stripe !== NULL) {
        $errors[] = $stripe;
      }
      else {
        $completed[] = (string) $this->t('Payment onboarding complete.');
      }
    }

    $denials = $this->publishRequirementsGate->getLivePublishDenialReasons($account);
    foreach ($denials as $reason) {
      $errors[] = $reason;
    }
    if ($denials === []) {
      $completed[] = (string) $this->t('Vendor publish requirements complete.');
    }

    if (!$event->hasField('field_event_image') || $event->get('field_event_image')->isEmpty()) {
      $recommendations[] = (string) $this->t('Add a banner image for stronger event conversion.');
    }
    else {
      $completed[] = (string) $this->t('Branding image added.');
    }

    if ($this->validateCapacities($event, $errors, $warnings)) {
      $completed[] = (string) $this->t('Capacity settings valid.');
    }

    $this->addOptionalRecommendations($event, $recommendations);

    $errors = array_values(array_unique($errors));
    $warnings = array_values(array_unique($warnings));
    $completed = array_values(array_unique($completed));
    $recommendations = array_values(array_unique($recommendations));
    return EventReadinessResult::create($errors, $warnings, $completed, $recommendations);
  }

  /**
   * @param list<string> $errors
   */
  private function validateDates(NodeInterface $event, array &$errors): bool {
    if (!$event->hasField('field_event_start') || $event->get('field_event_start')->isEmpty()) {
      $errors[] = (string) $this->t('Add a start date.');
      return FALSE;
    }
    if (!$event->hasField('field_event_end') || $event->get('field_event_end')->isEmpty()) {
      $errors[] = (string) $this->t('Add an end date.');
      return FALSE;
    }

    try {
      $start = new DrupalDateTime((string) $event->get('field_event_start')->value);
      $end = new DrupalDateTime((string) $event->get('field_event_end')->value);
      if ($end->getTimestamp() <= $start->getTimestamp()) {
        $errors[] = (string) $this->t('End date must be after the start date.');
        return FALSE;
      }
    }
    catch (\Throwable) {
      $errors[] = (string) $this->t('Event dates are invalid.');
      return FALSE;
    }
    return TRUE;
  }

  /**
   * @param list<string> $errors
   */
  private function validatePaidTickets(NodeInterface $event, array &$errors): bool {
    $has_active_paid = FALSE;
    foreach ($this->ticketTypeManager->loadEventTicketTypesForDisplay($event) as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->isArchived() || !$ticket->isPublished()) {
        continue;
      }
      if ($ticket->getTicketKind() !== 'paid') {
        continue;
      }
      $price = $ticket->toPriceValue();
      if ($price !== NULL && (float) $price->getNumber() > 0) {
        $has_active_paid = TRUE;
        break;
      }
    }
    if (!$has_active_paid) {
      $errors[] = (string) $this->t('Add at least one active paid ticket.');
      return FALSE;
    }
    return TRUE;
  }

  /**
   * @param list<string> $errors
   * @param list<string> $warnings
   */
  private function validateCapacities(NodeInterface $event, array &$errors, array &$warnings): bool {
    $valid = TRUE;
    foreach (['field_capacity', 'field_event_capacity_total'] as $field_name) {
      if ($event->hasField($field_name) && !$event->get($field_name)->isEmpty() && (int) $event->get($field_name)->value < 0) {
        $errors[] = (string) $this->t('Event capacity cannot be negative.');
        $valid = FALSE;
      }
    }

    foreach ($this->ticketTypeManager->loadEventTicketTypesForDisplay($event) as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->isArchived()) {
        continue;
      }
      if ($ticket->hasField('capacity') && !$ticket->get('capacity')->isEmpty() && (int) $ticket->get('capacity')->value < 1) {
        $errors[] = (string) $this->t('Ticket capacity must be empty or at least 1.');
        $valid = FALSE;
      }
      if ($ticket->getTicketKind() === 'paid' && !$ticket->isPublished()) {
        $warnings[] = (string) $this->t('Inactive paid tickets will not be available at checkout.');
      }
    }
    return $valid;
  }

  private function eventType(NodeInterface $event): string {
    if (!$event->hasField('field_event_type') || $event->get('field_event_type')->isEmpty()) {
      return '';
    }
    return (string) $event->get('field_event_type')->value;
  }

  /**
   * @param list<string> $recommendations
   */
  private function addOptionalRecommendations(NodeInterface $event, array &$recommendations): void {
    $event_type = $this->eventType($event);
    if (in_array($event_type, ['rsvp', 'both'], TRUE)
      && $event->hasField('field_enable_donations')
      && ($event->get('field_enable_donations')->isEmpty() || !(bool) $event->get('field_enable_donations')->value)) {
      $recommendations[] = (string) $this->t('Consider enabling optional supporter donations for RSVP attendees.');
    }

    if ($event->hasField('field_event_summary') && $event->get('field_event_summary')->isEmpty()) {
      $recommendations[] = (string) $this->t('Add a short event summary so attendees understand the experience quickly.');
    }

    if ($event->hasField('field_accessibility_contact') && $event->get('field_accessibility_contact')->isEmpty()) {
      $recommendations[] = (string) $this->t('Add accessibility contact details to build attendee confidence.');
    }
  }

}
