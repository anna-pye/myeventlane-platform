<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_studio\Service\EventHighlightHelper;
use Drupal\myeventlane_event_studio\Service\EventReadinessService;
use Drupal\myeventlane_event_studio\Service\EventStudioMelPayloadService;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\myeventlane_event_studio\Service\QuestionFieldTypeRegistry;
use Drupal\myeventlane_venue\Service\VenueManager;
use Drupal\myeventlane_vendor\Service\PaidPublishStripeGate;
use Drupal\myeventlane_vendor\Service\VendorPublishRequirementsGate;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @group myeventlane_event_studio
 */
final class EventStudioProductTargetPreservationTest extends UnitTestCase {

  /**
   * Partial mel without ticket fields must carry forward node product + event type.
   */
  public function testBuildFromFormStatePreservesProductTargetAndEventTypeFromNode(): void {
    $event = $this->createEventNodeMock(42, 'paid', 90);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(42)->willReturn($event);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('node')->willReturn($storage);

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->willReturnMap([
      ['mel', ['title' => 'Updated title']],
      ['nid', 42],
    ]);

    $payload_service = new EventStudioMelPayloadService(
      $this->createMock(EventHighlightHelper::class),
      $this->createMock(QuestionFieldTypeRegistry::class),
    );

    $payload = $payload_service->buildFromFormState($form_state, $entity_type_manager);

    $this->assertSame('paid', $payload['field_event_type']);
    $this->assertSame(90, $payload['field_product_target']);
  }

  /**
   * applyTicketPayload() must not clear product target for hybrid (both) events.
   */
  public function testApplyTicketPayloadPreservesProductTargetForBothEventType(): void {
    $state = ['product_target_id' => 55];
    $event = $this->createMutableEventNodeMock('both', $state);
    $service = $this->buildSaveService();

    $errors = $this->invokeApplyTicketPayload($service, $event, [
      'field_product_target' => NULL,
      'field_event_type' => 'both',
    ], 'both', TRUE);

    $this->assertSame([], $errors);
    $this->assertSame(55, $state['product_target_id']);
  }

  /**
   * Explicit valid replacement product id must still update the node reference.
   */
  public function testApplyTicketPayloadReplacesProductTargetWhenValidIdSubmitted(): void {
    $state = ['product_target_id' => 55];
    $event = $this->createMutableEventNodeMock('paid', $state);

    $product = $this->createMock(\Drupal\commerce_product\Entity\ProductInterface::class);
    $product->method('bundle')->willReturn('ticket');

    $product_storage = $this->createMock(EntityStorageInterface::class);
    $product_storage->method('load')->with(99)->willReturn($product);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnMap([
      ['commerce_product', $product_storage],
    ]);

    $service = $this->buildSaveService($entity_type_manager);

    $errors = $this->invokeApplyTicketPayload($service, $event, [
      'field_product_target' => 99,
      'field_event_type' => 'paid',
    ], 'paid', TRUE);

    $this->assertSame([], $errors);
    $this->assertSame(99, $state['product_target_id']);
  }

  /**
   * Switching away from paid-capable booking mode still clears the product target.
   */
  public function testApplyTicketPayloadClearsProductTargetWhenEventTypeIsRsvp(): void {
    $state = ['product_target_id' => 55];
    $event = $this->createMutableEventNodeMock('rsvp', $state);
    $service = $this->buildSaveService();

    $this->invokeApplyTicketPayload($service, $event, [
      'field_product_target' => NULL,
      'field_event_type' => 'rsvp',
    ], 'rsvp', TRUE);

    $this->assertNull($state['product_target_id']);
  }

  private function createEventNodeMock(int $nid, string $event_type, int $product_id): NodeInterface {
    $product_field = $this->createEntityReferenceField($product_id);
    $event_type_field = $this->createListField($event_type);

    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn($nid);
    $event->method('bundle')->willReturn('event');
    $event->method('hasField')->willReturnCallback(static fn (string $field): bool => in_array($field, [
      'field_product_target',
      'field_event_type',
    ], TRUE));
    $event->method('get')->willReturnCallback(static function (string $field) use ($product_field, $event_type_field): FieldItemListInterface {
      return match ($field) {
        'field_product_target' => $product_field,
        'field_event_type' => $event_type_field,
        default => throw new \InvalidArgumentException($field),
      };
    });

    return $event;
  }

  /**
   * @param array{product_target_id: int|null} $state
   */
  private function createMutableEventNodeMock(string $event_type, array &$state): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('hasField')->willReturnCallback(static fn (string $field): bool => in_array($field, [
      'field_product_target',
      'field_event_type',
      'field_capacity',
      'field_external_url',
      'field_collect_per_ticket',
    ], TRUE));
    $event->method('get')->willReturnCallback(function (string $field) use (&$state, $event_type): FieldItemListInterface {
      if ($field === 'field_product_target') {
        return $this->createEntityReferenceField($state['product_target_id']);
      }
      if ($field === 'field_event_type') {
        return $this->createListField($event_type);
      }
      return $this->createEmptyField();
    });
    $event->method('set')->willReturnCallback(function (string $field, mixed $value) use (&$state, $event): NodeInterface {
      if ($field === 'field_product_target') {
        if ($value === NULL || $value === []) {
          $state['product_target_id'] = NULL;
        }
        elseif (is_array($value) && isset($value['target_id'])) {
          $state['product_target_id'] = (int) $value['target_id'];
        }
        elseif (is_array($value) && isset($value[0]['target_id'])) {
          $state['product_target_id'] = (int) $value[0]['target_id'];
        }
      }
      return $event;
    });

    return $event;
  }

  private function createEntityReferenceField(?int $target_id): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($target_id === NULL);
    $field->target_id = $target_id;
    return $field;
  }

  private function createListField(string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === '');
    $field->value = $value;
    return $field;
  }

  private function createEmptyField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    $field->method('setValue')->willReturnSelf();
    return $field;
  }

  private function buildSaveService(?EntityTypeManagerInterface $entity_type_manager = NULL): EventStudioSaveService {
    $entity_type_manager ??= $this->createMock(EntityTypeManagerInterface::class);

    return new EventStudioSaveService(
      $entity_type_manager,
      $this->createMock(VenueManager::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(EventHighlightHelper::class),
      $this->createMock(PaidPublishStripeGate::class),
      $this->createMock(VendorPublishRequirementsGate::class),
      $this->createMock(EventReadinessService::class),
      $this->createMock(QuestionFieldTypeRegistry::class),
      $this->createMock(ImageFactory::class),
      $this->createMock(TranslationInterface::class),
      $this->createMock(\Drupal\Core\File\FileSystemInterface::class),
    );
  }

  /**
   * @param array<string, mixed> $payload
   *
   * @return list<string>
   */
  private function invokeApplyTicketPayload(
    EventStudioSaveService $service,
    NodeInterface $node,
    array $payload,
    string $event_type,
    bool $draft,
  ): array {
    $method = new ReflectionMethod(EventStudioSaveService::class, 'applyTicketPayload');
    $method->setAccessible(TRUE);

    /** @var list<string> $errors */
    $errors = $method->invoke($service, $node, $payload, $event_type, $draft);
    return $errors;
  }

}
