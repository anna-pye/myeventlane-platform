<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFilter;

/**
 * Protects venue-name metadata across canonical public event cards.
 *
 * @group myeventlane_event
 */
final class EventCardVenueMetadataContractTest extends TestCase {

  /**
   * Ensures the venue label is prioritised and cacheable.
   */
  public function testCardModelPrioritisesVenueNameAndTracksVenueCacheability(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $model = (string) file_get_contents($moduleRoot . '/src/EventCard/EventCardViewModel.php');

    $venuePosition = strpos($model, "hasField('field_venue')");
    $namePosition = strpos($model, "hasField('field_venue_name')");
    $addressPosition = strpos($model, "hasField('field_location')");

    self::assertIsInt($venuePosition);
    self::assertIsInt($namePosition);
    self::assertIsInt($addressPosition);
    self::assertLessThan($namePosition, $venuePosition);
    self::assertLessThan($addressPosition, $namePosition);
    self::assertStringContainsString('$cacheability?->addCacheableDependency($venue);', $model);
  }

  /**
   * Ensures discovery cards render the resolved venue metadata.
   */
  public function testPosterAndSpotlightCardsRenderVenueMetadata(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/templates/components/event-card/mel-event-card.html.twig',
    );
    $frontPageStyles = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/src/scss/pages/_front-page.scss',
    );

    self::assertStringContainsString('mel-event-card__location--spotlight', $template);
    self::assertStringContainsString('mel-event-card__location--poster', $template);
    self::assertLessThan(
      strpos($template, "{% if _discovery_reason %}", strpos($template, "{% if _is_poster %}")),
      strpos($template, 'mel-event-card__location--poster'),
      'Poster cards should keep the venue with the date metadata, before merchandising signals.',
    );
    self::assertStringNotContainsString("mel_source|default('') == 'homepage_free' and _loc", $template);
    self::assertStringNotContainsString(
      ".mel-event-card__location--poster,\n    .mel-event-card__footer",
      $frontPageStyles,
    );
  }

  /**
   * Ensures field-based event Views use the shared venue resolver.
   */
  public function testFieldBasedEventViewsUseResolvedVenueName(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $theme = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/myeventlane_theme.theme',
    );
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/templates/views/views-view-fields.html.twig',
    );

    self::assertStringContainsString(
      "\$variables['mel_event_card_location'] = _myeventlane_theme_event_card_location_label(\$event_node);",
      $theme,
    );
    self::assertStringContainsString("\$venue->getCacheTags()", $theme);
    self::assertStringContainsString('location: mel_event_card_location|default(', $template);
  }

  /**
   * Ensures both public event-card entry points have valid Twig syntax.
   */
  public function testEventCardTemplatesHaveValidTwigSyntax(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $files = [
      $webRoot . '/themes/custom/myeventlane_theme/templates/components/event-card/mel-event-card.html.twig',
      $webRoot . '/themes/custom/myeventlane_theme/templates/views/views-view-fields.html.twig',
    ];
    $twig = new Environment(new ArrayLoader());
    $twig->addFilter(new TwigFilter('t', static fn (mixed $value): mixed => $value));
    $twig->addFilter(new TwigFilter('render', static fn (mixed $value): mixed => $value));

    foreach ($files as $file) {
      $source = new Source((string) file_get_contents($file), $file);
      self::assertNotNull($twig->parse($twig->tokenize($source)));
    }
  }

}
