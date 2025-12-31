<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Controller;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for vendor check-in functionality.
 */
final class VendorCheckInController extends ControllerBase {

  /**
   * Constructs VendorCheckInController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * Access callback for check-in pages.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   The event node (optional).
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function checkAccess(?NodeInterface $node = NULL): AccessResult {
    $account = $this->currentUser;

    // Admin users always allowed.
    if ($account->hasPermission('administer commerce_order') || $account->hasPermission('bypass node access')) {
      return AccessResult::allowed()->addCacheContexts(['user.permissions']);
    }

    // If event provided, verify vendor owns it.
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

    // Check if user is a vendor.
    if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      $store = $vendorResolver->getStoreForUser($account);
      if ($store) {
        return AccessResult::allowed()->addCacheContexts(['user']);
      }
    }

    return AccessResult::forbidden('Only vendors and administrators can access this page.')->addCacheContexts(['user']);
  }

  /**
   * Renders the check-in page for an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array
   *   A render array for the check-in page.
   */
  public function checkInPage(NodeInterface $node, Request $request): array {
    // Verify vendor owns this event (access callback handles this, but double-check).
    $account = $this->currentUser;
    $store = NULL;

    if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      $store = $vendorResolver->getStoreForUser($account);
      if ($store && !$vendorResolver->vendorOwnsEvent($store, $node)) {
        return [
          '#markup' => $this->t('Access denied. You do not own this event.'),
          '#cache' => ['contexts' => ['user']],
        ];
      }
    }

    // Get search query.
    $search = $request->query->get('search', '');

    // Load attendees for this event.
    $attendees = $this->getEventAttendees($node, $search);

