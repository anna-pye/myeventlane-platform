<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_account\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the public calendar journey and its personal-data boundary.
 *
 * @group myeventlane_account
 */
final class CalendarEventPlannerContractTest extends TestCase {

  /**
   * Personal content stays inside a user-varied placeholder.
   */
  public function testPersonalPanelsUseUserVariedLazyBuilder(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $theme = (string) file_get_contents($webRoot . '/themes/custom/myeventlane_theme/myeventlane_theme.theme');
    $builder = (string) file_get_contents($moduleRoot . '/src/Service/CalendarPersonalisationBuilder.php');

    self::assertStringContainsString("'myeventlane_account.calendar_personalisation_builder:build'", $theme);
    self::assertStringContainsString("'#create_placeholder' => TRUE", $theme);
    self::assertStringContainsString('implements TrustedCallbackInterface', $builder);
    self::assertStringContainsString("'contexts' => ['user']", $builder);
  }

  /**
   * Saved ideas are future-facing and never presented as bookings.
   */
  public function testSavedIdeasStaySeparateFromConfirmedBookings(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $builder = (string) file_get_contents($moduleRoot . '/src/Service/CalendarPersonalisationBuilder.php');
    $template = (string) file_get_contents($moduleRoot . '/templates/mel-calendar-personalisation.html.twig');

    self::assertStringContainsString('$eventEnds >= $now', $builder);
    self::assertStringContainsString("unset(\$event['status_key'], \$event['status_label'])", $builder);
    self::assertStringContainsString('Nothing is booked until you complete checkout or RSVP.', $template);
    self::assertStringContainsString("'#card_variant' => (\$event['source'] ?? '') === 'rsvp' ? 'rsvp' : 'ticket'", $builder);
  }

  /**
   * All three journey modes use accessible tab semantics.
   */
  public function testCalendarTabsExposeThreeAccessibleModes(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/templates/includes/mel-calendar-page-content.html.twig',
    );

    self::assertStringContainsString('role="tablist"', $template);
    self::assertSame(3, substr_count($template, 'data-mel-event-planner-tab='));
    self::assertStringContainsString('data-mel-event-planner-panel="discover"', $template);
  }

  /**
   * Coming up shows the next three real events in chronological order.
   */
  public function testComingUpUsesNextThreeUpcomingEvents(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $repositoryRoot = dirname($moduleRoot, 4);
    $configPath = $repositoryRoot . '/config/sync/views.view.upcoming_events.yml';
    $config = Yaml::parseFile($configPath);
    $display = $config['display']['embed_calendar_this_week']['display_options'];

    self::assertSame(3, $display['pager']['options']['items_per_page']);
    self::assertArrayNotHasKey('field_event_start_value_2', $display['filters']);
    self::assertFalse($display['defaults']['sorts']);
    self::assertSame('ASC', $display['sorts']['field_event_start_value']['order']);

    $rawConfig = (string) file_get_contents($configPath);
    $displayStart = strpos($rawConfig, '  embed_calendar_this_week:');
    $displayEnd = strpos($rawConfig, '  homepage_hidden_gems:', $displayStart ?: 0);
    self::assertNotFalse($displayStart);
    self::assertNotFalse($displayEnd);
    $rawDisplay = substr($rawConfig, $displayStart, $displayEnd - $displayStart);
    $sortsPosition = strpos($rawDisplay, "\n      sorts:");
    $filtersPosition = strpos($rawDisplay, "\n      filters:");
    self::assertNotFalse($sortsPosition);
    self::assertNotFalse($filtersPosition);
    self::assertLessThan(
      $filtersPosition,
      $sortsPosition,
      'Views must export sorts before filters so config import converges.',
    );
  }

  /**
   * Discovery pages use a compact hero and a separate search dock.
   */
  public function testDiscoveryHeroKeepsSearchOutOfTheArtwork(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $hero = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/templates/components/discovery-hero/discovery-hero.html.twig',
    );
    $search = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/templates/components/discovery-hero/_discovery-hero-search.html.twig',
    );
    $styles = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/src/scss/components/_discovery-hero.scss',
    );

    self::assertStringContainsString('mel-home-hero__search-dock', $hero);
    self::assertStringContainsString("'Search MyEventLane'|t", $search);
    self::assertStringContainsString('.mel-home-hero--discovery', $styles);
    self::assertStringContainsString('min-height: clamp(230px, 28vw, 310px);', $styles);
  }

}
