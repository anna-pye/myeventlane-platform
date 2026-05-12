<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Component\Utility\Html;
use Drupal\myeventlane_ai\Value\PromptDefinition;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds governed Event Studio writing prompts from verified event context.
 */
final class EventStudioAiPromptBuilder {

  /**
   * @var array<string, array{label: string, instruction: string, max_chars: int, max_tokens: int}>
   */
  private const FIELDS = [
    'title' => [
      'label' => 'event title',
      'instruction' => 'Write one clear event title. Keep it under 80 characters.',
      'max_chars' => 80,
      'max_tokens' => 160,
    ],
    'summary' => [
      'label' => 'event summary',
      'instruction' => 'Write one concise event summary for cards and previews. Use 1-2 sentences and keep it under 220 characters.',
      'max_chars' => 220,
      'max_tokens' => 220,
    ],
    'body' => [
      'label' => 'about the event',
      'instruction' => 'Write a warm public event description. Use 2-4 short paragraphs, plain text only.',
      'max_chars' => 1400,
      'max_tokens' => 700,
    ],
    'field_event_intro' => [
      'label' => 'what to expect',
      'instruction' => 'Write practical attendee expectations. Use short sentences or light bullet-style lines, plain text only.',
      'max_chars' => 700,
      'max_tokens' => 420,
    ],
  ];

  /**
   * @var array<string, string>
   */
  private const STYLE_INSTRUCTIONS = [
    'friendly' => 'friendly, welcoming, and easy to understand',
    'professional' => 'polished, credible, and structured',
    'community' => 'inclusive, neighbourly, and community-minded',
    'fun' => 'playful, upbeat, and energetic without hype',
    'more_welcoming' => 'warmer, more welcoming, and reassuring for attendees',
    'more_exciting' => 'more vivid and energetic while staying honest and grounded',
    'social_short' => 'short enough for social sharing while keeping the core event promise',
    'attendee_clarity' => 'clearer for attendees, with fewer assumptions and plain language',
    'community_friendly' => 'warm, inclusive, and centred on community belonging',
    'lgbtqia' => 'affirming and inclusive for LGBTQIA+ communities',
    'family' => 'clear and reassuring for families and carers',
    'workshop' => 'practical, learning-focused, and outcome-oriented',
    'music' => 'atmospheric and audience-focused for a music event',
    'activist' => 'purposeful, respectful, and action-oriented',
    'minimal' => 'brief, calm, and direct',
  ];

  /**
   * @var array<string, string>
   */
  private const PROMPT_TYPE_INSTRUCTIONS = [
    'more_welcoming' => 'Make the copy feel warmer, more welcoming, and emotionally safe for attendees.',
    'more_exciting' => 'Make the copy more vivid and exciting without hype, fake urgency, or clickbait.',
    'social_short' => 'Shorten the copy for social sharing while preserving the most important attendee-facing detail.',
    'attendee_clarity' => 'Improve clarity for attendees. Remove assumptions, jargon, and vague phrasing.',
    'community_friendly' => 'Make the copy feel community-minded, inclusive, and grounded in belonging.',
  ];

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  public function isSupportedField(string $field_name): bool {
    return isset(self::FIELDS[$field_name]);
  }

  /**
   * @param array<string, mixed> $input
   *   Client draft values from the current form. Treated only as draft context.
   *
   * @return array{
   *   definition: \Drupal\myeventlane_ai\Value\PromptDefinition,
   *   options: array<string, mixed>,
   *   scope_id: string,
   *   vendor_id: int|null
   * }
   */
  public function build(NodeInterface $event, string $field_name, array $input): array {
    if (!$this->isSupportedField($field_name)) {
      throw new \InvalidArgumentException('Unsupported Event Studio AI field.');
    }

    $field = self::FIELDS[$field_name];
    $context = $this->contextFromEventAndInput($event, $input);
    $styles = $this->styleInstructions($input['styles'] ?? []);
    $prompt_type = strtolower(trim((string) ($input['prompt_type'] ?? '')));
    $prompt_instruction = self::PROMPT_TYPE_INSTRUCTIONS[$prompt_type] ?? '';
    $current_value = $this->plainText($input['current_value'] ?? $context[$field_name] ?? '', 1800);
    $max_chars = (int) $field['max_chars'];

    $system = <<<SYS
You are MEL Write with AI for MyEventLane.

Purpose:
- Help creators write event listing copy.
- Be supportive, calm, and creator-first.
- Keep the copy factual and grounded only in the supplied context.

Rules:
- Do not invent dates, prices, performers, accessibility details, venues, or ticket promises.
- Do not mention AI, OpenAI, prompts, or models.
- Do not use emojis or hashtags.
- Return JSON only in the exact shape {"text":"..."}.
- Plain text only. No HTML.
SYS;

    $context_text = $this->formatContext($context);
    $style_parts = array_filter([$prompt_instruction, $styles !== '' ? $styles : 'friendly, clear, and distinctly MEL']);
    $style_text = implode("\n", $style_parts);
    $label = $field['label'];
    $instruction = $field['instruction'];

    $user = <<<USR
Write the {$label}.

Instruction:
{$instruction}

Maximum length:
{$max_chars} characters

Style helpers:
{$style_text}

Current value for this field:
{$current_value}

Event context:
{$context_text}

Return JSON only:
{"text":"..."}
USR;

    $hash = hash('sha256', $field_name . '|' . $system . "\n" . $user);
    $definition = new PromptDefinition('event_studio.ai_assist.' . $field_name, 'v1', $system, $user, $hash);

    return [
      'definition' => $definition,
      'options' => [
        'temperature' => 0.35,
        'max_tokens' => (int) $field['max_tokens'],
      ],
      'scope_id' => 'event_studio:ai_assist:' . $event->id() . ':' . $field_name,
      'vendor_id' => $this->vendorId($event),
    ];
  }

