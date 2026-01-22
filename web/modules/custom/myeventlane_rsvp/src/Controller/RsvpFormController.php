<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_rsvp\Service\UserRsvpRepository;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for RSVP form and user RSVP listing.
 */
final class RsvpFormController extends ControllerBase {

  /**
   * Constructs RsvpFormController.
   */
  public function __construct(
    private readonly UserRsvpRepository $rsvpRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_rsvp.user_rsvp_repository'),
    );
  }

  /**
   * Builds RSVP form page.
   */
  public function form($node): array {
    $form = $this->formBuilder()->getForm('\Drupal\myeventlane_rsvp\Form\RsvpPublicForm', $node);

    return [
      '#theme' => 'rsvp_page_wrapper',
      '#form' => $form,
      '#node' => $node,
    ];
  }

  /**
   * Displays a user's RSVP submissions.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user whose RSVPs to display.
   *
   * @return array
   *   A render array.
   */
  public function userList(UserInterface $user): array {
    $currentUser = $this->currentUser();

    // Users can only view their own RSVPs unless they're admin.
    if ((int) $user->id() !== (int) $currentUser->id() && !$currentUser->hasPermission('administer rsvps')) {
      throw new AccessDeniedHttpException();
    }

    $rsvps = $this->rsvpRepository->loadByUser($user);

    $rows = [];
    $nodeStorage = $this->entityTypeManager()->getStorage('node');

    foreach ($rsvps as $rsvp) {
      $nid = isset($rsvp->event_id) ? (int) $rsvp->event_id : 0;
      $event = ($nid > 0) ? $nodeStorage->load($nid) : NULL;
      $rows[] = [
        'event' => $event ? $event->toLink()->toString() : $this->t('(Event deleted)'),
        'status' => ucfirst($rsvp->status ?? 'confirmed'),
        'date' => date('M j, Y', (int) ($rsvp->created ?? time())),
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Event'),
        $this->t('Status'),
        $this->t('Date'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('You have not RSVPed to any events yet.'),
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['rsvp_submission_list'],
      ],
    ];
  }

}
