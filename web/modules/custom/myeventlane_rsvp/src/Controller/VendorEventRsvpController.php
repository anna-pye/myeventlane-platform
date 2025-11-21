<?php

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_rsvp\Service\UserRsvpRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\NodeInterface;

final class VendorEventRsvpController extends ControllerBase {

  public function __construct(
    private readonly UserRsvpRepository $repo,
  ) {}

  public static function create(ContainerInterface $c): self {
    return new self(
      $c->get('myeventlane_rsvp.user_rsvp_repository'),
    );
  }

  public function view(NodeInterface $event): array {
    $event_id = (int) $event->id();

    $confirmed = $this->repo->getEventRsvpCount($event_id, 'confirmed');
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
          'cancel' => Url::fromRoute('myeventlane_rsvp.public_cancel', ['rsvp' => $row['id']]),
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