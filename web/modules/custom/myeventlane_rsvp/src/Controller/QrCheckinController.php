<?php

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\myeventlane_rsvp\Service\UserRsvpRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

final class QrCheckinController extends ControllerBase {

  public function __construct(
    private readonly UserRsvpRepository $repo,
    private readonly EntityTypeManagerInterface $em,
    private readonly ConfigFactoryInterface $config
  ) {}

  public static function create(ContainerInterface $c): self {
    return new self(
      $c->get('myeventlane_rsvp.user_rsvp_repository'),
      $c->get('entity_type.manager'),
      $c->get('config.factory'),
    );
  }

  public function scanPage($event): array {
    return [
      '#theme' => 'myeventlane_rsvp_qr_scan',
      '#event_id' => $event,
      '#attached' => [
        'library' => [
          'myeventlane_rsvp/qrscan',
        ],
      ],
    ];
  }

  public function validate(Request $req): JsonResponse {
    $data = json_decode($req->getContent(), TRUE);
    $code = $data['code'] ?? '';

    // Format: mel:rsvp:ID:HASH
    if (!preg_match('/^mel:rsvp:(\d+):([a-f0-9]{64})$/', $code, $m)) {
      return new JsonResponse(['status' => 'invalid', 'message' => 'Bad QR format']);
    }

    $rsvp_id = (int) $m[1];
    $hash = $m[2];

    $rsvp = $this->em->getStorage('rsvp_submission')->load($rsvp_id);
    if (!$rsvp) {
      return new JsonResponse(['status' => 'invalid', 'message' => 'RSVP not found']);
    }

    $event_id = $rsvp->get('field_event')->target_id;
    $secret = $this->config->get('myeventlane_rsvp.settings')->get('private_qr_key');

    $expected = hash('sha256', $event_id . ':' . $rsvp_id . ':' . $secret);
    if ($hash !== $expected) {
      return new JsonResponse(['status' => 'invalid', 'message' => 'Invalid QR']);
    }

    if ($rsvp->get('field_checked_in')->value) {
      return new JsonResponse([
        'status' => 'repeat',
        'message' => 'Already checked in',
      ]);
    }

    // Mark as checked in
    $rsvp->set('field_checked_in', 1);
    $rsvp->save();

    return new JsonResponse([
      'status' => 'success',
      'message' => 'Checked in',
      'name' => $rsvp->get('field_first_name')->value . ' ' . $rsvp->get('field_last_name')->value,
    ]);
  }
}