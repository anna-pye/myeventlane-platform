<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_checkout_flow\Service\MelAttendeeCheckinManager;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controllers for paragraph / QR check-in; main event check-in is myeventlane_checkin.
 */
final class VendorCheckInController extends ControllerBase {

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    private readonly MelAttendeeCheckinManager $checkinManager,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('myeventlane_checkout_flow.attendee_checkin_manager'),
    );
  }

  public function checkAccess(?NodeInterface $node = NULL): AccessResult {
    $account = $this->currentUser;

    if ($account->hasPermission('administer commerce_order') || $account->hasPermission('bypass node access')) {
      return AccessResult::allowed()->addCacheContexts(['user.permissions']);
    }

    if ($node) {
      if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
        $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
        $store = $vendorResolver->getStoreForUser($account);
        if ($store && $vendorResolver->vendorOwnsEvent($store, $node)) {
          return AccessResult::allowed()->addCacheContexts(['user']);
        }
      }
      return AccessResult::forbidden('You do not own this event.')->addCacheContexts(['user']);
    }

    if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      $store = $vendorResolver->getStoreForUser($account);
      if ($store) {
        return AccessResult::allowed()->addCacheContexts(['user']);
      }
    }

    return AccessResult::forbidden('Only vendors and administrators can access this page.')->addCacheContexts(['user']);
  }

  public function scanCheckIn(string $token, Request $request): Response {
    $tokenService = \Drupal::service('myeventlane_checkout_paragraph.checkin_token');
    $token_data = $tokenService->validateToken($token);

    if (!$token_data || !$token_data['valid']) {
      $this->messenger()->addError($this->t('Invalid or expired check-in token.'));
      return $this->redirect('<front>');
    }

    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    $paragraph = $paragraph_storage->load($token_data['paragraph_id']);

    if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'attendee_answer') {
      $this->messenger()->addError($this->t('Attendee not found.'));
      return $this->redirect('<front>');
    }

    $accessHandler = $this->entityTypeManager->getAccessControlHandler('paragraph');
    $access = $accessHandler->access($paragraph, 'update', $this->currentUser);
    if (!$access) {
      $this->messenger()->addError($this->t('Access denied.'));
      return $this->redirect('<front>');
    }

    $accessResolver = \Drupal::service('myeventlane_checkout_paragraph.access_resolver');
    $event = $accessResolver->getEvent($paragraph);
    if (!$event) {
      $this->messenger()->addError($this->t('Event not found.'));
      return $this->redirect('<front>');
    }

    if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      $store = $vendorResolver->getStoreForUser($this->currentUser);
      if (!$store || !$vendorResolver->vendorOwnsEvent($store, $event)) {
        $this->messenger()->addError($this->t('Access denied. You do not own this event.'));
        return $this->redirect('<front>');
      }
    }

    $result = $this->checkinManager->checkInForTicketParagraph(
      $paragraph,
      $event,
      $this->currentUser()->getAccount(),
      MelAttendeeCheckinManager::SOURCE_QR_SCAN,
    );

    if (!$result['success'] && $result['reason'] === 'forbidden') {
      $this->messenger()->addError($this->t('Access denied.'));
      return $this->redirect('<front>');
    }

    if ($result['success'] && !$result['transitioned'] && $result['reason'] === 'already_checked_in') {
      $this->messenger()->addWarning($this->t('This attendee is already checked in.'));
      return $this->redirect('myeventlane_checkin.page', ['node' => $event->id()], [], 302);
    }

    if (!$result['success']) {
      $this->messenger()->addError($this->t('Check-in could not be completed.'));
      return $this->redirect('myeventlane_checkin.page', ['node' => $event->id()], [], 302);
    }

    $this->messenger()->addStatus($this->t('Attendee checked in successfully via QR code.'));
    return $this->redirect('myeventlane_checkin.page', ['node' => $event->id()], [], 302);
  }

}
