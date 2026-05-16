<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\myeventlane_event_studio\Service\VendorOperationalProductCreationManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Lightweight contract tests for vendor operational product creation payloads.
 *
 * @group myeventlane_event_studio
 */
#[Group('myeventlane_event_studio')]
final class VendorOperationalProductCreationManagerTest extends TestCase {

  public function testForbiddenCreationKeysList(): void {
    $this->assertContains('inventory_quantity', VendorOperationalProductCreationManager::FORBIDDEN_CREATION_KEYS);
    $this->assertContains('qr_payload', VendorOperationalProductCreationManager::FORBIDDEN_CREATION_KEYS);
    $this->assertContains('entitlement_id', VendorOperationalProductCreationManager::FORBIDDEN_CREATION_KEYS);
  }

}
