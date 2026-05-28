<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommerceLinkManager;
use Drupal\myeventlane_event_studio\Service\VendorProductisationStudioManager;
use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager;
use Drupal\myeventlane_tickets\Service\InventoryReservationGovernanceManager;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\VendorProductisationStudioManager
 *
 * @group myeventlane_event_studio
 */
final class VendorProductisationStudioManagerTest extends UnitTestCase {

  /**
   * @covers ::stripForbiddenRecursive
   */
  public function testStripForbiddenRecursiveRemovesExecutionKeys(): void {
    $manager = $this->createVendorProductisationStudioManager();
    $clean = $manager->stripForbiddenRecursive([
      'title' => 'Hat',
      'qr_payload' => ['nope' => TRUE],
      'nested' => ['scanner_action' => 'x', 'ok' => 1],
    ]);
    $this->assertSame('Hat', $clean['title']);
    $this->assertArrayNotHasKey('qr_payload', $clean);
    $this->assertArrayNotHasKey('scanner_action', $clean['nested']);
    $this->assertSame(1, $clean['nested']['ok']);
  }

  /**
   * @covers ::validateMerchandiseFragment
   */
  public function testValidateRejectsTooManyRows(): void {
    $manager = $this->createVendorProductisationStudioManager();
    $event = $this->createMock(NodeInterface::class);
    $items = [];
    for ($i = 0; $i < 30; $i++) {
      $items[] = ['productisation_type' => VendorProductisationStudioManager::TYPE_MERCHANDISE];
    }
    $errors = $manager->validateMerchandiseFragment($event, ['productisation_items' => $items]);
    $this->assertNotEmpty($errors);
  }

  private function createVendorProductisationStudioManager(): VendorProductisationStudioManager {
    $logger = new TestLoggerChannel();
    $registry = new EntitlementCapabilityRegistry();
    $reservation = new InventoryReservationGovernanceManager($registry, $logger);
    $capability = new OperationalEntitlementCapabilityManager($registry, $reservation, $logger);
    $fulfillment = new FulfillmentLifecycleManager($registry);
    $link = new OperationalCapabilityCommerceLinkManager(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(AccountProxyInterface::class),
      $registry,
      $capability,
      $fulfillment,
      $reservation,
    );
    $merch = new OperationalMerchandiseManager(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->translationStub(),
      $logger,
    );
    return new VendorProductisationStudioManager($merch, $link);
  }

  private function translationStub(): TranslationInterface {
    $translator = $this->createMock(TranslationInterface::class);
    $translator
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    return $translator;
  }

}
