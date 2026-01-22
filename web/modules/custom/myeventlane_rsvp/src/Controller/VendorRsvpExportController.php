<?php

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Drupal\myeventlane_rsvp\Service\UserRsvpRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 *
 */
final class VendorRsvpExportController extends ControllerBase {

  public function __construct(
    private readonly UserRsvpRepository $repo,
  ) {}

  /**
   *
   */
  public static function create(ContainerInterface $c): self {
    return new self($c->get('myeventlane_rsvp.user_rsvp_repository'));
  }

  /**
   *
   */
  public function export(int $event): Response {
    $rows = $this->repo->getEventRsvps($event);

    $csv = "First Name,Last Name,Email,Status,Created\n";

    foreach ($rows as $r) {
      $csv .= sprintf(
        "%s,%s,%s,%s,%s\n",
        $r['first_name'],
        $r['last_name'],
        $r['email'],
        $r['status'],
        date('Y-m-d H:i', $r['created'])
      );
    }

    return new Response(
      $csv,
      200,
      [
        'Content-Type' => 'text/csv',
        'Content-Disposition' => 'attachment; filename="rsvps.csv"',
      ]
    );
  }

}
