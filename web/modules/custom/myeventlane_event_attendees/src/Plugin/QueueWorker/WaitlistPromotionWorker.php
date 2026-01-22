<?php

namespace Drupal\myeventlane_event_attendees\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_event_attendees\Service\AttendanceManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes waitlist promotion jobs.
 *
 * @QueueWorker(
 *   id = "myeventlane_waitlist_promotion",
 *   title = @Translation("MyEventLane waitlist promotion worker"),
 *   cron = {"time" = 10}
 * )
 */
class WaitlistPromotionWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected AttendanceManager $manager;

  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs WaitlistPromotionWorker.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    AttendanceManager $manager,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->manager = $manager;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_event_attendees.manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Process a single queue item.
   *
   * @param array $data
   *   Contains: event_id, attendee_id.
   */
  public function processItem($data) {
    $event = $this->entityTypeManager->getStorage('node')->load($data['event_id']);
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('event_attendee');
    $attendee = $storage->load($data['attendee_id']);

    if (!$attendee) {
      return;
    }

    // Promote the attendee via the manager.
    $this->manager->promoteAttendee($attendee);
  }

}
