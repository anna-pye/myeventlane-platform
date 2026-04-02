<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_rsvp\Service\UserRsvpRepository;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Vendor RSVP list for an event (legacy + Commerce RSVP attendees).
 */
final class VendorEventRsvpController extends ControllerBase {

  public function __construct(
    private readonly UserRsvpRepository $repo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $c): self {
    return new self(
      $c->get('myeventlane_rsvp.user_rsvp_repository'),
    );
  }

  /**
   * RSVP dashboard for vendors.
   */
  public function view(NodeInterface $event): array {
    $event_id = (int) $event->id();

    $confirmed = $this->repo->countTotalConfirmedRsvpsForEvent($event_id);
    $waitlist = $this->repo->getEventRsvpCount($event_id, 'waitlist')
      + $this->repo->countCommerceRsvpAttendeesByStatus($event_id, EventAttendee::STATUS_WAITLIST);

    $rows = $this->repo->getEventRsvps($event_id);

    $items = [];
    foreach ($rows as $row) {
      $actions = [];
      if (($row['kind'] ?? 'legacy') === 'legacy') {
        $actions['cancel'] = Url::fromRoute('myeventlane_rsvp.cancel_confirm', ['rsvp_id' => $row['id']]);
        $actions['promote'] = Url::fromRoute('myeventlane_rsvp.admin_promote', ['rsvp' => $row['id']]);
      }

      $items[] = [
        'name' => $row['first_name'] . ' ' . $row['last_name'],
        'email' => $row['email'],
        'status' => ucfirst((string) $row['status']),
        'quantity' => (int) ($row['quantity'] ?? 1),
        'created' => date('Y-m-d H:i', (int) $row['created']),
        'updated' => date('Y-m-d H:i', (int) ($row['updated'] ?? $row['created'])),
        'is_updated' => !empty($row['is_updated']),
        'actions' => $actions,
      ];
    }

    return [
      '#theme' => 'myeventlane_vendor_rsvp_dashboard',
      '#event_title' => $event->label(),
      '#confirmed' => $confirmed,
      '#waitlist' => $waitlist,
      '#items' => $items,
      '#csv_url' => Url::fromRoute('myeventlane_rsvp.export_csv', ['event' => $event_id])->toString(),
    ];
  }

}
