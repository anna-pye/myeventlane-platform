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
use Drupal\myeventlane_event_attendees\Service\VendorAttendeePresentationService;
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
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_event_attendees.manager'),
      $container->get('myeventlane_event_attendees.vendor_presentation'),
      $container->get('messenger'),
    );
  }

  /**
   * Access check: vendor owner or admin.
   */
  public function access(NodeInterface $event, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer nodes')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    $is_owner = (int) $event->getOwnerId() === (int) $account->id();
    return $is_owner
      ? AccessResult::allowed()->cachePerUser()->addCacheableDependency($event)
      : AccessResult::forbidden('Not the event owner.')->addCacheableDependency($event);
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

    $filename = 'attendees-' . $event->id() . '-' . date('Y-m-d') . '.csv';

    return new StreamedResponse(function () use ($event) {
      $handle = fopen('php://output', 'w');
      if (!$handle) {
        return;
      }

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

      if ($entities === []) {
        fclose($handle);
        return;
      }

      fputcsv($handle, [
        (string) $this->t('Name'),
        (string) $this->t('Email'),
        (string) $this->t('Phone'),
        (string) $this->t('Source'),
        (string) $this->t('Product variation'),
        (string) $this->t('Ticket Code'),
        (string) $this->t('Custom answers'),
        (string) $this->t('Checked In'),
        (string) $this->t('Checked In At'),
      ]);

      foreach ($entities as $attendee) {
        if (!$attendee instanceof EventAttendee) {
          continue;
        }
        $row = $this->vendorPresentation->buildCsvExportRow($attendee);
        fputcsv($handle, [
          $row['name'],
          $row['email'],
          $row['phone'],
          $row['source'],
          $row['ticket_type'],
          $row['ticket_code'],
          $row['custom_answers'],
          $row['checked_in'] ? (string) $this->t('Yes') : (string) $this->t('No'),
          $row['checked_in_at'],
        ]);
      }

      fclose($handle);
    }, 200, [
      'Content-Type' => 'text/csv',
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
