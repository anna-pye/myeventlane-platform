<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the organiser event-index presentation hierarchy.
 *
 * @group myeventlane_vendor
 */
final class VendorEventsPresentationContractTest extends TestCase {

  public function testEventIndexUsesSharedOrganiserPageHierarchy(): void {
    $webRoot = dirname(__DIR__, 6);
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/templates/myeventlane-vendor-events-grid.html.twig',
    );
    $styles = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/src/scss/pages/_organiser-pages.scss',
    );

    self::assertStringContainsString('mel-organiser-page', $template);
    self::assertStringContainsString('mel-organiser-page__eyebrow', $template);
    self::assertStringContainsString("{{ 'Your work'|t }}", $template);
    self::assertStringContainsString('.mel-organiser-page__intro', $styles);
    self::assertStringContainsString('.mel-organiser-page__metrics', $styles);
  }

  public function testEventCardsLeadToWorkspaceAndDiscloseSecondaryActions(): void {
    $webRoot = dirname(__DIR__, 6);
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/templates/myeventlane-vendor-events-grid.html.twig',
    );

    self::assertStringContainsString("{{ 'Open workspace'|t }}", $template);
    self::assertStringNotContainsString("{{ 'Manage'|t }}", $template);
    self::assertStringContainsString('<details class="mel-vendor-events-v2__more-actions">', $template);
    self::assertStringContainsString("{{ 'More actions'|t }}", $template);
    self::assertStringContainsString("ev.section_key|default('') == 'past'", $template);

    foreach (['links.edit', 'links.tickets', 'links.rsvps', 'links.orders', 'links.attendees', 'links.analytics'] as $link) {
      self::assertStringContainsString($link, $template);
    }
    self::assertStringContainsString('mel-event-card-removal-dialog.html.twig', $template);

    $builder = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Service/VendorEventIndexViewModelBuilder.php',
    );
    self::assertStringContainsString(
      "'manage' => \$this->routeUrlIfAccessible('myeventlane_vendor.console.event_workspace', ['event' => \$nid]",
      $builder,
    );
  }

  public function testEventGridUsesTwoColumnsWithoutAThreeColumnOverride(): void {
    $webRoot = dirname(__DIR__, 6);
    $styles = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/src/scss/pages/_vendor-events.scss',
    );

    self::assertStringContainsString('repeat(2, minmax(0, 1fr))', $styles);
    self::assertStringNotContainsString('repeat(3, 1fr)', $styles);
    self::assertStringContainsString('@media (max-width: 480px)', $styles);
    self::assertStringContainsString('grid-template-columns: minmax(0, 1fr);', $styles);
  }

  public function testEventIndexDefaultsToCurrentRecommendedWork(): void {
    $webRoot = dirname(__DIR__, 6);
    $controller = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Controller/VendorEventsController.php',
    );
    $builder = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Service/VendorEventIndexViewModelBuilder.php',
    );

    self::assertStringContainsString(
      "\$request->query->get('status') ?? 'current'",
      $controller,
    );
    self::assertStringContainsString(
      "\$request->query->get('sort') ?? 'recommended'",
      $controller,
    );
    self::assertStringContainsString("private const EVENTS_PER_PAGE = 12", $builder);
    self::assertStringContainsString("'Recommended'", $builder);
    self::assertStringContainsString("'Event date'", $builder);
    self::assertStringContainsString("'Recently updated'", $builder);
    self::assertStringContainsString("'Event name'", $builder);
    self::assertStringNotContainsString("'Newest created'", $builder);
    self::assertStringNotContainsString("'Soonest'", $builder);
  }

  public function testEventIndexRendersOneManagedListWithSafeSelectionMode(): void {
    $webRoot = dirname(__DIR__, 6);
    $controller = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Controller/VendorEventsController.php',
    );
    $form = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Form/VendorEventsBulkActionsForm.php',
    );
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/templates/myeventlane-vendor-events-grid.html.twig',
    );

    self::assertStringNotContainsString("\$body['bulk']", $controller);
    self::assertSame(1, substr_count($controller, 'getForm('));
    self::assertStringContainsString("\$form['#vendor_event_index_model'] = \$model", $form);
    self::assertStringContainsString('UserVendorMembershipQuery', $form);
    self::assertStringNotContainsString('ViewExecutable', $form);
    self::assertStringNotContainsString('->delete()', $form);
    self::assertStringContainsString("'publish' =>", $form);
    self::assertStringContainsString("'unpublish' =>", $form);
    self::assertStringContainsString('data-events-select-toggle', $template);
    self::assertStringContainsString('data-events-search', $template);
  }

  public function testEmptySearchAndAttentionFiltersDoNotTrapOrDuplicateEvents(): void {
    $webRoot = dirname(__DIR__, 6);
    $builder = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Service/VendorEventIndexViewModelBuilder.php',
    );

    self::assertStringContainsString(
      "'action_label' => (string) \$this->t('Show all events')",
      $builder,
    );
    self::assertStringContainsString(
      "'all',\n          'recommended',\n          '',",
      $builder,
    );
    self::assertStringContainsString(
      "if (\$st !== 'draft'\n        && !empty(\$row['needs_attention'])",
      $builder,
    );
    self::assertStringContainsString(
      "'needs_attention' => \$status !== 'draft'\n        && !empty(\$row['needs_attention'])",
      $builder,
    );
  }

  public function testBulkPublishShowsSafeEligibilityReason(): void {
    $webRoot = dirname(__DIR__, 6);
    $form = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Form/VendorEventsBulkActionsForm.php',
    );

    $eligibilityCatch = strpos($form, 'catch (\\InvalidArgumentException $e)');
    $unexpectedCatch = strpos($form, 'catch (\\Throwable $e)');
    self::assertIsInt($eligibilityCatch);
    self::assertIsInt($unexpectedCatch);
    self::assertLessThan($unexpectedCatch, $eligibilityCatch);
    self::assertStringContainsString(
      "'Could not publish “@event”: @reason'",
      $form,
    );
    self::assertStringContainsString("'@reason' => \$e->getMessage()", $form);
    self::assertStringContainsString("'1 event could not be updated.'", $form);
  }

}
