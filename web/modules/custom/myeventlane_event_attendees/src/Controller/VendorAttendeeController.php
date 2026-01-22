<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Controller;

use Drupal\Core\Url;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for vendor attendee management pages.
 */
final class VendorAttendeeController extends ControllerBase {

  /**
   * Constructs VendorAttendeeController.
   */
  public function __construct(
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_event_attendees.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Access check for vendor attendee routes.
   */
  public function access(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    // Must be an event node.
    if ($node->bundle() !== 'event') {
      return AccessResult::forbidden('Not an event.');
    }

    // Admin can access all.
    if ($account->hasPermission('administer event attendees')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Check if user is the event author.
    $isOwner = (int) $node->getOwnerId() === (int) $account->id();

    if ($isOwner && $account->hasPermission('view own event attendees')) {
      return AccessResult::allowed()
        ->cachePerUser()
        ->addCacheableDependency($node);
    }

    return AccessResult::forbidden('You do not have access to view attendees for this event.');
  }

  /**
   * Access check for single attendee operations.
   */
  public function accessAttendee(EventAttendee $event_attendee, AccountInterface $account): AccessResultInterface {
    $event = $event_attendee->getEvent();

    if (!$event instanceof NodeInterface) {
      return AccessResult::forbidden('Event not found.');
    }

    // Admin can manage all.
    if ($account->hasPermission('administer event attendees')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Check if user is the event author.
    $isOwner = (int) $event->getOwnerId() === (int) $account->id();

    if ($isOwner && $account->hasPermission('manage own event attendees')) {
      return AccessResult::allowed()
        ->cachePerUser()
        ->addCacheableDependency($event);
    }

    return AccessResult::forbidden();
  }

  /**
   * Page title callback for attendee list.
   */
  public function listTitle(NodeInterface $node): string {
    return (string) $this->t('Attendees for @event', ['@event' => $node->label()]);
  }

  /**
   * Lists attendees for an event, grouped by ticket type.
   */
  public function list(NodeInterface $node): array {
    $attendees = $this->attendanceManager->getAttendeesForEvent((int) $node->id());
    $availability = $this->attendanceManager->getAvailability($node);

    // Group attendees by source and ticket type.
    $grouped = [
      'rsvp' => [],
      'ticket' => [],
      'manual' => [],
    ];

    $ticketTypeGroups = [];

    foreach ($attendees as $attendee) {
      $source = $attendee->getSource();
      $grouped[$source][] = $attendee;

      // For ticket-based attendees, group by variation.
      if ($source === 'ticket' && $attendee->hasField('order_item') && !$attendee->get('order_item')->isEmpty()) {
        $orderItem = $attendee->get('order_item')->entity;
        if ($orderItem) {
          $purchasedEntity = $orderItem->getPurchasedEntity();
          if ($purchasedEntity) {
            $variationId = $purchasedEntity->id();
            $variationTitle = $purchasedEntity->label();

            // Extract ticket type from variation title (e.g., "Event Name – General" -> "General").
            $ticketType = $variationTitle;
            if (strpos($variationTitle, ' – ') !== FALSE) {
              $parts = explode(' – ', $variationTitle, 2);
              $ticketType = $parts[1] ?? $variationTitle;
            }

            if (!isset($ticketTypeGroups[$variationId])) {
              $ticketTypeGroups[$variationId] = [
                'title' => $ticketType,
                'attendees' => [],
              ];
            }
            $ticketTypeGroups[$variationId]['attendees'][] = $attendee;
          }
        }
      }
    }

    $build = [];

    // Summary section.
    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['attendee-summary']],
    ];

    $summaryItems = [
      $this->t('Total attendees: @count', ['@count' => count($attendees)]),
      $this->t('RSVPs: @count', ['@count' => count($grouped['rsvp'])]),
      $this->t('Tickets: @count', ['@count' => count($grouped['ticket'])]),
      $this->t('Capacity: @capacity', [
        '@capacity' => $availability['capacity'] > 0 ? $availability['capacity'] : $this->t('Unlimited'),
      ]),
    ];

    if ($availability['remaining'] !== NULL) {
      $summaryItems[] = $this->t('Spots remaining: @remaining', ['@remaining' => $availability['remaining']]);
    }

    $build['summary']['stats'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Summary'),
      '#items' => $summaryItems,
    ];

    // Export links.
    $build['summary']['export'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['export-links']],
    ];

    $build['summary']['export']['csv'] = [
      '#type' => 'link',
      '#title' => $this->t('Export to CSV'),
      '#url' => Url::fromRoute('myeventlane_event_attendees.vendor_export', ['node' => $node->id()]),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $build['summary']['export']['csv_obfuscated'] = [
      '#type' => 'link',
      '#title' => $this->t('Export to CSV (Obfuscated Emails)'),
      '#url' => Url::fromRoute('myeventlane_event_attendees.vendor_export', ['node' => $node->id()], ['query' => ['obfuscate' => '1']]),
      '#attributes' => ['class' => ['button', 'button--secondary']],
    ];

    // RSVP attendees section.
    if (!empty($grouped['rsvp'])) {
      $build['rsvp_section'] = [
        '#type' => 'details',
        '#title' => $this->t('RSVP Attendees (@count)', ['@count' => count($grouped['rsvp'])]),
        '#open' => TRUE,
      ];

      $rsvpRows = [];
      foreach ($grouped['rsvp'] as $attendee) {
        $rsvpRows[] = [
          'name' => $attendee->getName(),
          'email' => $attendee->getEmail(),
          'status' => ucfirst($attendee->getStatus()),
          'checked_in' => $attendee->isCheckedIn() ? $this->t('Yes') : $this->t('No'),
          'created' => $this->dateFormatter->format($attendee->get('created')->value, 'short'),
        ];
      }

      $build['rsvp_section']['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Name'),
          $this->t('Email'),
          $this->t('Status'),
          $this->t('Checked in'),
          $this->t('Registered'),
        ],
        '#rows' => $rsvpRows,
        '#attributes' => ['class' => ['attendee-list', 'rsvp-list']],
      ];
    }

