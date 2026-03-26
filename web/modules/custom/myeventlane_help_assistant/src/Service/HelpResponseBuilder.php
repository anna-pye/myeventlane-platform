<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Merges grounded AI payloads with verified workspace context (actions, follow-ups).
 *
 * Staff-only help content is never added here; retrieval remains audience-filtered
 * in HelpRetriever.
 */
final class HelpResponseBuilder {

  use StringTranslationTrait;

  /**
   * @param array<string, mixed> $ai
   *   Payload from HelpAssistantService::answerQuestion().
   * @param array<string, mixed> $context
   *   Output from HelpContextResolver::resolve().
   *
   * @return array<string, mixed>
   */
  public function build(array $ai, array $context): array {
    $response = $ai;

    $response['actions'] = is_array($ai['actions'] ?? NULL) ? $ai['actions'] : [];
    $response['steps'] = is_array($ai['steps'] ?? NULL) ? $ai['steps'] : [];
    $response['followups'] = is_array($ai['followups'] ?? NULL) ? $ai['followups'] : [];

    $context_actions = $this->buildContextActions($context);
    $context_followups = $this->buildContextFollowups($context);

    $response['actions'] = $this->mergeUniqueActions($context_actions, $response['actions']);
    $response['followups'] = $this->mergeUniqueStrings($context_followups, $response['followups']);

    if (trim((string) ($response['answer'] ?? '')) === '') {
      $response['answer'] = (string) $this->t('Here are practical next steps from MyEventLane based on the Help Centre and your workspace.');
    }

    if ($this->contextExpectsAction($context) && $response['actions'] === []) {
      $response['actions'] = $this->defaultActionsForContext($context);
    }

    $response['context_display'] = $this->buildContextDisplay($context);

    return $response;
  }

  /**
   * @param array<string, mixed> $context
   *
   * @return array<int, array<string, string>>
   */
  private function buildContextActions(array $context): array {
    $actions = [];
    $event = $context['event'] ?? NULL;
    $surface = (string) ($context['surface'] ?? 'unknown');
    $vendorish = in_array($surface, ['vendor_dashboard', 'event_workspace', 'event_wizard'], TRUE);

    if (is_array($event)) {
      $eid = (int) ($event['id'] ?? 0);
      $status = (string) ($event['status'] ?? '');
      if ($eid > 0 && $status === 'draft') {
        $actions[] = [
          'label' => (string) $this->t('Finish your event'),
          'url' => '/vendor/events/' . $eid . '/edit',
        ];
      }
      if ($eid > 0 && $status === 'published') {
        $boost_url = $vendorish
          ? '/vendor/events/' . $eid . '/boost/wizard'
          : '/event/' . $eid . '/boost';
        $actions[] = [
          'label' => (string) $this->t('Boost your event'),
          'url' => $boost_url,
        ];
      }
      if ($eid > 0) {
        $actions[] = [
          'label' => (string) $this->t('Open event workspace'),
          'url' => '/vendor/events/' . $eid,
        ];
      }
    }

    if ($surface === 'vendor_dashboard') {
      $actions[] = [
        'label' => (string) $this->t('View your events'),
        'url' => '/vendor/events',
      ];
    }

    return $actions;
  }

  /**
   * @param array<string, mixed> $context
   *
   * @return list<string>
   */
  private function buildContextFollowups(array $context): array {
    $out = [];
    $event = $context['event'] ?? NULL;
    if (!is_array($event)) {
      return $out;
    }
    $type = (string) ($event['event_type'] ?? '');
    if ($type === 'rsvp') {
      $out[] = (string) $this->t('How do I limit RSVPs?');
    }
    if ($type === 'paid' || $type === 'both') {
      $out[] = (string) $this->t('How do I increase ticket sales?');
    }
    if ($type === 'external') {
      $out[] = (string) $this->t('How do I track sales from my external ticket link?');
    }
    return $out;
  }

  /**
   * @param array<int, array<string, string>> $first
   * @param array<int, array<string, string>> $second
   *
   * @return array<int, array<string, string>>
   */
  private function mergeUniqueActions(array $first, array $second): array {
    $seen = [];
    $out = [];
    foreach (array_merge($first, $second) as $row) {
      if (!is_array($row)) {
        continue;
      }
      $url = trim((string) ($row['url'] ?? ''));
      $label = trim((string) ($row['label'] ?? ''));
      if ($url === '' || $label === '') {
        continue;
      }
      if (isset($seen[$url])) {
        continue;
      }
      $seen[$url] = TRUE;
      $out[] = ['label' => $label, 'url' => $url];
      if (count($out) >= 8) {
        break;
      }
    }
    return $out;
  }

  /**
   * @param list<string> $first
   * @param list<string> $second
   *
   * @return list<string>
   */
  private function mergeUniqueStrings(array $first, array $second): array {
    $seen = [];
    $out = [];
    foreach (array_merge($first, $second) as $item) {
      $s = trim((string) $item);
      if ($s === '') {
        continue;
      }
      $key = mb_strtolower($s);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $out[] = $s;
      if (count($out) >= 6) {
        break;
      }
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $context
   */
  private function contextExpectsAction(array $context): bool {
    if (!empty($context['event']) && is_array($context['event'])) {
      return TRUE;
    }
    $surface = (string) ($context['surface'] ?? '');
    return in_array($surface, ['vendor_dashboard', 'event_workspace', 'event_wizard'], TRUE);
  }

  /**
   * @param array<string, mixed> $context
   *
   * @return array<int, array<string, string>>
   */
  private function defaultActionsForContext(array $context): array {
    $event = $context['event'] ?? NULL;
    if (is_array($event)) {
      $eid = (int) ($event['id'] ?? 0);
      if ($eid > 0) {
        return [
          [
            'label' => (string) $this->t('Open event workspace'),
            'url' => '/vendor/events/' . $eid,
          ],
        ];
      }
    }
    return [
      [
        'label' => (string) $this->t('View your events'),
        'url' => '/vendor/events',
      ],
    ];
  }

  /**
   * @param array<string, mixed> $context
   *
   * @return array<string, mixed>
   */
  private function buildContextDisplay(array $context): array {
    $event = $context['event'] ?? NULL;
    $title = NULL;
    if (is_array($event) && !empty($event['title'])) {
      $title = (string) $event['title'];
    }
    return [
      'event_title' => $title,
      'surface' => (string) ($context['surface'] ?? 'unknown'),
    ];
  }

}
