<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Behavioural orchestration entry point for MEL surfaces.
 */
final class MelWorkflowManager {

  use StringTranslationTrait;

  public function __construct(
    private readonly MelWorkflowRegistry $registry,
    private readonly MelWorkflowResolver $resolver,
    private readonly MelWorkflowProgressHelper $progressHelper,
    private readonly MelWorkflowCTAHelper $ctaHelper,
    private readonly MelWorkflowAccessibilityHelper $accessibilityHelper,
    private readonly LoggerChannelInterface $logger,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Attachments for page preprocess (render arrays + cache metadata).
   *
   * @return array<string, mixed>
   */
  public function buildPageAttachments(): array {
    try {
      $context = $this->resolver->buildContext();
      $active = $this->resolver->resolveActiveWorkflows($this->registry, $context);
    }
    catch (\Throwable $e) {
      $this->logger->error('MEL workflow orchestration failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        '#cache' => [
          'contexts' => ['route', 'user'],
          'max-age' => 0,
        ],
      ];
    }

    $completion_definitions = [];
    $incomplete_definitions = [];
    foreach ($active as $definition) {
      if ($this->resolver->isCompletionMoment($definition, $context)) {
        $completion_definitions[] = $definition;
      }
      else {
        $incomplete_definitions[] = $definition;
      }
    }

    // RSVP thank-you carries its own confirmation hero; suppress duplicate success panel.
    $completion_definitions = array_values(array_filter(
      $completion_definitions,
      static function (WorkflowDefinition $d) use ($context): bool {
        if ($d->id === 'first_rsvp' && !empty($context->signals['route.rsvp_thankyou'])) {
          return FALSE;
        }
        return TRUE;
      },
    ));

    $primary = [];
    if ($completion_definitions !== []) {
      usort($completion_definitions, static function (WorkflowDefinition $a, WorkflowDefinition $b): int {
        return $b->ctaPriority <=> $a->ctaPriority;
      });
      $primary = $this->buildCompletionRegion($completion_definitions[0]);
    }
    else {
      $primary = $this->ctaHelper->buildPrimaryRegion($incomplete_definitions, $context);
    }

    if ($primary !== []) {
      $this->accessibilityHelper->applyWorkflowRegionLandmark(
        $primary,
        (string) $this->t('Guided next step'),
      );
    }

    $customer_incomplete = [];
    foreach ($incomplete_definitions as $definition) {
      if ($definition->domain === 'customer') {
        $customer_incomplete[] = $definition;
      }
    }
    $progress = $this->progressHelper->buildDashboardChecklist($customer_incomplete, $context);
    if ($progress !== []) {
      $this->accessibilityHelper->applyWorkflowRegionLandmark(
        $progress,
        (string) $this->t('Your onboarding checklist'),
      );
    }

    $attachments = [
      'surface' => $context->surface->value,
      'active_ids' => array_map(static fn (WorkflowDefinition $d): string => $d->id, $active),
      'completion_ids' => array_map(static fn (WorkflowDefinition $d): string => $d->id, $completion_definitions),
      'region_primary' => $primary,
      'region_progress' => $progress,
      'empty_state_hints' => $this->buildEmptyStateHints($active),
      '#cache' => [
        'contexts' => ['route', 'user', 'user.permissions', 'languages:language_interface'],
        'tags' => [],
      ],
    ];

    if ($context->account->isAuthenticated()) {
      $attachments['#cache']['tags'][] = 'user:' . $context->account->id();
    }

    return $attachments;
  }

  /**
   * Whether MELWorkflowSystem will render a non-empty primary region on this request.
   *
   * Callers may use this to avoid duplicate dashboard CTAs that repeat the same
   * onboarding delegate URL as MelWorkflowCTAHelper.
   */
  public function willRenderPrimaryWorkflowRegion(): bool {
    try {
      $context = $this->resolver->buildContext();
      $active = $this->resolver->resolveActiveWorkflows($this->registry, $context);
    }
    catch (\Throwable $e) {
      $this->logger->error('MEL workflow primary-region preview failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }

    $completion_definitions = [];
    $incomplete_definitions = [];
    foreach ($active as $definition) {
      if ($this->resolver->isCompletionMoment($definition, $context)) {
        $completion_definitions[] = $definition;
      }
      else {
        $incomplete_definitions[] = $definition;
      }
    }

    $completion_definitions = array_values(array_filter(
      $completion_definitions,
      static function (WorkflowDefinition $d) use ($context): bool {
        if ($d->id === 'first_rsvp' && !empty($context->signals['route.rsvp_thankyou'])) {
          return FALSE;
        }
        return TRUE;
      },
    ));

    if ($completion_definitions !== []) {
      return TRUE;
    }

    return $this->ctaHelper->buildPrimaryRegion($incomplete_definitions, $context) !== [];
  }

  /**
   * @param list<\Drupal\myeventlane_surface\WorkflowDefinition> $active
   * @return array<string, string>
   */
  private function buildEmptyStateHints(array $active): array {
    $hints = [];
    foreach ($active as $definition) {
      if (!in_array($definition->id, ['first_saved_event', 'profile_completed', 'calendar_prompt'], TRUE)) {
        continue;
      }
      $hints[$definition->id] = $definition->accessibilityNotes;
    }
    return $hints;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildCompletionRegion(WorkflowDefinition $definition): array {
    return [
      '#theme' => 'mel_success_panel',
      '#heading' => (string) $this->t('You’re up to date'),
      '#heading_id' => 'mel-workflow-completion-heading',
      '#content' => [
        '#type' => 'inline_template',
        '#template' => '<p>{{ message }}</p>',
        '#context' => [
          'message' => (string) $this->t('Nice work — @workflow is complete for now.', [
            '@workflow' => ucfirst(str_replace('_', ' ', $definition->id)),
          ]),
        ],
      ],
      '#attributes' => [],
    ];
  }

}
