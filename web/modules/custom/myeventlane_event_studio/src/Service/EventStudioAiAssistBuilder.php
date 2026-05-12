<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Adds the reusable Event Studio inline AI assist component to form fields.
 */
final class EventStudioAiAssistBuilder {

  use StringTranslationTrait;

  /**
   * Target field metadata for current and future Event Studio assist targets.
   *
   * @var array<string, array<string, string>>
   */
  private const TARGETS = [
    'title' => [
      'name' => 'mel[title]',
      'section' => 'information',
      'label' => 'Event title',
      'helper' => 'Shape a first impression that helps guests feel excited to attend.',
      'button' => 'Help me shape this',
      'generate' => 'Shape a draft',
      'insert' => 'Use suggestion',
    ],
    'summary' => [
      'name' => 'mel[summary]',
      'section' => 'information',
      'label' => 'Summary',
      'helper' => 'Create a clear, easy-to-scan snapshot for guests browsing on any device.',
      'button' => 'Help me shape this',
      'generate' => 'Shape a draft',
      'insert' => 'Use suggestion',
    ],
    'body' => [
      'name' => 'mel[body]',
      'section' => 'content',
      'label' => 'About the event',
      'helper' => 'Turn your notes into a warm event story guests can trust.',
      'button' => 'Help me shape this',
      'generate' => 'Shape a draft',
      'insert' => 'Use suggestion',
    ],
    'field_event_intro' => [
      'name' => 'mel[field_event_intro]',
      'section' => 'content',
      'label' => 'What to expect',
      'helper' => 'Help guests picture the experience before they RSVP or book.',
      'button' => 'Help me shape this',
      'generate' => 'Shape a draft',
      'insert' => 'Use suggestion',
    ],
  ];

  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly UrlGeneratorInterface $urlGenerator,
    private readonly LoggerInterface $logger,
    TranslationInterface $string_translation,
  ) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * Attaches AI assist to all supported fields present in the MEL form tree.
   *
   * @param array<string, mixed> $mel
   *   The form's mel subtree.
   */
  public function attachSupportedFields(array &$mel, NodeInterface $event, string $section): void {
    foreach (array_keys(self::TARGETS) as $target) {
      if (isset($mel[$target]) && is_array($mel[$target])) {
        $this->attachToElement($mel[$target], $event, $target, $section);
      }
    }
  }

  /**
   * Attaches AI assist to a single form element.
   *
   * @param array<string, mixed> $element
   *   A Drupal form element.
   */
  public function attachToElement(array &$element, NodeInterface $event, string $target, string $section): void {
    if (!$event->id() || !isset(self::TARGETS[$target])) {
      return;
    }

    $definition = self::TARGETS[$target];
    try {
      $endpoint = $this->urlGenerator->generateFromRoute('myeventlane_event_studio.ai_assist', [
        'node' => (int) $event->id(),
      ]);
    }
    catch (RouteNotFoundException $e) {
      $this->logger->error('Event Studio AI assist route is unavailable while attaching target @target to nid @nid: @message', [
        '@target' => $target,
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    $build = [
      '#theme' => 'mel_event_studio_ai_assist',
      '#target' => $target,
      '#field_name' => $definition['name'],
      '#field_label' => $this->t($definition['label']),
      '#helper' => $this->t($definition['helper']),
      '#button_label' => $this->t($definition['button']),
      '#generate_label' => $this->t($definition['generate']),
      '#regenerate_label' => $this->t('Try another shape'),
      '#insert_label' => $this->t($definition['insert']),
      '#section' => $section,
      '#endpoint' => $endpoint,
      '#chip_groups' => $this->chipGroups(),
    ];

    $markup = (string) $this->renderer->renderPlain($build);
    $existing = isset($element['#field_suffix']) ? (string) $element['#field_suffix'] : '';
    $element['#field_suffix'] = Markup::create($existing . $markup);
    $element['#attributes']['data-mel-ai-field'] = $target;
  }

  /**
   * @return array<string, array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>>
   *   Optional writing intent chips grouped for scanning.
   */
  private function chipGroups(): array {
    return [
      'Tone' => [
        'more_welcoming' => $this->t('Create a warmer first impression'),
        'more_exciting' => $this->t('Help guests feel excited to attend'),
        'community_friendly' => $this->t('Make it feel community-minded'),
        'friendly' => $this->t('Keep it friendly'),
      ],
      'Clarity' => [
        'attendee_clarity' => $this->t('Make this easier to scan on mobile'),
        'minimal' => $this->t('Keep it simple'),
        'professional' => $this->t('Make it polished'),
      ],
      'Length' => [
        'shorter' => $this->t('Make it shorter'),
        'longer' => $this->t('Add a little more detail'),
      ],
      'Social' => [
        'social_short' => $this->t('Shape it for sharing'),
        'fun' => $this->t('Make it lively'),
        'music' => $this->t('Music-friendly'),
      ],
      'Access' => [
        'lgbtqia' => $this->t('LGBTQIA+ affirming'),
        'family' => $this->t('Family-friendly'),
        'workshop' => $this->t('Workshop-friendly'),
        'activist' => $this->t('Purpose-led'),
      ],
    ];
  }

}
