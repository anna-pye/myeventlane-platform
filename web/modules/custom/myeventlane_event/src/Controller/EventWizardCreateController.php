<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for event wizard create/edit entry points.
 *
 * Creates draft event and redirects to basics step.
 */
final class EventWizardCreateController extends VendorConsoleBaseController implements ContainerInjectionInterface {


  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Creates a draft event and redirects to basics step.
   */
  public function createDraft(): RedirectResponse {
    $this->assertVendorAccess();
    $this->assertStripeConnected();

    $event = $this->getOrCreateDraftEvent();
    $url = Url::fromRoute('myeventlane_event.wizard.basics', ['event' => $event->id()]);
    return new RedirectResponse($url->toString());
  }

  /**
   * Redirects edit to basics step.
   */
  public function edit(NodeInterface $node): RedirectResponse {
    $this->assertEventOwnership($node);
    $url = Url::fromRoute('myeventlane_event.wizard.basics', ['event' => $node->id()]);
    return new RedirectResponse($url->toString());
  }

  /**
   * Gets or creates a draft event for the current user.
   */
  private function getOrCreateDraftEvent(): NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $uid = (int) $this->currentUser->id();

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('uid', $uid)
      ->condition('status', 0)
      ->sort('created', 'DESC')
      ->range(0, 1);

    $ids = $query->execute();
    if (!empty($ids)) {
      $event = $storage->load(reset($ids));
      if ($event instanceof NodeInterface) {
        return $event;
      }
    }

    $event = $storage->create([
      'type' => 'event',
      'title' => 'New Event',
      'status' => 0,
      'uid' => $uid,
    ]);

    $vendor = $this->getCurrentVendorOrNull();
    if ($vendor && $event->hasField('field_event_vendor')) {
      $event->set('field_event_vendor', $vendor);
    }

    $event->save();
    return $event;
  }

}
