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
use Psr\Log\LoggerInterface;

/**
 * Evaluates whether an event is ready to publish without changing state.
 */
final class EventReadinessService {

  use StringTranslationTrait;

  public function __construct(
    private readonly TicketTypeManager $ticketTypeManager,
    private readonly VendorPublishRequirementsGate $publishRequirementsGate,
    private readonly PaidPublishStripeGate $paidPublishStripeGate,
    private readonly EventStudioQuestionTemplateManager $questionTemplateManager,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  public function evaluate(NodeInterface $event, AccountInterface $account): EventReadinessResult {
    try {
      return $this->evaluateInternal($event, $account);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio publish readiness evaluation failed for event @nid uid=@uid: @message', [
        '@nid' => (string) ($event->id() ?? 'new'),
        '@uid' => (string) $account->id(),
        '@message' => $e->getMessage(),
      ]);
      return $this->degradedResult();
    }
  }

  private function evaluateInternal(NodeInterface $event, AccountInterface $account): EventReadinessResult {
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
      $completed[] = (string) $this->t('Event title');
    }

    if ($this->validateDates($event, $errors)) {
      $completed[] = (string) $this->t('Schedule');
    }

    $event_type = $this->eventType($event);
    if (!in_array($event_type, ['rsvp', 'paid', 'both', 'external'], TRUE)) {
      $errors[] = (string) $this->t('Choose how attendees will join this event.');
    }
    else {
      $completed[] = (string) $this->t('Tickets ready');
    }

    if ($event_type === 'external') {
      if (!$event->hasField('field_external_url') || $event->get('field_external_url')->isEmpty()) {
        $errors[] = (string) $this->t('Add the external booking URL.');
      }
      else {
        $completed[] = (string) $this->t('External booking link');
      }
    }

    if (in_array($event_type, ['paid', 'both'], TRUE)) {
      if ($this->validatePaidTickets($event, $errors, $warnings)) {
        $completed[] = (string) $this->t('Tickets ready');
      }
      $stripe = $this->validateStripePublish($account, (int) $event->id(), $warnings);
      if ($stripe !== NULL) {
        $errors[] = $stripe;
      }
      else {
        $completed[] = (string) $this->t('Payments connected');
      }
    }

    $denials = $this->loadPublishDenials($account, $warnings);
    foreach ($denials as $reason) {
      $errors[] = $reason;
    }
    if ($denials === []) {
      $completed[] = (string) $this->t('Organiser profile ready');
    }

    if (!$event->hasField('field_event_image') || $event->get('field_event_image')->isEmpty()) {
      $recommendations[] = (string) $this->t('Add a cover image so your event looks inviting.');
    }
    else {
      $completed[] = (string) $this->t('Cover image');
    }

    if ($this->validateCapacities($event, $errors, $warnings)) {
      $completed[] = (string) $this->t('Capacity');
    }

    $this->addOptionalRecommendations($event, $recommendations);
    $this->mergeQuestionReadinessFindings($event, $errors, $warnings);

    $errors = array_values(array_unique($errors));
    $warnings = array_values(array_unique($warnings));
    $completed = array_values(array_unique($completed));
    $recommendations = array_values(array_unique($recommendations));
    return EventReadinessResult::create($errors, $warnings, $completed, $recommendations);
  }

  private function degradedResult(): EventReadinessResult {
    return EventReadinessResult::create(
      [],
      [(string) $this->t('Publish readiness could not be fully calculated. Save your work and refresh the page.')],
      [],
      [],
    );
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
   * @param list<string> $warnings
   */
  private function validatePaidTickets(NodeInterface $event, array &$errors, array &$warnings): bool {
    $tickets = $this->loadEventTickets($event, $warnings);
    if ($tickets === NULL) {
      $errors[] = (string) $this->t('Ticketing could not be checked. Try again after saving.');
      return FALSE;
    }

    $has_active_paid = FALSE;
    foreach ($tickets as $ticket) {
      try {
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
      catch (\Throwable $e) {
        $this->logger->warning('Event Studio paid ticket readiness check failed for event @nid ticket @tid: @message', [
          '@nid' => (string) ($event->id() ?? 'new'),
          '@tid' => $ticket instanceof TicketTypeInterface ? (string) $ticket->id() : 'unknown',
          '@message' => $e->getMessage(),
        ]);
        $warnings[] = (string) $this->t('One or more tickets could not be checked.');
      }
    }

    if (!$has_active_paid) {
      $errors[] = (string) $this->t('Add at least one active paid ticket.');
      return FALSE;
    }
    return TRUE;
  }

  /**
   * @param list<string> $warnings
   *
   * @return array<int, TicketTypeInterface>|null
   */
  private function loadEventTickets(NodeInterface $event, array &$warnings): ?array {
    try {
      return $this->ticketTypeManager->loadEventTicketTypesForDisplay($event);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio ticket readiness load failed for event @nid: @message', [
        '@nid' => (string) ($event->id() ?? 'new'),
        '@message' => $e->getMessage(),
      ]);
      $warnings[] = (string) $this->t('Ticket configuration could not be loaded.');
      return NULL;
    }
  }

  /**
   * @param list<string> $warnings
   */
  private function validateStripePublish(AccountInterface $account, int $eventNodeId, array &$warnings): ?string {
    try {
      return $this->paidPublishStripeGate->validatePaidPublishAllowed($account, $eventNodeId);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio Stripe publish readiness check failed for event @nid uid=@uid: @message', [
        '@nid' => (string) $eventNodeId,
        '@uid' => (string) $account->id(),
        '@message' => $e->getMessage(),
      ]);
      $warnings[] = (string) $this->t('Payment onboarding could not be verified.');
      return (string) $this->t('Payment onboarding could not be verified. Try again shortly.');
    }
  }

  /**
   * @param list<string> $warnings
   *
   * @return list<string>
   */
  private function loadPublishDenials(AccountInterface $account, array &$warnings): array {
    try {
      return $this->publishRequirementsGate->getLivePublishDenialReasons($account);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio vendor publish gate failed for uid=@uid: @message', [
        '@uid' => (string) $account->id(),
        '@message' => $e->getMessage(),
      ]);
      $warnings[] = (string) $this->t('Organiser publish requirements could not be verified.');
      return [(string) $this->t('Organiser publish requirements could not be verified. Try again shortly.')];
    }
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

    $tickets = $this->loadEventTickets($event, $warnings);
    if ($tickets === NULL) {
      return FALSE;
    }

    foreach ($tickets as $ticket) {
      try {
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
      catch (\Throwable $e) {
        $this->logger->warning('Event Studio ticket capacity readiness check failed for event @nid ticket @tid: @message', [
          '@nid' => (string) ($event->id() ?? 'new'),
          '@tid' => $ticket instanceof TicketTypeInterface ? (string) $ticket->id() : 'unknown',
          '@message' => $e->getMessage(),
        ]);
        $warnings[] = (string) $this->t('One or more ticket capacity settings could not be checked.');
        $valid = FALSE;
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
   * @param list<string> $errors
   * @param list<string> $warnings
   */
  private function mergeQuestionReadinessFindings(NodeInterface $event, array &$errors, array &$warnings): void {
    try {
      foreach ($this->questionTemplateManager->buildQuestionReadinessFindings($event) as $finding) {
        $message = trim((string) ($finding['message'] ?? ''));
        if ($message === '') {
          continue;
        }
        if (($finding['severity'] ?? '') === 'blocker') {
          $errors[] = $message;
          continue;
        }
        $warnings[] = $message;
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio question readiness check failed for event @nid: @message', [
        '@nid' => (string) ($event->id() ?? 'new'),
        '@message' => $e->getMessage(),
      ]);
      $warnings[] = (string) $this->t('Checkout question readiness could not be verified.');
    }
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