  public function extractGeneratedText(mixed $json, string $raw): string {
    $text = '';
    if (is_array($json)) {
      $text = (string) ($json['text'] ?? '');
    }
    if ($text === '' && trim($raw) !== '') {
      $decoded = json_decode(trim($raw), TRUE);
      if (is_array($decoded)) {
        $text = (string) ($decoded['text'] ?? '');
      }
    }
    $text = $this->plainText($text, 1400);
    if ($text === '') {
      $this->logger->notice('Event Studio AI assist returned empty text.');
    }
    return $text;
  }

  /**
   * @param array<string, mixed> $input
   *
   * @return array<string, string>
   */
  private function contextFromEventAndInput(NodeInterface $event, array $input): array {
    $context = [
      'title' => $this->firstText($input['title'] ?? NULL, $event->label()),
      'summary' => $this->firstText($input['summary'] ?? NULL, $this->fieldText($event, 'field_event_summary')),
      'body' => $this->firstText($input['body'] ?? NULL, $this->fieldText($event, 'body')),
      'field_event_intro' => $this->firstText($input['what_to_expect'] ?? NULL, $this->fieldText($event, 'field_event_intro')),
      'category' => $this->firstText($input['category'] ?? NULL, $this->termLabels($event, 'field_category')),
      'tags' => $this->firstText($input['tags'] ?? NULL, $this->termLabels($event, 'field_tags')),
      'event_type' => $this->firstText($input['event_type'] ?? NULL, $this->eventTypeLabel($event)),
      'ticket_mode' => $this->plainText($input['ticket_mode'] ?? '', 120),
      'location' => $this->firstText($input['location'] ?? NULL, $this->locationLabel($event)),
    ];
    $event_context = $input['event_context'] ?? [];
    if (is_array($event_context)) {
      foreach ($event_context as $key => $value) {
        if (is_string($key) && array_key_exists($key, $context)) {
          $context[$key] = $this->firstText($value, $context[$key]);
        }
      }
    }
    return $context;
  }

  private function firstText(mixed $preferred, mixed $fallback): string {
    $preferred_text = $this->plainText($preferred, 1800);
    if ($preferred_text !== '') {
      return $preferred_text;
    }
    return $this->plainText($fallback, 1800);
  }

  private function plainText(mixed $value, int $limit): string {
    if (is_array($value)) {
      $value = implode(', ', array_filter(array_map(static fn(mixed $item): string => is_scalar($item) ? (string) $item : '', $value)));
    }
    $text = Html::decodeEntities(strip_tags((string) $value));
    $text = preg_replace('/[^\P{C}\n\t]+/u', '', $text) ?? '';
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';
    return mb_substr(trim($text), 0, $limit);
  }

  private function fieldText(NodeInterface $event, string $field_name): string {
    if (!$event->hasField($field_name) || $event->get($field_name)->isEmpty()) {
      return '';
    }
    return (string) ($event->get($field_name)->value ?? '');
  }

  private function termLabels(NodeInterface $event, string $field_name): string {
    if (!$event->hasField($field_name) || $event->get($field_name)->isEmpty()) {
      return '';
    }
    $labels = [];
    foreach ($event->get($field_name)->referencedEntities() as $entity) {
      $labels[] = $entity->label();
    }
    return implode(', ', $labels);
  }

  private function eventTypeLabel(NodeInterface $event): string {
    if (!$event->hasField('field_event_type') || $event->get('field_event_type')->isEmpty()) {
      return '';
    }
    $value = (string) $event->get('field_event_type')->value;
    return match ($value) {
      'paid' => 'Paid ticketed event',
      'external' => 'External booking link',
      default => 'RSVP event',
    };
  }

  private function locationLabel(NodeInterface $event): string {
    if ($event->hasField('field_venue') && !$event->get('field_venue')->isEmpty()) {
      $venue = $event->get('field_venue')->entity;
      if ($venue) {
        return $venue->label();
      }
    }
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $first = $event->get('field_location')->getValue()[0] ?? [];
      if (is_array($first)) {
        return implode(', ', array_filter([
          (string) ($first['address_line1'] ?? ''),
          (string) ($first['locality'] ?? ''),
          (string) ($first['administrative_area'] ?? ''),
          (string) ($first['postal_code'] ?? ''),
        ]));
      }
    }
    return '';
  }

  private function styleInstructions(mixed $styles): string {
    if (!is_array($styles)) {
      $styles = [$styles];
    }
    $instructions = [];
    foreach ($styles as $style) {
      $key = strtolower(trim((string) $style));
      if (isset(self::STYLE_INSTRUCTIONS[$key])) {
        $instructions[] = self::STYLE_INSTRUCTIONS[$key];
      }
    }
    return implode('; ', array_unique($instructions));
  }

  private function vendorId(NodeInterface $event): ?int {
    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      return (int) $event->get('field_event_vendor')->target_id;
    }
    return NULL;
  }

  /**
   * @param array<string, string> $context
   */
  private function formatContext(array $context): string {
    $lines = [];
    foreach ($context as $label => $value) {
      $value = trim($value);
      if ($value !== '') {
        $lines[] = $label . ': ' . $value;
      }
    }
    return $lines !== [] ? implode("\n", $lines) : 'No extra event context supplied.';
  }

}
