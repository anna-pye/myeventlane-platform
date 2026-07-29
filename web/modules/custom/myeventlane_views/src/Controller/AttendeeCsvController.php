<?php

declare(strict_types=1);

namespace Drupal\myeventlane_views\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_event_attendees\Service\MelAttendeeExportBuilder;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Provides an attendee CSV export response (legacy URL).
 *
 * Delegates row generation to {@see MelAttendeeExportBuilder} so the legacy
 * `/dashboard/attendees/export` URL produces the same canonical row shape as
 * every other attendee CSV exporter. Route access is enforced by
 * {@see \Drupal\myeventlane_views\Access\AttendeeCsvExportAccess}; this
 * controller re-checks ownership before streaming CSV.
 */
final class AttendeeCsvController extends ControllerBase {

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    private readonly LoggerChannelFactoryInterface $viewsLoggerFactory,
    private readonly MelAttendeeExportBuilder $exportBuilder,
    private readonly EventVendorAccessCheckerInterface $eventVendorAccessChecker,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('logger.factory'),
      $container->get('myeventlane_event_attendees.attendee_export_builder'),
      $container->get('myeventlane_vendor.event_access_checker'),
    );
  }

  /**
   * Builds a CSV response for attendees on an event.
   *
   * @return \Symfony\Component\HttpFoundation\Response|array
   *   A CSV download response when `download_csv` is set, or a redirect to the
   *   canonical organiser event portfolio.
   */
  public function handle(Request $request): Response|array {
    $eventIdRaw = $request->query->get('download_csv');
    if (!$eventIdRaw) {
      return new LocalRedirectResponse('/vendor/events');
    }

    $eventId = (int) $eventIdRaw;
    if ($eventId <= 0) {
      $this->viewsLoggerFactory
        ->get('myeventlane_views')
        ->warning('Invalid event id @id passed to attendee CSV export.', ['@id' => (string) $eventIdRaw]);
      throw new AccessDeniedHttpException();
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      $this->viewsLoggerFactory
        ->get('myeventlane_views')
        ->warning('Event @id not found or not an event for CSV export.', ['@id' => (string) $eventId]);
      throw new AccessDeniedHttpException();
    }

    $account = $this->currentUser;
    if (!$account->hasPermission('administer nodes')
      && !$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
      $this->viewsLoggerFactory
        ->get('myeventlane_views')
        ->info('Attendee CSV export denied for event @id, uid @uid.', [
          '@id' => (string) $eventId,
          '@uid' => (string) $account->id(),
        ]);
      throw new AccessDeniedHttpException();
    }

    $this->viewsLoggerFactory
      ->get('myeventlane_views')
      ->notice('Exporting attendee CSV via legacy /dashboard/attendees/export for event @id.', [
        '@id' => (string) $eventId,
      ]);

    $rows = $this->exportBuilder->buildRowsForEvent($event);
    $filename = $this->exportBuilder->buildFilename($event, 'attendees');
    $exportBuilder = $this->exportBuilder;

    return new StreamedResponse(static function () use ($exportBuilder, $rows): void {
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
  }

}
