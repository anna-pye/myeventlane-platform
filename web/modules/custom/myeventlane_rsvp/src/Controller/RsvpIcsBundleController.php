<?php

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Drupal\myeventlane_rsvp\Service\IcsBundleGenerator;

/**
 * Controller for generating multi-event RSVP ICS bundle.
 */
class RsvpIcsBundleController extends ControllerBase {

  /**
   * @var \Drupal\myeventlane_rsvp\Service\IcsBundleGenerator
   */
  protected $icsBundleGenerator;

  public function __construct(IcsBundleGenerator $generator) {
    $this->icsBundleGenerator = $generator;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('myeventlane_rsvp.ics_bundle')
    );
  }

  /**
   * Return an ICS file of all upcoming RSVPs for the logged-in user.
   */
  public function download() {
    $account = $this->currentUser();

    if ($account->isAnonymous()) {
      return new Response('Forbidden', 403);
    }

    $ics = $this->icsBundleGenerator->generateForUser($account);

    $response = new Response($ics);
    $response->headers->set('Content-Type', 'text/calendar; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="my-rsvps.ics"');

    return $response;
  }

}