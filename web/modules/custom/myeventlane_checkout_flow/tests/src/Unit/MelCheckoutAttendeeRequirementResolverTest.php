<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\myeventlane_checkout_flow\Service\MelCheckoutAttendeeRequirementResolver;
use Drupal\myeventlane_checkout_paragraph\CheckoutAttendeeSchemaInspectorInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_checkout_flow\Service\MelCheckoutAttendeeRequirementResolver
 *
 * @group myeventlane_checkout_flow
 */
final class MelCheckoutAttendeeRequirementResolverTest extends TestCase {

  /**
   * @covers ::isAttendeeCompletionSatisfied
   * @covers ::resolve
   */
  public function testNoTicketHolderCaptureMeansSatisfiedAndNotRequired(): void {
    $schema = $this->createMock(CheckoutAttendeeSchemaInspectorInterface::class);
    $schema->expects($this->atLeastOnce())
      ->method('orderRequiresTicketHolderAttendeeCapture')
      ->willReturn(FALSE);
    $schema->method('shouldCollectTicketHolders')->willReturn(FALSE);

    $order = $this->createMock(OrderInterface::class);

    $resolver = new MelCheckoutAttendeeRequirementResolver($schema);
    $this->assertTrue($resolver->isAttendeeCompletionSatisfied($order));
    $resolution = $resolver->resolve($order);
    $this->assertFalse($resolution['requires_attendee_details']);
    $this->assertSame([], $resolution['missing_required_fields']);
  }

  /**
   * Identity-only checkout (no vendor question templates) still requires completion.
   *
   * @covers ::isAttendeeCompletionSatisfied
   * @covers ::resolve
   */
  public function testCaptureWithoutVendorTemplatesRequiresIdentityCompletion(): void {
    $ticket_holder_field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $ticket_holder_field->method('referencedEntities')->willReturn([]);
    $ticket_holder_field->method('isEmpty')->willReturn(TRUE);

    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getQuantity')->willReturn(1);
    $order_item->method('hasField')->willReturnCallback(static fn (string $f): bool => $f === 'field_ticket_holder');
    $order_item->method('get')->with('field_ticket_holder')->willReturn($ticket_holder_field);

    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$order_item]);

    $schema = $this->createMock(CheckoutAttendeeSchemaInspectorInterface::class);
    $schema->method('orderRequiresTicketHolderAttendeeCapture')->willReturn(TRUE);
    $schema->method('orderHasMergedQuestionTemplates')->willReturn(FALSE);
    $schema->method('shouldCollectTicketHolders')->willReturn(TRUE);
    $schema->method('getMergedQuestionTemplatesForOrderItem')->willReturn([]);

    $resolver = new MelCheckoutAttendeeRequirementResolver($schema);
    $this->assertFalse($resolver->isAttendeeCompletionSatisfied($order));
    $resolution = $resolver->resolve($order);
    $this->assertTrue($resolution['requires_attendee_details']);
    $this->assertContains('identity', $resolution['missing_required_fields']);
  }

}
