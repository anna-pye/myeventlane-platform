<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Component\Datetime\Time;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityStudioManager;
use Drupal\myeventlane_tickets\Service\DeviceOperationIdentityManager;
use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager;
use Drupal\myeventlane_tickets\Service\InventoryReservationGovernanceManager;
use Drupal\myeventlane_tickets\Service\OccupancyPolicyManager;
use Drupal\myeventlane_tickets\Service\OperationalContinuityPolicyManager;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\myeventlane_tickets\Service\SessionEntitlementPolicyManager;
use Drupal\myeventlane_tickets\Service\TicketCapabilityManager;
use Drupal\myeventlane_tickets\Service\TimedEntryPolicyManager;
use Drupal\myeventlane_tickets\Service\VenueOperationPolicyManager;
use Drupal\myeventlane_tickets\Service\ZoneAccessPolicyManager;

/**
 * Builds a real policy stack for Event Studio capability studio unit tests.
 */
final class OperationalCapabilityStudioTestFactory {

  public static function buildManager(?LoggerChannelInterface $logger = NULL): OperationalCapabilityStudioManager {
    $logger ??= new TestLoggerChannel();
    $registry = new EntitlementCapabilityRegistry();
    $time = new Time();
    $ticket_capability = new TicketCapabilityManager($time, $registry);
    $timed_entry = new TimedEntryPolicyManager();
    $session = new SessionEntitlementPolicyManager($registry, $ticket_capability);
    $zone = new ZoneAccessPolicyManager($registry, $ticket_capability, $timed_entry, $session, $time);
    $device = new DeviceOperationIdentityManager(
      $timed_entry,
      $session,
      $zone,
      $registry,
      $ticket_capability,
      $time,
    );
    $continuity = new OperationalContinuityPolicyManager(
      $device,
      $zone,
      $session,
      $timed_entry,
      $registry,
      $ticket_capability,
      $time,
    );
    $occupancy = new OccupancyPolicyManager(
      $continuity,
      $device,
      $zone,
      $session,
      $timed_entry,
      $registry,
      $ticket_capability,
      $time,
    );
    $venue = new VenueOperationPolicyManager(
      $registry,
      $ticket_capability,
      $time,
      $timed_entry,
      $session,
      $zone,
      $device,
      $continuity,
      $occupancy,
    );
    $reservation = new InventoryReservationGovernanceManager($registry, $logger);
    $capability = new OperationalEntitlementCapabilityManager($registry, $reservation, $logger);
    $fulfillment = new FulfillmentLifecycleManager($registry);

    return new OperationalCapabilityStudioManager(
      $registry,
      $venue,
      $capability,
      $fulfillment,
      $reservation,
      $logger,
    );
  }

}
