<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_paragraph\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_attendee\Service\AttendeeRepositoryResolver;
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
    private readonly AttendeeRepositoryResolver $repositoryResolver,
    private readonly MessengerInterface $messengerService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_attendee.repository_resolver'),
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

      // Get attendees via repository.
      $repository = $this->repositoryResolver->getRepository($event);
      $attendees = $repository->loadByEvent($event);

      if (empty($attendees)) {
        fclose($handle);
        return;
      }

      // Get column headers from first attendee's export row.
      $firstRow = $attendees[0]->toExportRow();
      $headers = array_keys($firstRow);

      // Map header keys to human-readable labels.
      $headerLabels = [
        'name' => $this->t('Name'),
        'email' => $this->t('Email'),
        'ticket_type' => $this->t('Ticket Type'),
        'checked_in' => $this->t('Checked In'),
        'checked_in_at' => $this->t('Checked In At'),
        'source' => $this->t('Source'),
        'ticket_code' => $this->t('Ticket Code'),
      ];

      // Write header row.
      $headerRow = [];
      foreach ($headers as $header) {
        $headerRow[] = $headerLabels[$header] ?? $header;
      }
      fputcsv($handle, $headerRow);

      // Write data rows.
      foreach ($attendees as $attendee) {
        $row = $attendee->toExportRow();
        $csvRow = [];
        foreach ($headers as $header) {
          $value = $row[$header] ?? '';
          // Convert boolean to readable string.
          if ($header === 'checked_in') {
            $value = $value ? $this->t('Yes') : $this->t('No');
          }
          $csvRow[] = $value;
        }
        fputcsv($handle, $csvRow);
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
