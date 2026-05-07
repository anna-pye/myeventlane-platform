<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_paragraph\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\myeventlane_event_attendees\Service\MelAttendeeExportBuilder;
use Drupal\myeventlane_event_attendees\Service\VendorAttendeePresentationService;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for vendor attendee export.
 */
final class AttendeeExportController extends ControllerBase implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * AttendeeExportController constructor.
   */
  public function __construct(
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly VendorAttendeePresentationService $vendorPresentation,
    private readonly MessengerInterface $messengerService,
    private readonly EventVendorAccessChecker $eventAccessChecker,
    private readonly MelAttendeeExportBuilder $exportBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_event_attendees.manager'),
      $container->get('myeventlane_event_attendees.vendor_presentation'),
      $container->get('messenger'),
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->get('myeventlane_event_attendees.attendee_export_builder'),
    );
  }

  /**
   * Access check: event workspace parity (owner or vendor team) or admin.
   */
  public function access(NodeInterface $event, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer nodes')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if ($this->eventAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
      return AccessResult::allowed()->cachePerUser()->addCacheableDependency($event);
    }

    return AccessResult::forbidden('Not authorized for this event.')->addCacheableDependency($event);
  }

  /**
   * Export attendee info for a given event as CSV.
   */
  public function export(NodeInterface $event): StreamedResponse|RedirectResponse {
    $access = $this->access($event, $this->currentUser());
    if ($access->isForbidden()) {
      $this->messengerService->addError($this->t('You do not have access to export this event.'));
      return $this->redirect('<front>');
    }

    $filename = $this->exportBuilder->buildFilename($event, 'attendees');
    $rows = $this->exportBuilder->buildRowsForEvent($event);

    $entities = $this->attendanceManager->getAttendeesForEvent((int) $event->id());
    $pairTotal = 0;
    foreach ($entities as $entity) {
      if ($entity instanceof EventAttendee) {
        $pairTotal += count($this->vendorPresentation->normalizeCustomAnswers($entity));
      }
    }
    $this->vendorPresentation->logVendorParityBatch(
      'checkout_paragraph_csv_export',
      (int) $event->id(),
      count($entities),
      $pairTotal,
    );

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

  /**
   * Queue export for async processing and notification.
   *
   * @todo Implement file-based export that generates a file first,
   * then queues notification. Current implementation streams directly.
   * For now, this is a placeholder.
   */
  public function queueExport(NodeInterface $event): RedirectResponse {
    // @todo Generate export file, save to temporary storage,
    // create secure download link, then call:
    // \Drupal::service('myeventlane_automation.export_notification')
    //   ->queueExportNotification($event, 'csv', $downloadUrl);
    $this->messengerService->addStatus($this->t('Export queued (implementation pending).'));
    return $this->redirect('<front>');
  }

}
