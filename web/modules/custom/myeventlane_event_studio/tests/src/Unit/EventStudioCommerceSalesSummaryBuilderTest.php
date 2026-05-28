<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifierInterface;
use Drupal\myeventlane_commerce\Service\TicketVariationSoldService;
use Drupal\myeventlane_event\Service\EventPaidTicketLoaderInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioCommerceSalesSummaryBuilder;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Routing\Route;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\EventStudioCommerceSalesSummaryBuilder
 *
 * @group myeventlane_event_studio
 */
final class EventStudioCommerceSalesSummaryBuilderTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $route = new Route('/studio/event/{node}/tickets');
    $route_provider = $this->createMock(\Drupal\Core\Routing\RouteProviderInterface::class);
    $route_provider->method('getRouteByName')
      ->with('myeventlane_event_studio.workspace_tickets')
      ->willReturn($route);
    $container->set('router.route_provider', $route_provider);

    $url_generator = $this->createMock(\Drupal\Core\Routing\UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')->willReturnCallback(
      static fn (string $name, array $params): string => '/studio/event/' . ($params['node'] ?? '') . '/tickets',
    );
    $container->set('url_generator', $url_generator);

    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * @covers ::buildTicketSalesPanel
   */
  public function testEmptyPanelWhenNoPaidTickets(): void {
    $event = $this->event(42);
    $lifecycle = $this->createMock(EventPaidTicketLoaderInterface::class);
    $lifecycle->method('loadOrderedTicketsForEvent')->with($event)->willReturn([]);

    $panel = $this->builder($lifecycle)->buildTicketSalesPanel($event);

    $this->assertFalse($panel['has_rows']);
    $this->assertSame([], $panel['rows']);
    $this->assertStringContainsString('No paid ticket types', $panel['empty_message']);
    $this->assertSame('completed', $panel['sold_order_state']);
    $this->assertSame('ticket_backed_order_items', $panel['sold_basis']);
  }

  /**
   * @covers ::countTicketBackedSoldByVariation
   */
  public function testOperationalOrderItemsAreExcludedFromTicketSoldCount(): void {
    $ticket_item = $this->orderItem(10, 7, '2');
    $extra_item = $this->orderItem(10, 7, '5');
    $order = $this->completedOrder();

    $classifier = $this->createMock(TicketBackedOrderItemClassifierInterface::class);
    $classifier->method('isTicketBackedOrderItem')->willReturnCallback(
      static fn (OrderItemInterface $item): bool => $item === $ticket_item,
    );

    $order_item_storage = $this->createMock(EntityStorageInterface::class);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([1, 2]);
    $order_item_storage->method('getQuery')->willReturn($query);
    $order_item_storage->method('loadMultiple')->willReturn([1 => $ticket_item, 2 => $extra_item]);

    $order_storage = $this->createMock(EntityStorageInterface::class);
    $order_storage->method('load')->with(10)->willReturn($order);

    $builder = new EventStudioCommerceSalesSummaryBuilder(
      $this->entityTypeManager($order_item_storage, $order_storage),
      $this->createMock(EventPaidTicketLoaderInterface::class),
      $classifier,
      $this->variationSoldService(),
      $this->createMock(CurrencyFormatter::class),
      $this->createMock(TimeInterface::class),
      $this->getStringTranslationStub(),
    );

    $sold = $builder->countTicketBackedSoldByVariation(99);
    $this->assertSame([7 => 2], $sold);
  }

  /**
   * @covers ::countTicketBackedSoldByVariation
   */
  public function testNonCompletedOrdersAreExcludedFromTicketSoldCount(): void {
    $ticket_item = $this->orderItem(11, 8, '3');
    $pending_order = $this->createMock(OrderInterface::class);
    $pending_order->method('getState')->willReturn(new CommerceOrderStateStub('draft'));

    $classifier = $this->createMock(TicketBackedOrderItemClassifierInterface::class);
    $classifier->method('isTicketBackedOrderItem')->willReturn(TRUE);

    $order_item_storage = $this->createMock(EntityStorageInterface::class);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([1]);
    $order_item_storage->method('getQuery')->willReturn($query);
    $order_item_storage->method('loadMultiple')->willReturn([1 => $ticket_item]);

    $order_storage = $this->createMock(EntityStorageInterface::class);
    $order_storage->method('load')->with(11)->willReturn($pending_order);

    $builder = new EventStudioCommerceSalesSummaryBuilder(
      $this->entityTypeManager($order_item_storage, $order_storage),
      $this->createMock(EventPaidTicketLoaderInterface::class),
      $classifier,
      $this->variationSoldService(),
      $this->createMock(CurrencyFormatter::class),
      $this->createMock(TimeInterface::class),
      $this->getStringTranslationStub(),
    );

    $this->assertSame([], $builder->countTicketBackedSoldByVariation(55));
  }

  /**
   * @covers ::buildTicketSalesPanel
   */
  public function testRemainingUsesCapacityMinusTicketBackedSold(): void {
    $event = $this->event(55);
    $variation = $this->variation(9);
    $tier = $this->tier('VIP', 3, $variation, 10);

    $lifecycle = $this->createMock(EventPaidTicketLoaderInterface::class);
    $lifecycle->method('loadOrderedTicketsForEvent')->with($event)->willReturn([$tier]);

    $ticket_item = $this->orderItem(10, 9, '4');
    $order = $this->completedOrder();
    $classifier = $this->createMock(TicketBackedOrderItemClassifierInterface::class);
    $classifier->method('isTicketBackedOrderItem')->willReturn(TRUE);

    $order_item_storage = $this->createMock(EntityStorageInterface::class);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([1]);
    $order_item_storage->method('getQuery')->willReturn($query);
    $order_item_storage->method('loadMultiple')->willReturn([1 => $ticket_item]);

    $order_storage = $this->createMock(EntityStorageInterface::class);
    $order_storage->method('load')->with(10)->willReturn($order);

    $builder = new EventStudioCommerceSalesSummaryBuilder(
      $this->entityTypeManager($order_item_storage, $order_storage),
      $lifecycle,
      $classifier,
      $this->variationSoldService(),
      $this->createMock(CurrencyFormatter::class),
      $this->createMock(TimeInterface::class),
      $this->getStringTranslationStub(),
    );

    $panel = $builder->buildTicketSalesPanel($event);
    $this->assertTrue($panel['has_rows']);
    $this->assertSame(4, $panel['rows'][0]['sold']);
    $this->assertSame('6', $panel['rows'][0]['remaining_label']);
    $this->assertSame(40.0, $panel['rows'][0]['percent_sold']);
  }

  /**
   * @covers ::buildTicketSalesPanel
   */
  public function testSoldOutTicketStatusIsIndependentFromOperationalStock(): void {
    $event = $this->event(77);
    $variation = $this->variation(12);
    $tier = $this->tier('GA', 5, $variation, 5);

    $lifecycle = $this->createMock(EventPaidTicketLoaderInterface::class);
    $lifecycle->method('loadOrderedTicketsForEvent')->with($event)->willReturn([$tier]);

    $ticket_item = $this->orderItem(10, 12, '5');
    $order = $this->completedOrder();
    $classifier = $this->createMock(TicketBackedOrderItemClassifierInterface::class);
    $classifier->method('isTicketBackedOrderItem')->willReturn(TRUE);

    $order_item_storage = $this->createMock(EntityStorageInterface::class);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([1]);
    $order_item_storage->method('getQuery')->willReturn($query);
    $order_item_storage->method('loadMultiple')->willReturn([1 => $ticket_item]);

    $order_storage = $this->createMock(EntityStorageInterface::class);
    $order_storage->method('load')->with(10)->willReturn($order);

    $builder = new EventStudioCommerceSalesSummaryBuilder(
      $this->entityTypeManager($order_item_storage, $order_storage),
      $lifecycle,
      $classifier,
      $this->variationSoldService(),
      $this->createMock(CurrencyFormatter::class),
      $this->createMock(TimeInterface::class),
      $this->getStringTranslationStub(),
    );

    $panel = $builder->buildTicketSalesPanel($event);
    $this->assertSame('sold_out', $panel['rows'][0]['status']);
    $this->assertSame(5, $panel['rows'][0]['sold']);
    $this->assertSame('0', $panel['rows'][0]['remaining_label']);
  }

  private function builder(EventPaidTicketLoaderInterface $lifecycle): EventStudioCommerceSalesSummaryBuilder {
    $order_item_storage = $this->createMock(EntityStorageInterface::class);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $order_item_storage->method('getQuery')->willReturn($query);

    return new EventStudioCommerceSalesSummaryBuilder(
      $this->entityTypeManager($order_item_storage, $this->createMock(EntityStorageInterface::class)),
      $lifecycle,
      $this->createMock(TicketBackedOrderItemClassifierInterface::class),
      $this->variationSoldService(),
      $this->createMock(CurrencyFormatter::class),
      $this->createMock(TimeInterface::class),
      $this->getStringTranslationStub(),
    );
  }

  private function variationSoldService(): TicketVariationSoldService {
    return new TicketVariationSoldService($this->createMock(EntityTypeManagerInterface::class));
  }

  private function entityTypeManager(
    EntityStorageInterface $order_item_storage,
    EntityStorageInterface $order_storage,
  ): EntityTypeManagerInterface {
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturnCallback(
      static fn (string $type) => match ($type) {
        'commerce_order_item' => $order_item_storage,
        'commerce_order' => $order_storage,
        default => throw new \InvalidArgumentException('Unexpected storage: ' . $type),
      },
    );
    return $etm;
  }

  private function event(int $id): NodeInterface&MockObject {
    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn((string) $id);
    $event->method('bundle')->willReturn('event');
    $event->method('getCacheTags')->willReturn(['node:' . $id]);
    return $event;
  }

  private function orderItem(int $order_id, int $variation_id, string $quantity): OrderItemInterface&MockObject {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('id')->willReturn((string) $variation_id);

    $item = $this->createMock(OrderItemInterface::class);
    $item->method('get')->with('order_id')->willReturn(new CommerceFieldTargetIdStub($order_id));
    $item->method('getPurchasedEntity')->willReturn($variation);
    $item->method('getQuantity')->willReturn($quantity);
    return $item;
  }

  private function completedOrder(): OrderInterface&MockObject {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getState')->willReturn(new CommerceOrderStateStub('completed'));
    return $order;
  }

  private function variation(int $id): ProductVariationInterface&MockObject {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('id')->willReturn((string) $id);
    $variation->method('getPrice')->willReturn(NULL);
    $variation->method('getCacheTags')->willReturn(['commerce_product_variation:' . $id]);
    $variation->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_capacity',
    );
    $variation->method('get')->willReturn(new CommerceFieldValueStub(NULL));
    return $variation;
  }

  /**
   * @return TicketTypeInterface&MockObject
   */
  private function tier(
    string $label,
    int $id,
    ProductVariationInterface $variation,
    int $capacity,
  ): TicketTypeInterface&MockObject {
    $tier = $this->createStub(TicketTypeInterface::class);
    $tier->method('getTicketKind')->willReturn('paid');
    $tier->method('label')->willReturn($label);
    $tier->method('id')->willReturn((string) $id);
    $tier->method('isArchived')->willReturn(FALSE);
    $tier->method('getCacheTags')->willReturn(['mel_ticket_type:' . $id]);
    $tier->method('hasField')->willReturnCallback(
      static fn (string $field): bool => in_array($field, [
        'commerce_variation',
        'capacity',
        'visibility_mode',
        'status',
        'sale_start',
        'sale_end',
        'event',
      ], TRUE),
    );
    $empty_field = new CommerceFieldValueStub(NULL);
    $tier->method('get')->willReturnCallback(
      static function (string $field) use ($variation, $capacity, $empty_field) {
        return match ($field) {
          'commerce_variation' => new CommerceFieldEntityStub($variation),
          'capacity' => new CommerceFieldValueStub((string) $capacity),
          'status' => new CommerceFieldValueStub('1'),
          default => $empty_field,
        };
      },
    );
    return $tier;
  }

}

/**
 * Minimal order state stub.
 */
final class CommerceOrderStateStub {

  public function __construct(private readonly string $id) {}

  public function getId(): string {
    return $this->id;
  }

}

/**
 * Minimal entity reference field stub with target_id.
 */
final class CommerceFieldTargetIdStub {

  public function __construct(public readonly int $target_id) {}

  public function isEmpty(): bool {
    return $this->target_id < 1;
  }

}

/**
 * Minimal entity reference field stub with entity.
 */
final class CommerceFieldEntityStub {

  public mixed $entity;

  public mixed $target_id;

  public function __construct(?object $entity) {
    $this->entity = $entity;
    $this->target_id = is_object($entity) && method_exists($entity, 'id') ? $entity->id() : NULL;
  }

  public function isEmpty(): bool {
    return $this->entity === NULL;
  }

}

/**
 * Minimal scalar field stub.
 */
final class CommerceFieldValueStub {

  public function __construct(public readonly ?string $value) {}

  public function isEmpty(): bool {
    return $this->value === NULL || $this->value === '';
  }

}
