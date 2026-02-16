<?php

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_rsvp\Service\RsvpCapacityService;
use Drupal\myeventlane_rsvp\Service\UserRsvpRepository;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 *
 */
final class VendorEventRsvpController extends ControllerBase {

  public function __construct(
    private readonly UserRsvpRepository $repo,
    private readonly RsvpCapacityService $capacity,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $c): self {
    return new self(
      $c->get('myeventlane_rsvp.user_rsvp_repository'),
      $c->get('myeventlane_rsvp.capacity'),
    );
  }

  /**
   *
   */
  public function view(NodeInterface $event): array {
    $event_id = (int) $event->id();

    // Use canonical count source for confirmed.
    $confirmed = $this->capacity->countConfirmedRsvps($event_id);
    $waitlist = $this->repo->getEventRsvpCount($event_id, 'waitlist');

    $rows = $this->repo->getEventRsvps($event_id);

    $items = [];
    foreach ($rows as $row) {
      $items[] = [
        'name' => $row['first_name'] . ' ' . $row['last_name'],
        'email' => $row['email'],
        'status' => ucfirst($row['status']),
        'created' => date('Y-m-d H:i', $row['created']),
        'actions' => [
          'cancel' => Url::fromRoute('myeventlane_rsvp.cancel_confirm', ['rsvp_id' => $row['id']]),
          'promote' => Url::fromRoute('myeventlane_rsvp.admin_promote', ['rsvp' => $row['id']]),
        ],
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
