<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\myeventlane_tickets\Entity\RedemptionLog;
use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Canonical operational capability policy for ticket-backed entitlements.
 *
 * Pure policy authority: no scanners, PDFs, wallets, render systems, or
 * notifications. Callers derive scan, redemption, fulfilment, and expiry
 * semantics exclusively from this registry.
 */
final class EntitlementCapabilityRegistry {

  /**
   * Machine-only capability maps keyed by normalized entitlement type.
   *
   * @var array<string, array<string, mixed>>
   */
  private const CAPABILITIES = [
    Ticket::ENTITLEMENT_TICKET => [
      'entitlement_type' => Ticket::ENTITLEMENT_TICKET,
      'scan_required' => TRUE,
      'redeemable' => FALSE,
      'multi_use' => FALSE,
      'requires_fulfilment' => FALSE,
      'supports_collection' => FALSE,
      'transferable' => TRUE,
      'expires' => TRUE,
      'scanner_mode' => RedemptionLog::ACTION_ADMIT,
      'fulfilment_mode' => 'none',
    ],
    Ticket::ENTITLEMENT_MERCH => [
      'entitlement_type' => Ticket::ENTITLEMENT_MERCH,
      'scan_required' => TRUE,
      'redeemable' => TRUE,
      'multi_use' => FALSE,
      'requires_fulfilment' => TRUE,
      'supports_collection' => TRUE,
      'transferable' => TRUE,
      'expires' => TRUE,
      'scanner_mode' => RedemptionLog::ACTION_COLLECT,
      'fulfilment_mode' => 'collect',
    ],
    Ticket::ENTITLEMENT_PARKING => [
      'entitlement_type' => Ticket::ENTITLEMENT_PARKING,
      'scan_required' => TRUE,
      'redeemable' => FALSE,
      'multi_use' => FALSE,
      'requires_fulfilment' => FALSE,
      'supports_collection' => FALSE,
      'transferable' => TRUE,
      'expires' => TRUE,
      'scanner_mode' => RedemptionLog::ACTION_VALIDATE,
      'fulfilment_mode' => 'none',
    ],
    Ticket::ENTITLEMENT_DRINK => [
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'scan_required' => TRUE,
      'redeemable' => TRUE,
      'multi_use' => TRUE,
      'requires_fulfilment' => FALSE,
      'supports_collection' => FALSE,
      'transferable' => TRUE,
      'expires' => TRUE,
      'scanner_mode' => RedemptionLog::ACTION_REDEEM,
      'fulfilment_mode' => 'redeem',
    ],
    Ticket::ENTITLEMENT_FOOD => [
      'entitlement_type' => Ticket::ENTITLEMENT_FOOD,
      'scan_required' => TRUE,
      'redeemable' => TRUE,
      'multi_use' => TRUE,
      'requires_fulfilment' => FALSE,
      'supports_collection' => FALSE,
      'transferable' => TRUE,
      'expires' => TRUE,
      'scanner_mode' => RedemptionLog::ACTION_REDEEM,
      'fulfilment_mode' => 'redeem',
    ],
    Ticket::ENTITLEMENT_VIP => [
      'entitlement_type' => Ticket::ENTITLEMENT_VIP,
      'scan_required' => TRUE,
      'redeemable' => FALSE,
      'multi_use' => FALSE,
      'requires_fulfilment' => FALSE,
      'supports_collection' => FALSE,
      'transferable' => TRUE,
      'expires' => TRUE,
      'scanner_mode' => RedemptionLog::ACTION_VERIFY,
      'fulfilment_mode' => 'optional',
    ],
    Ticket::ENTITLEMENT_ADDON => [
      'entitlement_type' => Ticket::ENTITLEMENT_ADDON,
      'scan_required' => TRUE,
      'redeemable' => TRUE,
      'multi_use' => TRUE,
      'requires_fulfilment' => TRUE,
      'supports_collection' => FALSE,
      'transferable' => TRUE,
      'expires' => TRUE,
      'scanner_mode' => RedemptionLog::ACTION_REDEEM,
      'fulfilment_mode' => 'redeem',
    ],
  ];

  /**
   * Normalizes a stored entitlement type string to a supported policy key.
   */
  public function normalizeEntitlementType(string $stored): string {
    $trimmed = trim($stored);
    if ($trimmed === '') {
      return Ticket::ENTITLEMENT_TICKET;
    }
    return isset(self::CAPABILITIES[$trimmed]) ? $trimmed : Ticket::ENTITLEMENT_TICKET;
  }

  /**
   * Returns the immutable capability map for one entitlement type.
   *
   * @return array<string, mixed>
   *   Machine-only capability metadata.
   */
  public function getCapabilityMap(string $normalized_entitlement_type): array {
    $type = $this->normalizeEntitlementType($normalized_entitlement_type);
    return self::CAPABILITIES[$type];
  }

  /**
   * Returns all entitlement capability maps keyed by entitlement type.
   *
   * @return array<string, array<string, mixed>>
   */
  public function getAllCapabilityMaps(): array {
    return self::CAPABILITIES;
  }

  /**
   * Redemption-log action token used by the scanner spine for this type.
   */
  public function getScannerOperationAction(string $normalized_entitlement_type): string {
    $map = $this->getCapabilityMap($normalized_entitlement_type);
    return (string) $map['scanner_mode'];
  }

  /**
   * TRUE when the entitlement uses admission (check-in) scanner semantics.
   */
  public function isAdmissionEntitlementType(string $normalized_entitlement_type): bool {
    return $this->getScannerOperationAction($normalized_entitlement_type) === RedemptionLog::ACTION_ADMIT;
  }

  /**
   * TRUE when a successful scan increments operational redemption_count.
   */
  public function consumesRedemptionCount(string $normalized_entitlement_type): bool {
    return (bool) $this->getCapabilityMap($normalized_entitlement_type)['redeemable'];
  }

  /**
   * TRUE when fulfilment workflow state is part of operational policy.
   */
  public function requiresFulfilmentWorkflow(string $normalized_entitlement_type): bool {
    return (bool) $this->getCapabilityMap($normalized_entitlement_type)['requires_fulfilment'];
  }

}
