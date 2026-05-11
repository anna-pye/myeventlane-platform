<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Public marketing pages linked from the site footer.
 */
final class MarketingPageController extends ControllerBase {

  /**
   * Builds the About MyEventLane page.
   */
  public function about(): array {
    return $this->buildPage(
      $this->t('About MyEventLane'),
      $this->t('MyEventLane helps people discover local events and gives organisers a straightforward way to publish, promote, and manage them.'),
      [
        $this->t('Find events by category, date, and ticket type.'),
        $this->t('Host free RSVP or ticketed events with clear organiser tools.'),
        $this->t('Keep community trust, support, and secure payments close to every event journey.'),
      ],
    );
  }

  /**
   * Builds the MyEventLane Story page.
   */
  public function story(): array {
    return $this->buildPage(
      $this->t('MyEventLane Story'),
      $this->t('MyEventLane is built around a simple idea: local events should be easier to find, easier to host, and easier to trust.'),
      [
        $this->t('We are shaping MyEventLane for community organisers, creative teams, venues, and attendees who want one reliable place to connect.'),
        $this->t('The platform is continuing to grow with better discovery, stronger organiser workflows, and practical support content.'),
      ],
    );
  }

  /**
   * Builds the Blog placeholder page.
   */
  public function blog(): array {
    return $this->buildPage(
      $this->t('MyEventLane Blog'),
      $this->t('The MyEventLane blog is being set up.'),
      [
        $this->t('Check back for organiser guides, platform updates, and event discovery stories.'),
      ],
    );
  }

  /**
   * Builds a shared marketing page render array.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   Page title.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $intro
   *   Introductory copy.
   * @param array<int, \Drupal\Core\StringTranslation\TranslatableMarkup> $points
   *   Supporting bullet points.
   *
   * @return array
   *   Render array.
   */
  private function buildPage(TranslatableMarkup $title, TranslatableMarkup $intro, array $points): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-container', 'mel-marketing-page'],
      ],
      'content' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-marketing-page__body'],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h1',
          '#value' => $title,
        ],
        'intro' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $intro,
        ],
        'points' => [
          '#theme' => 'item_list',
          '#items' => $points,
        ],
      ],
      '#cache' => [
        'max-age' => 3600,
      ],
    ];
  }

}
