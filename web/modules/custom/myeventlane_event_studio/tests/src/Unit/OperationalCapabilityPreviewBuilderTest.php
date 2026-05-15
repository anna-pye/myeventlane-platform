<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\myeventlane_event_studio\Service\OperationalCapabilityPreviewBuilder;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\OperationalCapabilityPreviewBuilder
 *
 * @group myeventlane_event_studio
 */
final class OperationalCapabilityPreviewBuilderTest extends UnitTestCase {

  /**
   * @covers ::buildCustomerPreview
   */
  public function testCustomerPreviewExcludesSecrets(): void {
    $studio = OperationalCapabilityStudioTestFactory::buildManager();
    $builder = new OperationalCapabilityPreviewBuilder($studio, $this->getStringTranslationStub());

    $preview = $builder->buildCustomerPreview([
      'schema_version' => 1,
      'capabilities' => [
        OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP => [
          'enabled' => TRUE,
          'customer_visibility' => 'after_purchase',
          'preview_summary' => 'Merch pickup · collect on site',
          'fulfillment_mode' => 'collect',
          'reservation_mode' => 'merch',
        ],
      ],
    ]);
    $this->assertFalse($preview['empty']);
    $this->assertCount(1, $preview['items']);
    $this->assertStringNotContainsString('replay_token', (string) $preview['items'][0]['summary']);
  }

}
