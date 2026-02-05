<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\node\NodeInterface;

/**
 * Interface for resolving the current vendor from various contexts.
 *
 * Canonical resolution order for context array:
 * 1. vendor_id (int) - explicit vendor ID
 * 2. vendor (Vendor entity) - explicit vendor entity
 * 3. event_id (int) - load event, get field_event_vendor
 * 4. event (NodeInterface) - get field_event_vendor
 * 5. order (Commerce Order) - resolve via store/event
 */
interface CurrentVendorResolverInterface {

  /**
   * Resolves vendor from a user account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  public function resolveFromUser(AccountInterface $account): ?Vendor;

  /**
   * Resolves vendor from the current user.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  public function resolveFromCurrentUser(): ?Vendor;

  /**
   * Resolves vendor from an event node.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  public function resolveFromEvent(NodeInterface $event): ?Vendor;

  /**
   * Resolves vendor from an event ID.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  public function resolveFromEventId(int $eventId): ?Vendor;

  /**
   * Resolves vendor from a context array using canonical resolution order.
   *
   * Resolution order:
   * 1. vendor_id (int) - explicit vendor ID
   * 2. vendor (Vendor entity) - explicit vendor entity
   * 3. event_id (int) - load event, get field_event_vendor
   * 4. event (NodeInterface) - get field_event_vendor
   * 5. order (Commerce Order) - resolve via store/event
   *
   * @param array $context
   *   Context array with optional keys: vendor_id, vendor, event_id, event, order.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  public function resolveFromContext(array $context): ?Vendor;

}
