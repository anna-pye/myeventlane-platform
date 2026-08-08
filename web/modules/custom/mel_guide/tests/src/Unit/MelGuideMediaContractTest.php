<?php

declare(strict_types=1);

namespace Drupal\Tests\mel_guide\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Protects MEL Guide Media capture and fallback contracts.
 */
#[Group('mel_guide')]
final class MelGuideMediaContractTest extends UnitTestCase {

  /**
   * Media UUIDs extend rather than replace the rollback configuration.
   */
  public function testEnvironmentStateRetainsConfigFallbacks(): void {
    $module = dirname(__DIR__, 3);
    $root = dirname(__DIR__, 7);
    $install = file_get_contents($module . '/config/install/mel_guide.settings.yml');
    $sync = file_get_contents($root . '/config/sync/mel_guide.settings.yml');
    $manager = file_get_contents($module . '/src/Service/MelGuideAssetMediaManager.php');

    self::assertIsString($install);
    self::assertIsString($sync);
    self::assertIsString($manager);
    self::assertStringContainsString("STATE_KEY = 'mel_guide.asset_media_uuids'", $manager);
    self::assertStringNotContainsString('asset_media_uuids:', $install);
    self::assertStringNotContainsString('asset_media_uuids:', $sync);
    self::assertStringContainsString('asset_fids:', $install);
    self::assertStringContainsString('assets:', $install);
  }

  /**
   * Runtime resolution is Media, legacy file, then editable path fallback.
   */
  public function testResolutionOrderAndCacheContract(): void {
    $module = dirname(__DIR__, 3);
    $context = file_get_contents($module . '/src/Service/MelGuideContext.php');
    $block = file_get_contents($module . '/src/Plugin/Block/MelGuideBlock.php');

    self::assertIsString($context);
    self::assertIsString($block);
    $media_position = strpos($context, 'MelGuideAssetMediaManager::STATE_KEY');
    $fid_position = strpos($context, "get('asset_fids')");
    $path_position = strpos($context, "get('assets')");
    self::assertNotFalse($media_position);
    self::assertNotFalse($fid_position);
    self::assertNotFalse($path_position);
    self::assertLessThan($fid_position, $media_position);
    self::assertLessThan($path_position, $fid_position);
    self::assertStringContainsString('fileExists($file)', $context);
    self::assertStringContainsString("'cache_tags' => array_merge(\$media->getCacheTags(), \$file->getCacheTags())", $context);
    self::assertStringContainsString("Cache::mergeTags(\$this->getCacheTags(), \$variables['cache_tags'] ?? [])", $block);
  }

  /**
   * Capture is system-owned and accepts only Image Media-compatible uploads.
   */
  public function testPlatformOwnershipAndUploadContract(): void {
    $module = dirname(__DIR__, 3);
    $manager = file_get_contents($module . '/src/Service/MelGuideAssetMediaManager.php');
    $form = file_get_contents($module . '/src/Form/MelGuideSettingsForm.php');

    self::assertIsString($manager);
    self::assertIsString($form);
    self::assertStringContainsString('PLATFORM_MEDIA_OWNER_ID = 0', $manager);
    self::assertStringContainsString("load('image')", $manager);
    self::assertStringContainsString('$media->validate()', $manager);
    self::assertStringContainsString("'extensions' => 'png jpg jpeg gif webp'", $form);
    self::assertStringNotContainsString('webp svg', $form);
    self::assertStringContainsString("->set('asset_fids', \$asset_fids)", $form);
    self::assertStringContainsString('MelGuideAssetMediaManager::STATE_KEY, $asset_media_uuids', $form);
  }

  /**
   * Update skips missing/unsupported files but fails closed on save errors.
   */
  public function testBackfillRetainsUnavailableLegacyAssets(): void {
    $module = dirname(__DIR__, 3);
    $update = file_get_contents($module . '/mel_guide.install');

    self::assertIsString($update);
    self::assertStringContainsString('mel_guide_update_9004', $update);
    self::assertStringContainsString('catch (\\InvalidArgumentException', $update);
    self::assertStringContainsString('throw new UpdateException', $update);
    self::assertStringNotContainsString("clear('asset_fids')", $update);
    self::assertStringNotContainsString("clear('assets')", $update);
  }

  /**
   * Form submission skips unchanged legacy capture and rolls back as one unit.
   */
  public function testSettingsSubmitIsAtomicAndLegacySafe(): void {
    $module = dirname(__DIR__, 3);
    $form = file_get_contents($module . '/src/Form/MelGuideSettingsForm.php');

    self::assertIsString($form);
    self::assertStringContainsString('if ($new_fid === $old_fid)', $form);
    self::assertStringContainsString('never recapture unchanged', $form);
    self::assertStringContainsString('$this->database->startTransaction()', $form);
    self::assertStringContainsString('$transaction->rollBack()', $form);
    self::assertStringContainsString('unset($transaction)', $form);

    $capture_position = strpos($form, '$this->assetMediaManager->capture');
    $usage_position = strpos($form, '$this->fileUsage->delete');
    self::assertNotFalse($capture_position);
    self::assertNotFalse($usage_position);
    self::assertLessThan($usage_position, $capture_position);
  }

}
