<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @group myeventlane_front
 */
final class BlogAudienceFilterTest extends TestCase {

  public function testMelBlogAudienceAlterIsWiredInModuleHook(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_front.module');
    $this->assertIsString($source);
    $this->assertStringContainsString('myeventlane_front.blog_audience_filter', $source);
    $this->assertStringContainsString('alterMelBlogView', $source);
  }

  public function testStructuredDataBreadcrumbUsesBlogLandingRoute(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/BlogStructuredDataBuilder.php');
    $this->assertIsString($source);
    $this->assertStringContainsString("Url::fromRoute('myeventlane_front.blog_landing'", $source);
    $this->assertStringNotContainsString("Url::fromRoute('view.mel_blog.page_blog'", $source);
  }

  public function testResourceDownloadsAreNotPlaybookGatedInPresentation(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/BlogArticlePresentationService.php');
    $this->assertIsString($source);
    $this->assertStringContainsString('$resourceDownloads = $this->resourceDownloads->build($node);', $source);
    $this->assertStringNotContainsString('$isPlaybook ? $this->resourceDownloads->build($node)', $source);
  }

  public function testFirstEventAudienceRunsAfterPlaybookReseed(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_front.install');
    $this->assertIsString($source);
    $this->assertStringContainsString('function _myeventlane_front_run_playbook_seeding(): string', $source);
    $this->assertStringContainsString('function _myeventlane_front_apply_first_event_organiser_audience(): string', $source);
    $this->assertStringContainsString('function myeventlane_front_update_8008(): string', $source);
    $this->assertStringContainsString('function myeventlane_front_update_8009(): string', $source);

    $update8006Start = strpos($source, 'function myeventlane_front_update_8006(): string');
    $update8007Start = strpos($source, 'function myeventlane_front_update_8007(): string');
    $update8008Start = strpos($source, 'function myeventlane_front_update_8008(): string');
    $update8009Start = strpos($source, 'function myeventlane_front_update_8009(): string');
    $this->assertNotFalse($update8006Start);
    $this->assertNotFalse($update8007Start);
    $this->assertNotFalse($update8008Start);
    $this->assertNotFalse($update8009Start);

    $update8006Body = substr($source, $update8006Start, $update8007Start - $update8006Start);
    $this->assertStringNotContainsString(
      '_myeventlane_front_apply_first_event_organiser_audience()',
      $update8006Body,
      '8006 must not apply organiser audience before playbook reseed in 8007.',
    );

    $update8007Body = substr($source, $update8007Start, $update8008Start - $update8007Start);
    $playbookReseedPos = strpos($update8007Body, '_myeventlane_front_run_playbook_seeding()');
    $audienceAfter8007 = strpos($update8007Body, '_myeventlane_front_apply_first_event_organiser_audience()');
    $this->assertNotFalse($playbookReseedPos);
    $this->assertNotFalse($audienceAfter8007);
    $this->assertLessThan(
      $audienceAfter8007,
      $playbookReseedPos,
      '8007 must reseed playbooks before applying first-event organiser audience.',
    );

    $update8009Body = substr($source, $update8009Start);
    $playbookReseedIn8009 = strpos($update8009Body, '_myeventlane_front_run_playbook_seeding()');
    $audienceAfter8009 = strpos($update8009Body, '_myeventlane_front_apply_first_event_organiser_audience()');
    $this->assertNotFalse($playbookReseedIn8009);
    $this->assertNotFalse($audienceAfter8009);
    $this->assertLessThan(
      $audienceAfter8009,
      $playbookReseedIn8009,
      '8009 must reseed playbooks before applying first-event organiser audience.',
    );
  }

}
