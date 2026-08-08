<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the CK-1 staff editor and organiser boundary configuration.
 */
#[Group('myeventlane_vendor')]
final class StaffMediaEditorConfigTest extends TestCase {

  /**
   * Verifies that staff use Media Embed without direct file uploads.
   */
  public function testStaffEditorUsesMediaEmbedOnly(): void {
    $editor = $this->config('editor.editor.staff_media_html.yml');
    $toolbar = $editor['settings']['toolbar']['items'] ?? [];

    self::assertContains('drupalMedia', $toolbar);
    self::assertNotContains('drupalInsertImage', $toolbar);
    self::assertFalse($editor['image_upload']['status'] ?? TRUE);

    $format = $this->config('filter.format.staff_media_html.yml');
    self::assertTrue($format['filters']['media_embed']['status'] ?? FALSE);
    self::assertSame(
      ['image' => 'image'],
      $format['filters']['media_embed']['settings']['allowed_media_types'] ?? [],
    );
  }

  /**
   * Verifies that CK-1 does not grant the staff format to organisers.
   */
  public function testFormatPermissionRemainsStaffOnly(): void {
    $staff = $this->config('user.role.content_editor.yml');
    $vendor = $this->config('user.role.vendor.yml');

    self::assertContains('use text format staff_media_html', $staff['permissions'] ?? []);
    self::assertNotContains('use text format staff_media_html', $vendor['permissions'] ?? []);
    self::assertContains('use text format basic_html', $vendor['permissions'] ?? []);

    $basicEditor = $this->config('editor.editor.basic_html.yml');
    self::assertTrue($basicEditor['image_upload']['status'] ?? FALSE);
    self::assertContains(
      'drupalInsertImage',
      $basicEditor['settings']['toolbar']['items'] ?? [],
    );
    self::assertNotContains(
      'drupalMedia',
      $basicEditor['settings']['toolbar']['items'] ?? [],
    );
  }

  /**
   * Verifies that only the approved Page and Article fields opt in.
   */
  public function testPageAndArticleAllowStaffFormatAndLegacyFormats(): void {
    foreach (['page', 'article'] as $bundle) {
      $field = $this->config('field.field.node.' . $bundle . '.body.yml');
      self::assertSame(
        ['staff_media_html', 'basic_html', 'full_html'],
        $field['settings']['allowed_formats'] ?? [],
      );
    }

    $event = $this->config('field.field.node.event.body.yml');
    self::assertSame(['plain_text'], $event['settings']['allowed_formats'] ?? []);
  }

  /**
   * Loads a synchronised configuration file.
   *
   * @return array<string, mixed>
   *   Parsed configuration.
   */
  private function config(string $file): array {
    $path = dirname(__DIR__, 7) . '/config/sync/' . $file;
    self::assertFileExists($path);
    $data = Yaml::parseFile($path);
    self::assertIsArray($data);
    return $data;
  }

}
