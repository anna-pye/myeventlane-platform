<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Form\FormState;
use Drupal\myeventlane_event_studio\Service\EventStudioMelPayloadService;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @group myeventlane_event_studio
 */
final class EventStudioSaveServiceHeroPersistenceTest extends UnitTestCase {

  /**
   * @covers ::brandingHeroExplicitRemovalRequested
   */
  public function testBrandingHeroExplicitRemovalRequestedRequiresEmptyFids(): void {
    $service = $this->buildPartialSaveService();
    $method = new ReflectionMethod(EventStudioSaveService::class, 'brandingHeroExplicitRemovalRequested');
    $method->setAccessible(TRUE);

    $this->assertFalse($method->invoke($service, NULL));
    $this->assertFalse($method->invoke($service, [
      0 => ['focal_point' => '50,50'],
    ]));
    $this->assertTrue($method->invoke($service, [
      0 => ['fids' => ''],
    ]));
  }

  public function testFirstPositiveIntFromFidsValueAcceptsNumericScalar(): void {
    $this->assertSame(606, EventStudioMelPayloadService::firstPositiveIntFromFidsValue(606));
  }

  public function testSyncBrandingHeroSubmittedValuesCopiesRequestFid(): void {
    $service = $this->buildPartialSaveService();
    $requestStack = new \Symfony\Component\HttpFoundation\RequestStack();
    $request = \Symfony\Component\HttpFoundation\Request::create('/', 'POST', [
      'mel' => [
        'field_event_image' => [
          0 => [
            'fids' => 606,
            'display' => 1,
          ],
        ],
        'field_event_image_alt' => 'Cover alt',
      ],
    ]);
    $requestStack->push($request);
    $this->setPrivateProperty($service, 'requestStack', $requestStack);

    $form_state = new FormState();
    $form_state->setValues([
      'mel' => [
        'field_event_image' => [
          0 => [
            'image_crop' => [
              'crop_wrapper' => [
                'event_hero' => [
                  'crop_container' => [
                    'values' => ['crop_applied' => 1],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ]);

    $method = new ReflectionMethod(EventStudioSaveService::class, 'syncBrandingHeroSubmittedValues');
    $method->setAccessible(TRUE);
    $method->invoke($service, $form_state);

    $mel = $form_state->getValue('mel');
    $hero = EventStudioMelPayloadService::normalizeHeroFromMelFragment(is_array($mel) ? $mel : []);
    $this->assertSame(606, $hero['fid']);
  }

  /**
   * @dataProvider deriveVenueDisplayNameFromAddressRowProvider
   */
  public function testDeriveVenueDisplayNameFromAddressRow(array $row, string $expected): void {
    $service = $this->buildPartialSaveService();
    $method = new ReflectionMethod(EventStudioSaveService::class, 'deriveVenueDisplayNameFromAddressRow');
    $method->setAccessible(TRUE);
    $this->assertSame($expected, $method->invoke($service, $row));
  }

  /**
   * @return array<string, array{0: array<string, string>, 1: string}>
   */
  public static function deriveVenueDisplayNameFromAddressRowProvider(): array {
    return [
      'locality and state' => [
        ['locality' => 'Sydney', 'administrative_area' => 'NSW', 'address_line1' => '1 George St'],
        'Sydney, NSW',
      ],
      'locality only' => [
        ['locality' => 'Melbourne', 'address_line1' => '100 Collins St'],
        'Melbourne',
      ],
      'line1 fallback' => [
        ['address_line1' => '100 Collins St'],
        '100 Collins St',
      ],
    ];
  }

  /**
   * @covers ::applyVenueDisplayName
   */
  public function testApplyVenueDisplayNameClearsStaleValueWhenOneOffLocationEmpty(): void {
    $service = $this->buildPartialSaveService();
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_venue_name')->willReturn(TRUE);
    $node->expects($this->once())
      ->method('set')
      ->with('field_venue_name', NULL);

    $method = new ReflectionMethod(EventStudioSaveService::class, 'applyVenueDisplayName');
    $method->setAccessible(TRUE);
    $method->invoke($service, $node, 'one_off', NULL);
  }

  /**
   * @covers ::applyVenueDisplayName
   */
  public function testApplyVenueDisplayNameSetsDerivedOneOffLabel(): void {
    $service = $this->buildPartialSaveService();
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_venue_name')->willReturn(TRUE);
    $node->expects($this->once())
      ->method('set')
      ->with('field_venue_name', 'Brisbane, QLD');

    $method = new ReflectionMethod(EventStudioSaveService::class, 'applyVenueDisplayName');
    $method->setAccessible(TRUE);
    $method->invoke($service, $node, 'one_off', [
      'locality' => 'Brisbane',
      'administrative_area' => 'QLD',
      'address_line1' => '1 Queen St',
    ]);
  }

  private function buildPartialSaveService(): EventStudioSaveService {
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')->willReturnCallback(
      static fn (string $string, array $args = [], array $options = []): string => $string,
    );

    $service = (new ReflectionClass(EventStudioSaveService::class))->newInstanceWithoutConstructor();
    $this->setPrivateProperty($service, 'entityTypeManager', $this->createMock(EntityTypeManagerInterface::class));
    $this->setPrivateProperty($service, 'fileSystem', $this->createMock(FileSystemInterface::class));
    $this->setPrivateProperty($service, 'stringTranslation', $translation);
    $this->setPrivateProperty($service, 'logger', $this->createMock(LoggerInterface::class));

    return $service;
  }

  private function setPrivateProperty(object $object, string $property, mixed $value): void {
    $ref = new ReflectionProperty($object, $property);
    $ref->setAccessible(TRUE);
    $ref->setValue($object, $value);
  }

}
