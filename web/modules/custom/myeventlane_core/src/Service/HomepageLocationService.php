<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserInterface;

/**
 * Service for detecting user location for homepage personalization.
 *
 * Soft detection priority:
 * 1. Logged-in user profile location (if exists)
 * 2. Last RSVP / ticketed event location
 * 3. Fallback: NULL (Australia-wide, no filtering)
 */
class HomepageLocationService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs a HomepageLocationService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * Detects user location for event filtering.
   *
   * @return array|null
   *   Location data with keys: 'locality', 'administrative_area', 'country_code'
   *   Returns NULL if no location detected (fallback to Australia-wide).
   */
  public function detectUserLocation(): ?array {
    // Only for logged-in users.
    if ($this->currentUser->isAnonymous()) {
      return NULL;
    }

    $user_id = (int) $this->currentUser->id();
    if ($user_id === 0) {
      return NULL;
    }

    // Priority 1: User profile location.
    $profile_location = $this->getUserProfileLocation($user_id);
    if ($profile_location) {
      return $profile_location;
    }

    // Priority 2: Last RSVP / ticketed event location.
    $last_event_location = $this->getLastEventLocation($user_id);
    if ($last_event_location) {
      return $last_event_location;
    }

    // Fallback: No location (Australia-wide, no filtering).
    return NULL;
  }

  /**
   * Gets location from user profile.
   *
   * @param int $user_id
   *   The user ID.
   *
   * @return array|null
   *   Location data or NULL.
   */
  protected function getUserProfileLocation(int $user_id): ?array {
    try {
      $user = $this->entityTypeManager->getStorage('user')->load($user_id);
      if (!$user instanceof UserInterface) {
        return NULL;
      }

      // Check for customer profile with address field.
      $profile_storage = $this->entityTypeManager->getStorage('profile');
      $profiles = $profile_storage->loadByProperties([
        'uid' => $user_id,
        'type' => 'customer',
      ]);

      foreach ($profiles as $profile) {
        if ($profile->hasField('address') && !$profile->get('address')->isEmpty()) {
          $address_item = $profile->get('address')->first();
          if ($address_item) {
            $address_value = $address_item->getValue();
            if (is_array($address_value) && !empty($address_value['locality'])) {
              return [
                'locality' => $address_value['locality'] ?? NULL,
                'administrative_area' => $address_value['administrative_area'] ?? NULL,
                'country_code' => $address_value['country_code'] ?? 'AU',
              ];
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      // Fail silently.
    }

    return NULL;
  }

  /**
   * Gets location from user's last RSVP or ticketed event.
   *
   * @param int $user_id
   *   The user ID.
   *
   * @return array|null
   *   Location data or NULL.
   */
  protected function getLastEventLocation(int $user_id): ?array {
    try {
      // Try RSVP first.
      $db = \Drupal::database();
      $rsvp = $db->select('myeventlane_rsvp', 'r')
        ->fields('r', ['event_nid'])
        ->condition('uid', $user_id)
        ->condition('status', 'active')
        ->orderBy('created', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchObject();

      if ($rsvp && isset($rsvp->event_nid)) {
        $location = $this->getEventLocation((int) $rsvp->event_nid);
        if ($location) {
          return $location;
        }
      }

      // Try ticket purchases (via order items).
      $query = $db->select('commerce_order_item', 'oi');
      $query->join('commerce_order', 'o', 'o.order_id = oi.order_id');
      $query->join('commerce_product_variation_field_data', 'pv', 'pv.variation_id = oi.purchased_entity');
      $query->join('commerce_product__field_event', 'pe', 'pe.entity_id = pv.product_id');
      $query->fields('pe', ['field_event_target_id'])
        ->condition('o.uid', $user_id)
        ->condition('o.state', 'completed')
        ->orderBy('o.completed', 'DESC')
        ->range(0, 1);

      $ticket = $query->execute()->fetchObject();
      if ($ticket && isset($ticket->field_event_target_id)) {
        $location = $this->getEventLocation((int) $ticket->field_event_target_id);
        if ($location) {
          return $location;
        }
      }
    }
    catch (\Exception $e) {
      // Fail silently.
    }

    return NULL;
  }

  /**
   * Gets location from an event node.
   *
   * @param int $nid
   *   The event node ID.
   *
   * @return array|null
   *   Location data or NULL.
   */
  protected function getEventLocation(int $nid): ?array {
    try {
      $event = $this->entityTypeManager->getStorage('node')->load($nid);
      if (!$event || !$event->hasField('field_location')) {
        return NULL;
      }

      if ($event->get('field_location')->isEmpty()) {
        return NULL;
      }

      $address_item = $event->get('field_location')->first();
      if ($address_item) {
        $address_value = $address_item->getValue();
        if (is_array($address_value) && !empty($address_value['locality'])) {
          return [
            'locality' => $address_value['locality'] ?? NULL,
            'administrative_area' => $address_value['administrative_area'] ?? NULL,
            'country_code' => $address_value['country_code'] ?? 'AU',
          ];
        }
      }
    }
    catch (\Exception $e) {
      // Fail silently.
    }

    return NULL;
  }

}