    // Ticket attendees grouped by ticket type.
    if (!empty($ticketTypeGroups)) {
      $build['ticket_section'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ticket-attendees']],
      ];

      $build['ticket_section']['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Ticket Attendees (@count)', ['@count' => count($grouped['ticket'])]),
      ];

      foreach ($ticketTypeGroups as $variationId => $group) {
        $build['ticket_section'][$variationId] = [
          '#type' => 'details',
          '#title' => $this->t('@type (@count)', [
            '@type' => $group['title'],
            '@count' => count($group['attendees']),
          ]),
          '#open' => TRUE,
        ];

        $ticketRows = [];
        foreach ($group['attendees'] as $attendee) {
          $ticketRows[] = [
            'name' => $attendee->getName(),
            'email' => $attendee->getEmail(),
            'ticket_code' => $attendee->getTicketCode() ?? '',
            'status' => ucfirst($attendee->getStatus()),
            'checked_in' => $attendee->isCheckedIn() ? $this->t('Yes') : $this->t('No'),
            'created' => $this->dateFormatter->format($attendee->get('created')->value, 'short'),
          ];
        }

        $build['ticket_section'][$variationId]['table'] = [
          '#type' => 'table',
          '#header' => [
            $this->t('Name'),
            $this->t('Email'),
            $this->t('Ticket Code'),
            $this->t('Status'),
            $this->t('Checked in'),
            $this->t('Registered'),
          ],
          '#rows' => $ticketRows,
          '#attributes' => ['class' => ['attendee-list', 'ticket-list']],
        ];
      }
    }

    // Manual attendees.
    if (!empty($grouped['manual'])) {
      $build['manual_section'] = [
        '#type' => 'details',
        '#title' => $this->t('Manual Entries (@count)', ['@count' => count($grouped['manual'])]),
        '#open' => FALSE,
      ];

      $manualRows = [];
      foreach ($grouped['manual'] as $attendee) {
        $manualRows[] = [
          'name' => $attendee->getName(),
          'email' => $attendee->getEmail(),
          'status' => ucfirst($attendee->getStatus()),
          'checked_in' => $attendee->isCheckedIn() ? $this->t('Yes') : $this->t('No'),
          'created' => $this->dateFormatter->format($attendee->get('created')->value, 'short'),
        ];
      }

      $build['manual_section']['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Name'),
          $this->t('Email'),
          $this->t('Status'),
          $this->t('Checked in'),
          $this->t('Registered'),
        ],
        '#rows' => $manualRows,
        '#attributes' => ['class' => ['attendee-list', 'manual-list']],
      ];
    }

    // Empty state.
    if (empty($attendees)) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No attendees yet. Share your event to start collecting RSVPs or ticket sales.') . '</p>',
      ];
    }

    $build['#cache'] = [
      'tags' => ['event_attendee_list:' . $node->id()],
      'contexts' => ['user'],
    ];

    return $build;
  }

  /**
   * Exports attendees as CSV with optional email obfuscation.
   *
   * IMPORTANT:
   * This export uses the attendee repository for unified data access.
   * If you need to add revenue, tickets sold, or RSVP counts to this export,
   * use VendorMetricsService to guarantee parity with the vendor dashboard.
   * Do not reimplement metrics here.
   */
  public function export(NodeInterface $node): Response {
    $request = \Drupal::request();
    $obfuscateEmails = (bool) $request->query->get('obfuscate', FALSE);

    // Use attendee repository for unified export.
    $repositoryResolver = \Drupal::service('myeventlane_attendee.repository_resolver');
    $repository = $repositoryResolver->getRepository($node);
    $attendees = $repository->loadByEvent($node);

    $filename = sprintf('attendees-%s-%s.csv',
      preg_replace('/[^a-z0-9]+/', '-', strtolower($node->label())),
      date('Y-m-d')
    );

    $response = new StreamedResponse(function () use ($attendees, $obfuscateEmails) {
      $handle = fopen('php://output', 'w');

      // Header row.
      fputcsv($handle, [
        'Name',
        'Email',
        'Source',
        'Ticket Type',
        'Ticket Code',
        'Checked In',
        'Checked In At',
      ]);

      // Data rows.
      foreach ($attendees as $attendee) {
        $row = $attendee->toExportRow();

        // Obfuscate email if requested.
        $email = $row['email'];
        if ($obfuscateEmails && $email) {
          $parts = explode('@', $email);
          if (count($parts) === 2) {
            $email = substr($parts[0], 0, 2) . '***@' . $parts[1];
          }
        }

        $checkedInAt = $row['checked_in_at'] ?? '';
        if ($checkedInAt && $row['checked_in']) {
          try {
            $dt = new \DateTime($checkedInAt);
            $checkedInAt = $dt->format('Y-m-d H:i:s');
          }
          catch (\Exception $e) {
            $checkedInAt = '';
          }
        }
        else {
          $checkedInAt = '';
        }

        fputcsv($handle, [
          $row['name'],
          $email,
          ucfirst($row['source']),
          $row['ticket_type'] ?? '',
          $row['ticket_code'] ?? '',
          $row['checked_in'] ? 'Yes' : 'No',
          $checkedInAt,
        ]);
      }

      fclose($handle);
    });

    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

  /**
   * Checks in an attendee (AJAX endpoint).
   */
  public function checkIn(EventAttendee $event_attendee): Response {
    $success = $this->attendanceManager->checkIn($event_attendee);

    if ($success) {
      return new Response(json_encode([
        'success' => TRUE,
        'message' => (string) $this->t('@name has been checked in.', ['@name' => $event_attendee->getName()]),
      ]), 200, ['Content-Type' => 'application/json']);
    }

    return new Response(json_encode([
      'success' => FALSE,
      'message' => (string) $this->t('@name is already checked in.', ['@name' => $event_attendee->getName()]),
    ]), 200, ['Content-Type' => 'application/json']);
  }

}
