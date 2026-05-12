<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_paragraph\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_checkout_paragraph\CheckoutAttendeeSchemaInspectorInterface;
use Drupal\myeventlane_commerce\Service\TicketAvailabilityService;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Canonical inspection of attendee question templates on ticket checkout orders.
 */
final class CheckoutAttendeeSchemaService implements CheckoutAttendeeSchemaInspectorInterface {

  public function __construct(
    private readonly TicketAvailabilityService $ticketAvailability,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function shouldCollectTicketHolders(OrderItemInterface $order_item): bool {
    if (!$order_item->hasField('field_ticket_holder')) {
      return FALSE;
    }

    return !$this->isProSubscriptionOrderItem($order_item);
  }

  /**
   * {@inheritdoc}
   */
  public function orderHasMergedQuestionTemplates(OrderInterface $order): bool {
    foreach ($order->getItems() as $order_item) {
      if (!$this->shouldCollectTicketHolders($order_item)) {
        continue;
      }
      if ($this->getMergedQuestionTemplatesForOrderItem($order_item) !== []) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function orderRequiresTicketHolderAttendeeCapture(OrderInterface $order): bool {
    foreach ($order->getItems() as $order_item) {
      if (!$this->shouldCollectTicketHolders($order_item)) {
        continue;
      }
      if ((int) $order_item->getQuantity() > 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function collectUniqueQuestionTemplatesForOrder(OrderInterface $order): array {
    $out = [];
    $seen = [];
    foreach ($order->getItems() as $order_item) {
      if (!$this->shouldCollectTicketHolders($order_item)) {
        continue;
      }
      foreach ($this->getMergedQuestionTemplatesForOrderItem($order_item) as $template) {
        $key = $this->questionTemplateDedupeKey($template);
        if (isset($seen[$key])) {
          continue;
        }
        $seen[$key] = TRUE;
        $out[] = $template;
      }
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function getMergedQuestionTemplatesForOrderItem(OrderItemInterface $order_item): array {
    $event = $this->resolveEventForOrderItem($order_item);
    if (!$event instanceof FieldableEntityInterface) {
      $purchased_entity = $order_item->getPurchasedEntity();
      $this->logger->warning(
        'Unable to resolve event for order item @id when building attendee questions. Purchased entity type: @type.',
        [
          '@id' => (string) $order_item->id(),
          '@type' => $purchased_entity ? $purchased_entity->getEntityTypeId() : 'none',
        ]
      );
      return [];
    }

    $event_templates = [];
    if ($event->hasField('field_attendee_questions') && !$event->get('field_attendee_questions')->isEmpty()) {
      $event_templates = $event->get('field_attendee_questions')->referencedEntities();
    }

    $tier_templates = [];
    $tier = NULL;
    $variation = $order_item->getPurchasedEntity();
    if ($variation instanceof ProductVariationInterface && $event instanceof NodeInterface) {
      $tier = $this->ticketAvailability->resolveTierForVariation($event, $variation);
      if ($tier !== NULL
        && $tier->hasField('field_use_ticket_attendee_questions')
        && $tier->get('field_use_ticket_attendee_questions')->value
        && $tier->hasField('field_attendee_questions')
        && !$tier->get('field_attendee_questions')->isEmpty()) {
        $tier_templates = $tier->get('field_attendee_questions')->referencedEntities();
      }
    }

    $event_templates = $this->filterQuestionTemplatesForOrderItem($event_templates, $tier, FALSE);
    $tier_templates = $this->filterQuestionTemplatesForOrderItem($tier_templates, $tier, TRUE);

    return $this->mergeQuestionTemplateParagraphs($event_templates, $tier_templates);
  }

  /**
   * Event-level questions followed by ticket-tier questions, deduped.
   *
   * @param \Drupal\paragraphs\ParagraphInterface[] $event_templates
   * @param \Drupal\paragraphs\ParagraphInterface[] $tier_templates
   *
   * @return \Drupal\paragraphs\ParagraphInterface[]
   */
  public function mergeQuestionTemplateParagraphs(array $event_templates, array $tier_templates): array {
    $seen = [];
    $out = [];
    foreach ([$event_templates, $tier_templates] as $batch) {
      foreach ($batch as $paragraph) {
        if (!$paragraph instanceof ParagraphInterface) {
          continue;
        }
        $key = $this->questionTemplateDedupeKey($paragraph);
        if (isset($seen[$key])) {
          continue;
        }
        $seen[$key] = TRUE;
        $out[] = $paragraph;
      }
    }
    return $out;
  }

  public function questionTemplateDedupeKey(ParagraphInterface $paragraph): string {
    if ($paragraph->hasField('field_question_machine_name') && !$paragraph->get('field_question_machine_name')->isEmpty()) {
      $machine = trim((string) ($paragraph->get('field_question_machine_name')->value ?? ''));
      if ($machine !== '') {
        return 'machine:' . mb_strtolower($machine);
      }
    }
    if ($paragraph->hasField('field_question_label') && !$paragraph->get('field_question_label')->isEmpty()) {
      $label = trim((string) ($paragraph->get('field_question_label')->value ?? ''));
      if ($label !== '') {
        return 'label:' . mb_strtolower($label);
      }
    }
    if ($paragraph->id() !== NULL) {
      return 'id:' . (string) $paragraph->id();
    }
    return 'tmp:' . spl_object_id($paragraph);
  }

  /**
   * @param \Drupal\paragraphs\ParagraphInterface[] $templates
   *
   * @return \Drupal\paragraphs\ParagraphInterface[]
   */
  private function filterQuestionTemplatesForOrderItem(array $templates, ?TicketTypeInterface $tier, bool $tierScoped): array {
    $out = [];
    foreach ($templates as $template) {
      if (!$template instanceof ParagraphInterface) {
        continue;
      }
      if (!$this->questionTemplateIsActive($template)) {
        continue;
      }
      $applicability = $this->questionTemplateApplicability($template);
      if ($applicability === 'per_order') {
        $this->logger->notice('Skipping per-order attendee question @id in checkout; per-order answer storage is not enabled.', [
          '@id' => (string) $template->id(),
        ]);
        continue;
      }
      if ($applicability === 'per_ticket_type') {
        if (!$tier instanceof TicketTypeInterface) {
          continue;
        }
        $ticket_ids = $this->questionTemplateTicketTypeIds($template);
        if ($ticket_ids !== [] && !in_array((int) $tier->id(), $ticket_ids, TRUE)) {
          continue;
        }
        if ($ticket_ids === [] && !$tierScoped) {
          continue;
        }
      }
      $out[] = $template;
    }
    return $out;
  }

  private function questionTemplateIsActive(ParagraphInterface $paragraph): bool {
    if ($paragraph->hasField('field_question_status') && !$paragraph->get('field_question_status')->isEmpty()) {
      return (string) $paragraph->get('field_question_status')->value !== 'archived';
    }
    return TRUE;
  }

  private function questionTemplateApplicability(ParagraphInterface $paragraph): string {
    if ($paragraph->hasField('field_question_applicability') && !$paragraph->get('field_question_applicability')->isEmpty()) {
      $value = (string) $paragraph->get('field_question_applicability')->value;
      if (in_array($value, ['per_ticket', 'per_ticket_type', 'per_order'], TRUE)) {
        return $value;
      }
    }
    return 'per_ticket';
  }

  /**
   * @return list<int>
   */
  private function questionTemplateTicketTypeIds(ParagraphInterface $paragraph): array {
    if (!$paragraph->hasField('field_question_ticket_types') || $paragraph->get('field_question_ticket_types')->isEmpty()) {
      return [];
    }
    $ids = [];
    foreach ($paragraph->get('field_question_ticket_types')->getValue() as $item) {
      $target_id = isset($item['target_id']) ? (int) $item['target_id'] : 0;
      if ($target_id > 0) {
        $ids[] = $target_id;
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Resolves the event fieldable for an order item (event questions + tier lookup).
   */
  public function resolveEventForOrderItem(OrderItemInterface $order_item): ?FieldableEntityInterface {
    $event = NULL;
    if ($order_item->hasField('field_target_event') && !$order_item->get('field_target_event')->isEmpty()) {
      $event = $order_item->get('field_target_event')->entity;
    }

    $purchased_entity = $order_item->getPurchasedEntity();

    if (!$event && $purchased_entity instanceof FieldableEntityInterface
      && $purchased_entity->hasField('field_event')
      && !$purchased_entity->get('field_event')->isEmpty()) {
      $event = $purchased_entity->get('field_event')->entity;
    }

    if (!$event && $purchased_entity && method_exists($purchased_entity, 'getProduct')) {
      $product = $purchased_entity->getProduct();
      if ($product instanceof FieldableEntityInterface
        && $product->hasField('field_event')
        && !$product->get('field_event')->isEmpty()) {
        $event = $product->get('field_event')->entity;
      }
    }

    return $event instanceof FieldableEntityInterface ? $event : NULL;
  }

  private function isProSubscriptionOrderItem(OrderItemInterface $order_item): bool {
    $purchased_entity = $order_item->getPurchasedEntity();
    if ($purchased_entity === NULL) {
      return FALSE;
    }

    if (method_exists($purchased_entity, 'bundle') && $purchased_entity->bundle() === 'mel_pro_subscription_variation') {
      return TRUE;
    }

    if (method_exists($purchased_entity, 'getSku')) {
      $sku = strtolower(trim((string) $purchased_entity->getSku()));
      if ($sku !== '' && str_starts_with($sku, 'mel-pro')) {
        return TRUE;
      }
    }

    if (method_exists($purchased_entity, 'getProduct')) {
      $product = $purchased_entity->getProduct();
      if ($product !== NULL && method_exists($product, 'bundle') && $product->bundle() === 'mel_pro_subscription_product') {
        return TRUE;
      }
    }

    return FALSE;
  }

}
