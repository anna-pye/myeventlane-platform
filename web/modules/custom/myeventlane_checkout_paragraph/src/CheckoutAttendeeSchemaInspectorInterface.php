<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_paragraph;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;

/**
 * Read-only attendee question/template inspection for Commerce orders.
 *
 * Used by checkout panes, fast checkout eligibility, and MEL checkout readiness.
 */
interface CheckoutAttendeeSchemaInspectorInterface {

  /**
   * Whether an order item participates in paragraph-based ticket-holder capture.
   */
  public function shouldCollectTicketHolders(OrderItemInterface $order_item): bool;

  /**
   * Merged event + tier attendee question template paragraphs for an order item.
   *
   * @return \Drupal\paragraphs\ParagraphInterface[]
   */
  public function getMergedQuestionTemplatesForOrderItem(OrderItemInterface $order_item): array;

  /**
   * Whether any collectible ticket row exposes non-empty attendee question templates.
   */
  public function orderHasMergedQuestionTemplates(OrderInterface $order): bool;

  /**
   * Whether checkout must collect per-ticket holder data (identity and/or vendor questions).
   *
   * True when any order item participates in ticket-holder capture and has a positive
   * quantity. This is the canonical gate for attendee pane content and completion
   * checks; {@see self::orderHasMergedQuestionTemplates} covers vendor questions only.
   */
  public function orderRequiresTicketHolderAttendeeCapture(OrderInterface $order): bool;

  /**
   * De-duplicated question templates across every collectible order item row.
   *
   * @return \Drupal\paragraphs\ParagraphInterface[]
   */
  public function collectUniqueQuestionTemplatesForOrder(OrderInterface $order): array;

}
