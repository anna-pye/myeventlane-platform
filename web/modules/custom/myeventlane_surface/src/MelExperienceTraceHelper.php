<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Governs experience continuity explainability (resume, escalation booleans, hints).
 */
final class MelExperienceTraceHelper {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $experience
   *   MelExperienceManager::buildPageAttachments() without #cache.
   *
   * @return list<array<string, mixed>>
   */
  public function buildTraces(
    MelObservabilityVisibilityLevel $tier,
    MelSurfaceId $surface,
    array $experience,
  ): array {
    if ($tier === MelObservabilityVisibilityLevel::None) {
      return [];
    }

    $rows = [];
    $primary = $experience['primary_experience_id'] ?? NULL;
    if ($tier->value >= MelObservabilityVisibilityLevel::Minimal->value) {
      if (is_string($primary) && $primary !== '') {
        if ($surface === MelSurfaceId::Customer || $surface === MelSurfaceId::Auth) {
          $rows[] = $this->row(
            'mel.obs.experience.resume_continuity',
            'continuation_active',
            (string) $this->t('You can pick up a saved step when you are ready — we keep it simple and optional.'),
            '',
          );
        }
        else {
          $rows[] = $this->row(
            'mel.obs.experience.resume_continuity',
            'primary_experience',
            (string) $this->t('Primary continuity experience id: @id.', ['@id' => $primary]),
            $primary,
          );
        }
      }

      $resume = $experience['resume_candidates'] ?? [];
      if (is_array($resume) && $resume !== [] && $surface !== MelSurfaceId::Customer) {
        $rows[] = $this->row(
          'mel.obs.experience.resume_continuity',
          'resume_candidates_present',
          (string) $this->t('Resume candidates are available (@count).', ['@count' => (string) count($resume)]),
          '',
        );
      }
      elseif (is_array($resume) && $resume !== []) {
        $rows[] = $this->row(
          'mel.obs.experience.resume_continuity',
          'resume_candidates_minimal',
          (string) $this->t('A gentle “continue” hint may appear when it helps you finish what you started.'),
          '',
        );
      }
    }

    if ($tier->value >= MelObservabilityVisibilityLevel::Minimal->value) {
      $escalation = $experience['escalation_safe'] ?? [];
      if (is_array($escalation)) {
        foreach (['moderation_hold_active', 'support_escalation_active', 'commerce_dispute_context_active'] as $flag) {
          if (!empty($escalation[$flag])) {
            $rows[] = $this->row(
              'mel.obs.experience.escalation_continuity',
              $flag,
              (string) $this->t('Escalation-safe continuity posture is active (@flag).', ['@flag' => $flag]),
              '',
            );
          }
        }
      }
    }

    if ($tier->value >= MelObservabilityVisibilityLevel::Operational->value) {
      $continuation = $experience['continuation'] ?? [];
      $hints = is_array($continuation) ? ($continuation['cross_surface_hints'] ?? []) : [];
      if (is_array($hints) && $hints !== []) {
        $opaque = array_values(array_filter($hints, 'is_string'));
        sort($opaque);
        foreach ($opaque as $hint) {
          $safe = preg_replace('/[^a-z0-9_.-]/i', '_', $hint) ?: 'hint';
          $rows[] = $this->row(
            'mel.obs.experience.cross_surface_continuity',
            'hint_' . $safe,
            (string) $this->t('Cross-surface continuity hint token: @token.', ['@token' => $hint]),
            '',
          );
        }
      }
    }

    return $this->sortRows($rows);
  }

  /**
   * @param list<array<string, mixed>> $rows
   *
   * @return list<array<string, mixed>>
   */
  private function sortRows(array $rows): array {
    usort($rows, static function (array $a, array $b): int {
      return strcmp((string) ($a['deterministic_key'] ?? ''), (string) ($b['deterministic_key'] ?? ''));
    });
    return array_values($rows);
  }

  /**
   * @return array<string, mixed>
   */
  private function row(string $observability_id, string $code, string $message, string $disambiguator = ''): array {
    return [
      'observability_id' => $observability_id,
      'code' => $code,
      'message' => $message,
      'deterministic_key' => MelObservabilityDeterministicKey::format($observability_id, $code, $disambiguator),
    ];
  }

}
