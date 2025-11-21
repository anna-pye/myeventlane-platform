<?php

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\myeventlane_rsvp\Service\UserRsvpRepository;
use Drupal\myeventlane_rsvp\Service\RsvpPdfGenerator;

final class RsvpCheckinController extends ControllerBase {

  public function __construct(
    private readonly UserRsvpRepository $repo,
    private readonly RsvpPdfGenerator $pdfGen
  ) {}

  public static function create(ContainerInterface $c): self {
    return new self(
      $c->get('myeventlane_rsvp.user_rsvp_repository'),
      $c->get('myeventlane_rsvp.pdf_generator'),
    );
  }

  public function checkinPage(NodeInterface $event): array {
    $confirmed = $this->repo->getEventRsvpsByStatus($event->id(), 'confirmed');
    $waitlist = $this->repo->getEventRsvpsByStatus($event->id(), 'waitlist');

    return [
      '#theme' => 'myeventlane_rsvp_checkin',
      '#event' => $event,
      '#confirmed' => $confirmed,
      '#waitlist' => $waitlist,
      '#pdf_url' => $event->toUrl('canonical')->toString(),
      '#attached' => ['library' => ['myeventlane_rsvp/checkin']],
    ];
  }

  public function pdf(NodeInterface $event): Response {
    $confirmed = $this->repo->getEventRsvpsByStatus($event->id(), 'confirmed');
    $waitlist = $this->repo->getEventRsvpsByStatus($event->id(), 'waitlist');

    $html = $this->renderPlain([
      '#theme' => 'myeventlane_rsvp_checkin_pdf',
      '#event' => $event,
      '#confirmed' => $confirmed,
      '#waitlist' => $waitlist,
    ]);

    return $this->pdfGen->generate($html, 'RSVP-CheckIn.pdf');
  }

  public function getEventRsvpsByStatus(int $event_id, string $status): array {
	  return array_filter(
	    $this->getEventRsvps($event_id),
	    fn($r) => strtolower($r['status']) === strtolower($status)
	  );
	}
}