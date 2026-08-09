<?php

declare(strict_types=1);

namespace Drupal\mel_universal_ticket\Service;

use Drupal\myeventlane_tickets\Service\TicketCapabilityManager;

/**
 * Universal entitlement service entry point for ticket-backed capabilities.
 *
 * Compatibility only. New code must use TicketCapabilityManager and the
 * mel_ticket_capability.manager service instead.
 *
 * @see \Drupal\myeventlane_tickets\Service\TicketCapabilityManager
 */
final class UniversalTicketCapabilityManager extends TicketCapabilityManager {}
