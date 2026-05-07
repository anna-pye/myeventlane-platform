<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Service;

use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;

/**
 * Interface for {@see VendorAttendeePresentationService}.
 *
 * Extracted so MEL Attendee Operations Layer services can be unit-tested
 * without doubling the final implementation. The implementation remains
 * `final`; consumers that previously type-hinted on the concrete class
 * continue to work because the implementation now also implements this
 * interface.
 */
interface VendorAttendeePresentationServiceInterface {

  /**
   * @return list<array{key: string, label: string, value: string}>
   */
  public function normalizeCustomAnswers(EventAttendee $attendee): array;

  public function customAnswersListToCsvString(array $normalized): string;

  public function resolveTicketTypeLabel(EventAttendee $attendee): string;

  /**
   * @return array{
   *   full_name: string,
   *   email: string,
   *   phone: string,
   *   ticket_type: string,
   *   ticket_code: string,
   *   source: string,
   *   custom_answers: list<array{key: string, label: string, value: string}>,
   *   custom_answers_display: string
   * }
   */
  public function buildVendorRowFromEventAttendee(EventAttendee $attendee): array;

  /**
   * @return array<string, mixed>
   */
  public function buildCsvExportRow(EventAttendee $attendee): array;

  public function logVendorParityBatch(
    string $surface,
    int $eventNid,
    int $rowCount,
    int $customAnswerPairsTotal,
    string $normalizationSource = 'event_attendee',
    int $canonicalRowCount = 0,
    int $paragraphSnapshotRowCount = 0,
  ): void;

  public function logRsvpEventQuestionTemplateCount(NodeInterface $event): void;

  public function logRsvpQuestionsSaved(int $eventNid, int $templateCount, int $answerPairsSaved): void;

}
