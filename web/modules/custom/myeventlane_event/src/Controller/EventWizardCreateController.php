<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_legal\Service\LegalGatekeeper;
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
   * The legal gatekeeper.
   */
  private readonly LegalGatekeeper $legalGatekeeper;

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    EntityTypeManagerInterface $entity_type_manager,
    LegalGatekeeper $legal_gatekeeper,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
    $this->entityTypeManager = $entity_type_manager;
    $this->legalGatekeeper = $legal_gatekeeper;
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
      $container->get('myeventlane_legal.gatekeeper'),
    );
  }

  /**
   * Creates a draft event and redirects to basics step.
   */
  public function createDraft(): RedirectResponse {
    $this->assertVendorAccess();
    $this->assertStripeConnected();
    $this->legalGatekeeper->assertVendorTermsAccepted();

    $event = $this->getOrCreateDraftEvent();
    $url = Url::fromRoute('myeventlane_event.wizard.basics', ['event' => $event->id()]);
    return new RedirectResponse($url->toString());
  }

  /**
   * Redirects edit to basics step.
   */
  public function edit(NodeInterface $node): RedirectResponse {
    $this->assertEventOwnership($node);
    $this->ensureVendorAssigned($node);
    $url = Url::fromRoute('myeventlane_event.wizard.basics', ['event' => $node->id()]);
    return new RedirectResponse($url->toString());
  }

  /**
   * Ensures the event has field_event_vendor set for the current vendor.
   *
   * Existing drafts may have been created before vendor assignment was in
   * place, or the vendor's store may have been provisioned after the event
   * was created. Re-saving triggers EventVendorSubscriber which auto-
   * populates field_event_store from the vendor's field_vendor_store.
   */
  private function ensureVendorAssigned(NodeInterface $event): void {
    if (!$event->hasField('field_event_vendor')) {
      return;
    }

    $vendor = $this->getCurrentVendorOrNull();
    if (!$vendor) {
      return;
    }

    $current_vendor_id = $event->get('field_event_vendor')->target_id;
    if ($current_vendor_id === (string) $vendor->id()) {
      if (!$event->hasField('field_event_store') || !$event->get('field_event_store')->isEmpty()) {
        return;
      }
    }

    $event->set('field_event_vendor', $vendor);
    $event->save();
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
        $this->ensureVendorAssigned($event);
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
