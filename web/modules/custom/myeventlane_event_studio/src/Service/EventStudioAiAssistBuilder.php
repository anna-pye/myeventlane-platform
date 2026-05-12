<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

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
      'helper' => 'Need help naming it? Create a clear, guest-friendly title.',
      'button' => 'Write with AI',
      'generate' => 'Generate a first draft',
      'insert' => 'Insert title',
    ],
    'summary' => [
      'name' => 'mel[summary]',
      'section' => 'information',
      'label' => 'Summary',
      'helper' => 'Give your audience a clear overview in a few calm sentences.',
      'button' => 'Write with AI',
      'generate' => 'Generate a first draft',
      'insert' => 'Insert summary',
    ],
    'body' => [
      'name' => 'mel[body]',
      'section' => 'content',
      'label' => 'About the event',
      'helper' => 'Create a friendly event description guests can trust.',
      'button' => 'Write with AI',
      'generate' => 'Generate a first draft',
      'insert' => 'Insert description',
    ],
    'field_event_intro' => [
      'name' => 'mel[field_event_intro]',
      'section' => 'content',
      'label' => 'What to expect',
      'helper' => 'Help guests picture the experience before they RSVP or book.',
      'button' => 'Write with AI',
      'generate' => 'Generate a first draft',
      'insert' => 'Insert expectations',
    ],
  ];

  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly UrlGeneratorInterface $urlGenerator,
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
    $endpoint = $this->urlGenerator->generateFromRoute('myeventlane_event_studio.ai_assist', [
      'node' => (int) $event->id(),
    ]);

    $build = [
      '#theme' => 'mel_event_studio_ai_assist',
      '#target' => $target,
      '#field_name' => $definition['name'],
      '#field_label' => $this->t($definition['label']),
      '#helper' => $this->t($definition['helper']),
      '#button_label' => $this->t($definition['button']),
      '#generate_label' => $this->t($definition['generate']),
      '#regenerate_label' => $this->t('Regenerate'),
      '#insert_label' => $this->t($definition['insert']),
      '#section' => $section,
      '#endpoint' => $endpoint,
      '#style_chips' => $this->styleChips(),
    ];

    $markup = (string) $this->renderer->renderPlain($build);
    $existing = isset($element['#field_suffix']) ? (string) $element['#field_suffix'] : '';
    $element['#field_suffix'] = $existing . $markup;
    $element['#attributes']['data-mel-ai-field'] = $target;
  }

  /**
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Optional style chips available to every assist panel.
   */
  private function styleChips(): array {
    return [
      'friendly' => $this->t('Friendly'),
      'professional' => $this->t('Professional'),
      'community' => $this->t('Community'),
      'fun' => $this->t('Fun'),
      'more_exciting' => $this->t('Make it more exciting'),
      'shorten' => $this->t('Shorten this'),
      'community_friendly' => $this->t('Make it community-friendly'),
      'readability' => $this->t('Improve readability'),
      'lgbtqia' => $this->t('LGBTQIA+'),
      'family' => $this->t('Family'),
      'workshop' => $this->t('Workshop'),
      'music' => $this->t('Music'),
      'activist' => $this->t('Activist'),
      'minimal' => $this->t('Minimal'),
    ];
  }

}
