<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_legal\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects GST supplier and registration wording in MEL legal terms.
 *
 * @group myeventlane_legal
 */
final class LegalPolicyGstCopyContractTest extends TestCase {

  public function testCustomerAndOrganiserTermsExplainGstResponsibility(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $content = file_get_contents($moduleRoot . '/src/Service/LegalPolicyPageContent.php');
    $install = file_get_contents($moduleRoot . '/myeventlane_legal.install');

    self::assertIsString($content);
    self::assertIsString($install);
    self::assertStringContainsString('GST on tickets and platform charges', $content);
    self::assertStringContainsString('currently registered for GST with the Australian Taxation Office (ATO)', $content);
    self::assertStringContainsString('An active ABN does not by itself mean', $content);
    self::assertStringContainsString('separate platform fee may still include GST', $content);
    self::assertStringContainsString('myeventlane_legal_update_9011', $install);
    self::assertStringContainsString("customer_terms_version', '1.1'", $install);
    self::assertStringContainsString("vendor_terms_version', '1.1'", $install);
  }

}
