<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_page_visuals\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Protects category image Media capture, backfill, and fallback contracts.
 */
#[Group('myeventlane_page_visuals')]
final class CategoryImageMediaBackfillContractTest extends UnitTestCase {

  /**
   * Category editing uses Image Media while retaining the legacy field.
   */
  public function testCategoryFieldAndFormDisplayContract(): void {
    $root = dirname(__DIR__, 7);
    $storage = file_get_contents($root . '/config/sync/field.storage.taxonomy_term.field_category_media.yml');
    $field = file_get_contents($root . '/config/sync/field.field.taxonomy_term.categories.field_category_media.yml');
    $display = file_get_contents($root . '/config/sync/core.entity_form_display.taxonomy_term.categories.default.yml');

    self::assertIsString($storage);
    self::assertIsString($field);
    self::assertIsString($display);
    self::assertStringContainsString('target_type: media', $storage);
    self::assertStringContainsString('image: image', $field);
    self::assertStringContainsString('field_category_media:', $display);
    self::assertStringContainsString('type: media_library_widget', $display);
    self::assertStringContainsString('field_category_image: true', $display);
  }

  /**
   * The update is fail-closed and never removes the direct image field.
   */
  public function testBackfillPreservesLegacyCategoryImage(): void {
    $module = dirname(__DIR__, 3);
    $update = file_get_contents($module . '/myeventlane_page_visuals.install');

    self::assertIsString($update);
    self::assertStringContainsString('myeventlane_page_visuals_update_9004', $update);
    self::assertStringContainsString("->notExists('field_category_media')", $update);
    self::assertStringContainsString('$manager->capture($term)', $update);
    self::assertStringContainsString("\$term->set('field_category_media'", $update);
    self::assertStringContainsString('throw new UpdateException', $update);
    self::assertStringNotContainsString("delete('field_category_image')", $update);
    self::assertStringNotContainsString("set('field_category_image', [])", $update);
  }

  /**
   * Rendering prefers Media and retains a physical-file legacy fallback.
   */
  public function testThemePrefersMediaAndKeepsLegacyFallback(): void {
    $root = dirname(__DIR__, 7);
    $theme = file_get_contents($root . '/web/themes/custom/myeventlane_theme/myeventlane_theme.theme');

    self::assertIsString($theme);
    $media_position = strpos($theme, "hasField('field_category_media')");
    $legacy_position = strpos($theme, "hasField('field_category_image')", $media_position ?: 0);
    self::assertNotFalse($media_position);
    self::assertNotFalse($legacy_position);
    self::assertLessThan($legacy_position, $media_position);
    self::assertStringContainsString('file_exists($file->getFileUri())', $theme);
    self::assertStringContainsString("'mel_page_visual_hero_desktop'", $theme);
    self::assertStringContainsString("'mel_page_visual_hero_mobile'", $theme);
    self::assertStringContainsString(
      '$variables[\'mel_hero_image_url_mobile\'] = $term_urls[\'mobile\'];',
      $theme,
    );
  }

  /**
   * Hero derivatives are size-limited and converted to WebP.
   */
  public function testHeroDerivativeOptimisationContract(): void {
    $root = dirname(__DIR__, 7);
    $desktop = file_get_contents($root . '/config/sync/image.style.mel_page_visual_hero_desktop.yml');
    $mobile = file_get_contents($root . '/config/sync/image.style.mel_page_visual_hero_mobile.yml');

    self::assertIsString($desktop);
    self::assertIsString($mobile);
    self::assertStringContainsString('width: 2048', $desktop);
    self::assertStringContainsString('width: 960', $mobile);
    self::assertStringContainsString('id: image_convert_avif', $desktop);
    self::assertStringContainsString('extension: webp', $desktop);
    self::assertStringContainsString('id: image_convert_avif', $mobile);
    self::assertStringContainsString('extension: webp', $mobile);
  }

  /**
   * Page Visuals fail closed when a Media entity outlives its source file.
   */
  public function testPageVisualResolverRejectsMissingSourceFiles(): void {
    $module = dirname(__DIR__, 3);
    $resolver = file_get_contents($module . '/src/Service/PageVisualResolver.php');

    self::assertIsString($resolver);
    self::assertStringContainsString('if (!file_exists($uri))', $resolver);
    self::assertStringContainsString("return NULL;", $resolver);
  }

}