    return [
      '#theme' => 'myeventlane_vendor_checkin',
      '#title' => $this->t('Check In - @event', ['@event' => $node->label()]),
      '#event' => $node,
      '#attendees' => $attendees,
      '#search' => $search,
      '#cache' => [
        'contexts' => ['user', 'url.query_args'],
        'tags' => ['node:' . $node->id()],
      ],
    ];
  }

  /**
   * Handles check-in action (AJAX or form submit).
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The attendee paragraph.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\Response
   *   JSON response for AJAX, or redirect for form submit.
   */
  public function checkIn(ParagraphInterface $paragraph, Request $request): JsonResponse|Response {
    // Verify CSRF token.
    $token = $request->request->get('form_token');
    if (!$this->csrfToken->validate($token, 'check-in-action')) {
      if ($request->isXmlHttpRequest()) {
        return new JsonResponse(['error' => 'Invalid security token.'], 403);
      }
      $this->messenger()->addError($this->t('Invalid security token.'));
      return $this->redirect('<front>');
    }
    // Verify paragraph is attendee_answer.
    if ($paragraph->bundle() !== 'attendee_answer') {
      return new JsonResponse(['error' => 'Invalid paragraph type.'], 400);
    }

    // Verify vendor has access to this paragraph (via entity access).
    $accessHandler = $this->entityTypeManager->getAccessControlHandler('paragraph');
    $access = $accessHandler->access($paragraph, 'update', $this->currentUser);
    if (!$access) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    // Get event from paragraph.
    $accessResolver = \Drupal::service('myeventlane_checkout_paragraph.access_resolver');
    $event = $accessResolver->getEvent($paragraph);
    if (!$event) {
      return new JsonResponse(['error' => 'Event not found.'], 404);
    }

    // Verify vendor owns event.
    if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      $store = $vendorResolver->getStoreForUser($this->currentUser);
      if (!$store || !$vendorResolver->vendorOwnsEvent($store, $event)) {
        return new JsonResponse(['error' => 'Access denied. You do not own this event.'], 403);
      }
    }

    // Toggle check-in status.
    $is_checked_in = $paragraph->hasField('field_checked_in') && !$paragraph->get('field_checked_in')->isEmpty()
      ? (bool) $paragraph->get('field_checked_in')->value : FALSE;

    $new_status = !$is_checked_in;

    // Update paragraph.
    if ($paragraph->hasField('field_checked_in')) {
      $paragraph->set('field_checked_in', $new_status ? 1 : 0);
    }

    if ($new_status) {
      // Set timestamp and user.
      if ($paragraph->hasField('field_checked_in_timestamp')) {
        $paragraph->set('field_checked_in_timestamp', time());
      }
      if ($paragraph->hasField('field_checked_in_by')) {
        $paragraph->set('field_checked_in_by', $this->currentUser->id());
      }
    }
    else {
      // Clear timestamp and user when undoing.
      if ($paragraph->hasField('field_checked_in_timestamp')) {
        $paragraph->set('field_checked_in_timestamp', NULL);
      }
      if ($paragraph->hasField('field_checked_in_by')) {
        $paragraph->set('field_checked_in_by', NULL);
      }
    }

    $paragraph->save();

    // Handle AJAX request.
    if ($request->isXmlHttpRequest()) {
      return new JsonResponse([
        'success' => TRUE,
        'checked_in' => $new_status,
        'timestamp' => $new_status ? time() : NULL,
        'message' => $new_status
          ? $this->t('Attendee checked in successfully.')
          : $this->t('Check-in undone.'),
      ]);
    }

    // Form submit: redirect back to check-in page.
    $this->messenger()->addStatus(
      $new_status
        ? $this->t('Attendee checked in successfully.')
        : $this->t('Check-in undone.')
    );

    return $this->redirect('myeventlane_checkout_flow.vendor_checkin', ['node' => $event->id()]);
  }

  /**
   * Handles QR code scan check-in.
   *
   * @param string $token
   *   The signed token.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Redirect response with status message.
   */
  public function scanCheckIn(string $token, Request $request): Response {
    // Validate token.
    $tokenService = \Drupal::service('myeventlane_checkout_paragraph.checkin_token');
    $token_data = $tokenService->validateToken($token);

    if (!$token_data || !$token_data['valid']) {
      $this->messenger()->addError($this->t('Invalid or expired check-in token.'));
      return $this->redirect('<front>');
    }

    // Load paragraph.
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    $paragraph = $paragraph_storage->load($token_data['paragraph_id']);

    if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'attendee_answer') {
      $this->messenger()->addError($this->t('Attendee not found.'));
      return $this->redirect('<front>');
    }

    // Verify vendor has access.
    $accessHandler = $this->entityTypeManager->getAccessControlHandler('paragraph');
    $access = $accessHandler->access($paragraph, 'update', $this->currentUser);
    if (!$access) {
      $this->messenger()->addError($this->t('Access denied.'));
      return $this->redirect('<front>');
    }

    // Get event.
    $accessResolver = \Drupal::service('myeventlane_checkout_paragraph.access_resolver');
    $event = $accessResolver->getEvent($paragraph);
    if (!$event) {
      $this->messenger()->addError($this->t('Event not found.'));
      return $this->redirect('<front>');
    }

    // Verify vendor owns event.
    if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      $store = $vendorResolver->getStoreForUser($this->currentUser);
      if (!$store || !$vendorResolver->vendorOwnsEvent($store, $event)) {
        $this->messenger()->addError($this->t('Access denied. You do not own this event.'));
        return $this->redirect('<front>');
      }
    }

    // Check if already checked in.
    $is_checked_in = $paragraph->hasField('field_checked_in') && !$paragraph->get('field_checked_in')->isEmpty()
      ? (bool) $paragraph->get('field_checked_in')->value : FALSE;

    if ($is_checked_in) {
      $this->messenger()->addWarning($this->t('This attendee is already checked in.'));
      return $this->redirect('myeventlane_checkout_flow.vendor_checkin', ['node' => $event->id()]);
    }

    // Check in.
    if ($paragraph->hasField('field_checked_in')) {
      $paragraph->set('field_checked_in', 1);
    }
    if ($paragraph->hasField('field_checked_in_timestamp')) {
      $paragraph->set('field_checked_in_timestamp', time());
    }
    if ($paragraph->hasField('field_checked_in_by')) {
      $paragraph->set('field_checked_in_by', $this->currentUser->id());
    }

    $paragraph->save();

    $this->messenger()->addStatus($this->t('Attendee checked in successfully via QR code.'));
    return $this->redirect('myeventlane_checkout_flow.vendor_checkin', ['node' => $event->id()]);
  }

  /**
   * Gets attendees for an event with optional search.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $search
   *   Search query (name or email).
   *
   * @return array
   *   Array of attendee data.
   */
  private function getEventAttendees(NodeInterface $event, string $search = ''): array {
    $attendees = [];
    $eventId = (int) $event->id();

    // Load order items for this event.
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderItemIds = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_target_event', $eventId)
      ->execute();

    if (empty($orderItemIds)) {
      return $attendees;
    }

    $orderItems = $orderItemStorage->loadMultiple($orderItemIds);
    $accessHandler = $this->entityTypeManager->getAccessControlHandler('paragraph');
    $tokenService = \Drupal::service('myeventlane_checkout_paragraph.checkin_token');

    foreach ($orderItems as $orderItem) {
      if (!$orderItem instanceof OrderItemInterface) {
        continue;
      }

      // Get order.
      try {
        $order = $orderItem->getOrder();
        if (!$order || $order->getState()->getId() !== 'completed') {
          continue;
        }
      }
      catch (\Exception $e) {
        continue;
      }

      // Get ticket type.
      $ticketType = $orderItem->getTitle();

      // Get attendees from paragraphs.
      if ($orderItem->hasField('field_ticket_holder') && !$orderItem->get('field_ticket_holder')->isEmpty()) {
        foreach ($orderItem->get('field_ticket_holder')->referencedEntities() as $paragraph) {
          if (!$paragraph instanceof ParagraphInterface) {
            continue;
          }

          // Check entity access.
          $access = $accessHandler->access($paragraph, 'view', $this->currentUser);
          if (!$access) {
            continue;
          }

          $first_name = $paragraph->hasField('field_first_name') && !$paragraph->get('field_first_name')->isEmpty()
            ? $paragraph->get('field_first_name')->value : '';
          $last_name = $paragraph->hasField('field_last_name') && !$paragraph->get('field_last_name')->isEmpty()
            ? $paragraph->get('field_last_name')->value : '';
          $email = $paragraph->hasField('field_email') && !$paragraph->get('field_email')->isEmpty()
            ? $paragraph->get('field_email')->value : '';
          $name = trim($first_name . ' ' . $last_name);

          // Apply search filter.
          if (!empty($search)) {
            $search_lower = strtolower($search);
            $name_lower = strtolower($name);
            $email_lower = strtolower($email);
            if (strpos($name_lower, $search_lower) === FALSE && strpos($email_lower, $search_lower) === FALSE) {
              continue;
            }
          }

          // Get check-in status.
          $checked_in = FALSE;
          $checked_in_timestamp = NULL;
          $checked_in_by = NULL;

          if ($paragraph->hasField('field_checked_in') && !$paragraph->get('field_checked_in')->isEmpty()) {
            $checked_in = (bool) $paragraph->get('field_checked_in')->value;
          }
          if ($checked_in && $paragraph->hasField('field_checked_in_timestamp') && !$paragraph->get('field_checked_in_timestamp')->isEmpty()) {
            $checked_in_timestamp = (int) $paragraph->get('field_checked_in_timestamp')->value;
          }
          if ($checked_in && $paragraph->hasField('field_checked_in_by') && !$paragraph->get('field_checked_in_by')->isEmpty()) {
            $checked_in_by = $paragraph->get('field_checked_in_by')->target_id;
          }

          // Generate QR token.
          $qr_token = $tokenService->generateToken($paragraph);

          $attendees[] = [
            'paragraph_id' => $paragraph->id(),
            'name' => $name,
            'email' => $email,
            'ticket_type' => $ticketType,
            'order_number' => $order->getOrderNumber(),
            'checked_in' => $checked_in,
            'checked_in_timestamp' => $checked_in_timestamp,
            'checked_in_by' => $checked_in_by,
            'qr_token' => $qr_token,
          ];
        }
      }
    }

    // Sort by name.
    usort($attendees, function ($a, $b) {
      return strcasecmp($a['name'], $b['name']);
    });

    return $attendees;
  }

}

