<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\myeventlane_checkout_paragraph\CheckoutAttendeeSchemaInspectorInterface;
use Drupal\myeventlane_checkout_paragraph\Support\AttendeeHolderMissingEvaluator;

/**
 * Canonical answer for whether checkout requires visible attendee completion.
 *
 * Uses ticket-holder capture rules, attendee question templates, and stored holder
 * paragraphs only — never checkout route, pane visibility, or Commerce step ids.
 */
final class MelCheckoutAttendeeRequirementResolver {

  public function __construct(
    private readonly CheckoutAttendeeSchemaInspectorInterface $checkoutAttendeeSchema,
  ) {}

  /**
   * @return array{
   *   requires_attendee_details: bool,
   *   missing_required_fields: list<string>,
   *   required_count: int
   * }
   */
  public function resolve(OrderInterface $order): array {
    $requires = $this->checkoutAttendeeSchema->orderRequiresTicketHolderAttendeeCapture($order);
    $required_count = 0;
    foreach ($order->getItems() as $item) {
      if ($this->checkoutAttendeeSchema->shouldCollectTicketHolders($item)) {
        $required_count += max(0, (int) $item->getQuantity());
      }
    }

    $missing = $requires ? AttendeeHolderMissingEvaluator::missingSummariesForOrder($order, $this->checkoutAttendeeSchema) : [];

    return [
      'requires_attendee_details' => $requires,
      'missing_required_fields' => $missing,
      'required_count' => $required_count,
    ];
  }

  public function isAttendeeCompletionSatisfied(OrderInterface $order): bool {
    if (!$this->checkoutAttendeeSchema->orderRequiresTicketHolderAttendeeCapture($order)) {
      return TRUE;
    }
    return AttendeeHolderMissingEvaluator::missingSummariesForOrder($order, $this->checkoutAttendeeSchema) === [];
  }

}
