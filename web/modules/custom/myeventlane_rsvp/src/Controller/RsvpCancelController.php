<?php

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Handles RSVP cancellation.
 */
class RsvpCancelController extends ControllerBase {

  /**
   * The queue name for vendor digest.
   */
  private const QUEUE_NAME = 'mel_rsvp_vendor_digest';

  /**
   * Queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * DI constructor.
   */
  public function __construct(QueueFactory $queue_factory) {
    $this->queueFactory = $queue_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('queue')
    );
  }

  /**
   * Process RSVP cancellation.
   */
  public function cancel($myeventlane_rsvp_submission): RedirectResponse {
    $submissionId = is_numeric($myeventlane_rsvp_submission) ? (int) $myeventlane_rsvp_submission : NULL;

    // Add an item to the digest queue.
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $queue->createItem([
      'action' => 'rsvp_cancelled',
      'id' => $myeventlane_rsvp_submission,
    ]);

    $this->getLogger('myeventlane_rsvp')->info('RSVP cancellation queued for vendor digest.', [
      'queue_name' => self::QUEUE_NAME,
      'submission_id' => $submissionId,
    ]);

    // Messenger via ControllerBase method.
    $this->messenger()->addStatus('Your RSVP has been cancelled.');

    // Redirect to front.
    $url = Url::fromRoute('<front>');
    return new RedirectResponse($url->toString());
  }

}
