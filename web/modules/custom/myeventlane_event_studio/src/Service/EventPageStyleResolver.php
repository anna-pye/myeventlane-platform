<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Resolves and sanitizes public event page style/colour for theme render.
 *
 * Stored field values may reflect Pro-only choices; public render and save
 * paths must enforce capability so non-Pro events never show Immersive.
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

  public function __construct(
    private readonly ?EventStyleAccessManager $styleAccess = NULL,
    private readonly ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ) {}

  /**
   * Resolves style/colour for anonymous/public full-page render.
   *
   * Uses the event owner's Pro capability (not the current viewer). Does not
   * mutate the node.
   *
   * @return array{
   *   style: string,
   *   colour: string,
   *   classes: list<string>,
   *   is_pro_style: bool,
   *   stored_style: string,
   *   stored_colour: string,
   * }
   */
  public function resolveForPublicRender(NodeInterface $event): array {
    $stored_style = $this->readStoredStyle($event);
    $stored_colour = $this->readStoredColour($event);

    return $this->buildResolved(
      $stored_style,
      $stored_colour,
      $this->canEventUseImmersive($event),
    );
  }

  /**
   * Resolves style/colour for Event Studio persistence.
   *
   * @return array{style: string, colour: string}
   */
  public function resolveForPersistence(
    NodeInterface $event,
    ?string $submitted_style,
    ?string $submitted_colour,
    AccountInterface $account,
  ): array {
    $stored_style = $submitted_style !== NULL && $submitted_style !== ''
      ? $this->sanitizeStyle($submitted_style)
      : $this->readStoredStyle($event);
    $stored_colour = $submitted_colour !== NULL && $submitted_colour !== ''
      ? $this->sanitizeColour($submitted_colour)
      : $this->readStoredColour($event);

    $can_immersive = $this->styleAccess instanceof EventStyleAccessManager
      && $this->styleAccess->canUseImmersiveStyle($event, $account);

    $resolved = $this->buildResolved($stored_style, $stored_colour, $can_immersive);

    return [
      'style' => $resolved['style'],
      'colour' => $resolved['colour'],
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

  /**
   * Whether the event owner may use Immersive on the public page.
   */
  public function canEventUseImmersive(NodeInterface $event): bool {
    if (!$this->styleAccess instanceof EventStyleAccessManager) {
      return FALSE;
    }

    $owner = $this->loadEventOwnerAccount($event);
    if ($owner === NULL) {
      return FALSE;
    }

    return $this->styleAccess->canUseImmersiveStyle($event, $owner);
  }

  /**
   * @return array{
   *   style: string,
   *   colour: string,
   *   classes: list<string>,
   *   is_pro_style: bool,
   *   stored_style: string,
   *   stored_colour: string,
   * }
   */
  private function buildResolved(string $stored_style, string $stored_colour, bool $can_immersive): array {
    $style = $this->sanitizeStyle($stored_style);
    $colour = $this->sanitizeColour($stored_colour);

    if ($style === self::STYLE_IMMERSIVE && !$can_immersive) {
      $style = self::STYLE_CLASSIC;
    }

    return [
      'style' => $style,
      'colour' => $colour,
      'classes' => $this->buildPageClasses($style, $colour),
      'is_pro_style' => $style === self::STYLE_IMMERSIVE,
      'stored_style' => $stored_style,
      'stored_colour' => $stored_colour,
    ];
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

  private function loadEventOwnerAccount(NodeInterface $event): ?AccountInterface {
    if (!$this->entityTypeManager instanceof EntityTypeManagerInterface) {
      return NULL;
    }

    $uid = (int) $event->getOwnerId();
    if ($uid < 1) {
      return NULL;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    return $user instanceof UserInterface ? $user : NULL;
  }

}
