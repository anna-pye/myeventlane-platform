<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a value proposition block for platform positioning.
 *
 * @Block(
 *   id = "myeventlane_value_proposition",
 *   admin_label = @Translation("MyEventLane Value Proposition"),
 *   category = @Translation("MyEventLane")
 * )
 */
final class ValuePropositionBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-value-proposition'],
      ],
      'content' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-container', 'mel-value-proposition-inner'],
        ],
        'cards' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['mel-value-proposition-cards'],
          ],
          'card1' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['mel-value-proposition-card'],
            ],
            'title' => [
              '#type' => 'html_tag',
              '#tag' => 'h3',
              '#value' => $this->t('Built for communities'),
              '#attributes' => [
                'class' => ['mel-value-proposition-card-title'],
              ],
            ],
            'body' => [
              '#type' => 'html_tag',
              '#tag' => 'p',
              '#value' => $this->t('Local organisers, grassroots events, and independent voices come first.'),
              '#attributes' => [
                'class' => ['mel-value-proposition-card-body'],
              ],
            ],
          ],
          'card2' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['mel-value-proposition-card'],
            ],
            'title' => [
              '#type' => 'html_tag',
              '#tag' => 'h3',
              '#value' => $this->t('No platform fees to list'),
              '#attributes' => [
                'class' => ['mel-value-proposition-card-title'],
              ],
            ],
            'body' => [
              '#type' => 'html_tag',
              '#tag' => 'p',
              '#value' => $this->t('Publish your event without upfront costs. Optional upgrades only when you need them.'),
              '#attributes' => [
                'class' => ['mel-value-proposition-card-body'],
              ],
            ],
          ],
          'card3' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['mel-value-proposition-card'],
            ],
            'title' => [
              '#type' => 'html_tag',
              '#tag' => 'h3',
              '#value' => $this->t('Inclusive by design'),
              '#attributes' => [
                'class' => ['mel-value-proposition-card-title'],
              ],
            ],
            'body' => [
              '#type' => 'html_tag',
              '#tag' => 'p',
              '#value' => $this->t('Accessibility, diversity, and respect are defaults, not add-ons.'),
              '#attributes' => [
                'class' => ['mel-value-proposition-card-body'],
              ],
            ],
          ],
        ],
      ],
      '#cache' => [
        'max_age' => 3600,
        'contexts' => ['languages'],
      ],
    ];
  }

}
