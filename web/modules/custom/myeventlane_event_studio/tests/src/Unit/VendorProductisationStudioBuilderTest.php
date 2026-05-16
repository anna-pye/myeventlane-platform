<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\myeventlane_event_studio\Service\CustomerOperationalCommerceExperienceBuilder;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommerceLinkManager;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommercePreviewBuilder;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityPreviewBuilder;
use Drupal\myeventlane_event_studio\Service\VendorProductisationStudioBuilder;
use Drupal\myeventlane_event_studio\Service\VendorProductisationStudioManager;
use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager;
use Drupal\myeventlane_tickets\Service\InventoryReservationGovernanceManager;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\VendorProductisationStudioBuilder
 *
 * @group myeventlane_event_studio
 */
final class VendorProductisationStudioBuilderTest extends UnitTestCase {

  /**
   * @covers ::buildProductisationWorkspace
   */
  public function testBuildProductisationWorkspaceReturnsTheme(): void {
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
    $studioManager = new VendorProductisationStudioManager($merch, $link);
    $commercePreview = new OperationalCapabilityCommercePreviewBuilder(
      $link,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->translationStub(),
    );
    $operationalStudio = OperationalCapabilityStudioTestFactory::buildManager($logger);
    $operationalPreview = new OperationalCapabilityPreviewBuilder(
      $operationalStudio,
      $commercePreview,
      $this->translationStub(),
    );
    $customer = new CustomerOperationalCommerceExperienceBuilder(
      $link,
      $operationalPreview,
      $operationalStudio,
      $registry,
      $fulfillment,
      $reservation,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->translationStub(),
    );
    $builder = new VendorProductisationStudioBuilder(
      $studioManager,
      $merch,
      $commercePreview,
      $customer,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->translationStub(),
    );
    $event = $this->createMock(NodeInterface::class);
    $event->method('getCacheTags')->willReturn(['node:1']);
    $out = $builder->buildProductisationWorkspace([
      'items' => [],
      'validation_messages' => [],
    ], [
      'capabilities' => [],
    ], $event);
    $this->assertSame('mel_event_studio_productisation', $out['#theme']);
  }

  private function translationStub(): TranslationInterface {
    $translator = $this->createMock(TranslationInterface::class);
    $translator
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    return $translator;
  }

}
