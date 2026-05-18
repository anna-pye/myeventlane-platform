<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\node\NodeInterface;

/**
 * Resolves and sanitizes public event page style/colour for theme render.
 */
final class EventPageStyleResolver {

  public const STYLE_CLASSIC = 'classic';

  public const STYLE_IMMERSIVE = 'immersive';

  public const COLOUR_CORAL = 'coral';

  public const COLOUR_PURPLE = 'purple';

  public const COLOUR_MINT = 'mint';

  public const COLOUR_GOLD = 'gold';

  public const COLOUR_BLUE = 'blue';

  /**
   * @var list<string>
   */
  private const ALLOWED_STYLES = [
    self::STYLE_CLASSIC,
    self::STYLE_IMMERSIVE,
  ];

  /**
   * @var list<string>
   */
  private const ALLOWED_COLOURS = [
    self::COLOUR_CORAL,
    self::COLOUR_PURPLE,
    self::COLOUR_MINT,
    self::COLOUR_GOLD,
    self::COLOUR_BLUE,
  ];

  /**
   * Resolves stored values for anonymous/public full-page render.
   *
   * Capability enforcement on save happens in Event Studio; public render only
   * sanitizes allowed values. Future Pro expiry should use persisted entitlement
   * state and downgrade stored style when subscription lapses.
   *
   * @return array{style: string, colour: string, classes: list<string>}
   */
  public function resolveForPublicRender(NodeInterface $event): array {
    $style = $this->readStoredStyle($event);
    $colour = $this->readStoredColour($event);

    return [
      'style' => $style,
      'colour' => $colour,
      'classes' => $this->buildPageClasses($style, $colour),
    ];
  }

  /**
   * Sanitizes a style machine name for render or persistence.
   */
  public function sanitizeStyle(?string $style): string {
    $style = strtolower(trim((string) $style));
    return in_array($style, self::ALLOWED_STYLES, TRUE)
      ? $style
      : self::STYLE_CLASSIC;
  }

  /**
   * Sanitizes a colour machine name for render or persistence.
   */
  public function sanitizeColour(?string $colour): string {
    $colour = strtolower(trim((string) $colour));
    return in_array($colour, self::ALLOWED_COLOURS, TRUE)
      ? $colour
      : self::COLOUR_CORAL;
  }

  /**
   * @return list<string>
   */
  public function buildPageClasses(string $style, string $colour): array {
    $style = $this->sanitizeStyle($style);
    $colour = $this->sanitizeColour($colour);

    return [
      'mel-event-page--' . $style,
      'mel-event-page--colour-' . $colour,
    ];
  }

  /**
   * @return list<string>
   */
  public function getAllowedStyles(): array {
    return self::ALLOWED_STYLES;
  }

  /**
   * @return list<string>
   */
  public function getAllowedColours(): array {
    return self::ALLOWED_COLOURS;
  }

  private function readStoredStyle(NodeInterface $event): string {
    if (!$event->hasField('field_mel_page_style') || $event->get('field_mel_page_style')->isEmpty()) {
      return self::STYLE_CLASSIC;
    }
    return $this->sanitizeStyle((string) $event->get('field_mel_page_style')->value);
  }

  private function readStoredColour(NodeInterface $event): string {
    if (!$event->hasField('field_mel_theme_colour') || $event->get('field_mel_theme_colour')->isEmpty()) {
      return self::COLOUR_CORAL;
    }
    return $this->sanitizeColour((string) $event->get('field_mel_theme_colour')->value);
  }

}
