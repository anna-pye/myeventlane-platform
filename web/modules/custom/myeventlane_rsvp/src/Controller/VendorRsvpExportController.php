<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_event_attendees\Service\MelAttendeeExportBuilder;
use Drupal\myeventlane_rsvp\Service\UserRsvpRepository;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vendor RSVP CSV export controller.
 *
 * Delegates row generation to {@see MelAttendeeExportBuilder} so RSVP exports
 * share canonical headers, formula-injection escaping, and operational state
 * with the rest of the attendee operations layer. The legacy
 * UserRsvpRepository remains available for non-export reads (kept on the
 * controller for backwards compatibility).
 */
final class VendorRsvpExportController extends ControllerBase {

  public function __construct(
    private readonly UserRsvpRepository $repo,
    private readonly MelAttendeeExportBuilder $exportBuilder,
  ) {}

  public static function create(ContainerInterface $c): self {
    return new self(
      $c->get('myeventlane_rsvp.user_rsvp_repository'),
      $c->get('myeventlane_event_attendees.attendee_export_builder'),
    );
  }

  /**
   * Exports RSVP attendees as CSV.
   *
   * Source filter is fixed to event_attendee.source = 'rsvp' so the file
   * never includes ticket holders. Vendor metrics still come from
   * VendorMetricsService elsewhere; this exporter owns row formatting only.
   */
  public function export(NodeInterface $event): StreamedResponse {
    $rows = $this->exportBuilder->buildRowsForEvent($event, FALSE, 'rsvp');
    $filename = $this->exportBuilder->buildFilename($event, 'rsvps');
    $exportBuilder = $this->exportBuilder;

    $response = new StreamedResponse(static function () use ($exportBuilder, $rows): void {
      $handle = fopen('php://output', 'w');
      if (!$handle) {
        return;
      }
      $exportBuilder->streamCsv($handle, $rows);
      fclose($handle);
    }, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
    ]);
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
  }

}
