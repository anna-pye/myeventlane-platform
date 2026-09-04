<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_account\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards customer dashboard trust and presentation behaviour.
 *
 * @group myeventlane_account
 */
final class CustomerHubTrustPresentationContractTest extends TestCase {

  public function testNotificationPreviewCollapsesRepeatedReminderRows(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $module = (string) file_get_contents($moduleRoot . '/myeventlane_account.module');

    self::assertStringContainsString('getUnreadDeliveryIds($uid, 12,', $module);
    self::assertStringContainsString('collapseActionCentreRows($rows)', $module);
    self::assertStringContainsString('array_slice($viewBuilder->collapseActionCentreRows($rows), 0, 3)', $module);
  }

  public function testEventCardsOnlyRenderImagesWithExistingSourceFiles(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $builder = (string) file_get_contents($moduleRoot . '/src/Service/CustomerHubDataBuilder.php');
    $services = (string) file_get_contents($moduleRoot . '/myeventlane_account.services.yml');
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/templates/account/mel-account-event-card.html.twig',
    );

    self::assertStringContainsString('FileSystemInterface $fileSystem', $builder);
    self::assertStringContainsString('$this->fileSystem->realpath($uri)', $builder);
    self::assertStringContainsString('is_file($sourcePath)', $builder);
    self::assertStringContainsString("- '@file_system'", $services);
    self::assertStringNotContainsString('onerror=', $template);
  }

}
