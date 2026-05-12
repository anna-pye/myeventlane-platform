<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_event_studio\DTO\ReadonlySectionProjection;
use Drupal\myeventlane_event_studio\Form\EventSettingsForm;
use Drupal\myeventlane_event_studio\Form\EventStudioOperationalTicketsForm;
use Drupal\myeventlane_event_studio\Form\EventStudioTicketsForm;
use Drupal\myeventlane_event_studio\Plugin\EventStudioSection\EventStudioSectionInterface;
use Drupal\myeventlane_metrics\Service\EventMetricsServiceInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds governed Event Studio section content from plugin render contracts.
 */
final class EventStudioSectionRenderer {

  use StringTranslationTrait;

  public function __construct(
    private readonly FormBuilderInterface $formBuilder,
    private readonly EventReadinessService $eventReadiness,
    private readonly EventStudioEmptyStateBuilder $emptyStateBuilder,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
    private readonly ?EventCapacityServiceInterface $capacityService = NULL,
    private readonly ?EventMetricsServiceInterface $metricsService = NULL,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds the section content declared by a section plugin.
   *
   * @return array<string, mixed>
   */
  public function build(EventStudioSectionInterface $section, NodeInterface $event): array {
    if ($section->sectionState() === EventStudioSectionInterface::STATE_DEFERRED) {
      return $this->emptyStateBuilder->deferredSection($section->title(), (string) $section->getPluginId());
    }
    if ($section->sectionState() === EventStudioSectionInterface::STATE_COMING_SOON) {
      return $this->emptyStateBuilder->comingSoonSection($section->title());
    }
    if ($section->sectionState() === EventStudioSectionInterface::STATE_READONLY) {
      return $this->buildReadonlySection($section, $event);
    }

    $target = $section->renderTarget();
    if (str_starts_with($target, 'form:')) {
      $formClass = substr($target, 5);
      if ($formClass === '' || !class_exists($formClass)) {
        $this->logger->error('Event Studio section @section references missing form target @target.', [
          '@section' => (string) $section->getPluginId(),
          '@target' => $target,
        ]);
        return $this->emptyStateBuilder->unavailableSection($section->title());
      }
      return $this->formBuilder->getForm($formClass, $event);
    }

    return match ($target) {
      'overview' => $this->buildOverviewSection($event),
      'tickets_stack' => $this->buildTicketsStack($event),
      'settings_with_readiness' => $this->buildSettingsSection($event),
      'capacity_summary' => $this->buildCapacitySection($event, $section),
      default => $this->buildUnknownTarget($section, $target),
    };
  }

  /**
   * @return array<string, mixed>
   */
  private function buildOverviewSection(NodeInterface $event): array {
    $readiness = $this->eventReadiness->evaluate($event, $this->currentUser);
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-studio-section__placeholder']],
      'summary' => [
        '#markup' => '<p>' . $this->t('Use the sidebar to manage one operational area at a time. Event information, content, tickets, and publishing now load as independent Studio sections.') . '</p>',
      ],
      'actions' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Review event information before publishing.'),
          $this->t('Manage tickets through the existing MEL ticket lifecycle path.'),
          $this->t('Use Settings to review readiness and publish without leaving Studio.'),
        ],
      ],
      'readiness' => $this->buildReadinessCard($readiness),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildTicketsStack(NodeInterface $event): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-studio-section__form-stack']],
      'mode' => $this->formBuilder->getForm(EventStudioTicketsForm::class, $event),
      'operational' => $this->formBuilder->getForm(EventStudioOperationalTicketsForm::class, $event),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildSettingsSection(NodeInterface $event): array {
    $readiness = $this->eventReadiness->evaluate($event, $this->currentUser);
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-studio-section__form-stack']],
      'readiness' => $this->buildReadinessCard($readiness),
      'settings' => $this->formBuilder->getForm(EventSettingsForm::class, $event),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildCapacitySection(NodeInterface $event, EventStudioSectionInterface $section): array {
    if (!$this->capacityService instanceof EventCapacityServiceInterface) {
      $this->logger->error('Event Studio capacity section cannot render because myeventlane_capacity.service is unavailable for event @event.', [
        '@event' => (string) $event->id(),
      ]);
      return $this->emptyStateBuilder->unavailableSection($section->title());
    }

    try {
      $total = $this->capacityService->getCapacityTotal($event);
      $sold = $this->capacityService->getSoldCount($event);
      $remaining = $this->capacityService->getRemaining($event);
      $soldOut = $this->capacityService->isSoldOut($event);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio capacity summary failed for event @event: @message', [
        '@event' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return $this->emptyStateBuilder->unavailableSection($section->title());
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-event-studio-readonly', 'mel-event-studio-readonly--capacity'],
        'data-mel-section-writable' => '0',
      ],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Capacity is enforced by the ticket and RSVP lifecycle. This panel shows the current operational capacity state without changing booking rules.'),
      ],
      'summary' => [
        '#type' => 'table',
        '#header' => [$this->t('Metric'), $this->t('Value')],
        '#rows' => [
          [$this->t('Total capacity'), $total === NULL ? $this->t('Unlimited') : (string) $total],
          [$this->t('Sold or confirmed'), (string) $sold],
          [$this->t('Remaining'), $remaining === NULL ? $this->t('Unlimited') : (string) $remaining],
          [$this->t('Status'), $soldOut ? $this->t('Sold out') : $this->t('Available')],
        ],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildReadonlySection(EventStudioSectionInterface $section, NodeInterface $event): array {
    return match ((string) $section->getPluginId()) {
      'attendees' => $this->buildAttendeeReadonlySection($section, $event),
      'analytics' => $this->buildAnalyticsReadonlySection($section, $event),
      'orders' => $this->buildReadonlyProjection(new ReadonlySectionProjection($section->title(), [], (string) $this->t('Order reporting will appear here after a paginated, event-scoped order read model is connected.'))),
      default => $this->emptyStateBuilder->readonlyEmptySection($section->title()),
    };
  }

  /**
   * @return array<string, mixed>
   */
  private function buildAttendeeReadonlySection(EventStudioSectionInterface $section, NodeInterface $event): array {
    if (!$this->metricsService instanceof EventMetricsServiceInterface) {
      return $this->emptyStateBuilder->readonlyEmptySection($section->title());
    }

    try {
      $attendeeCount = $this->metricsService->getAttendeeCount($event);
      $checkedInCount = $this->metricsService->getCheckedInCount($event);
      $checkInRate = $this->metricsService->getCheckInRate($event);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio attendee readonly summary failed for event @event: @message', [
        '@event' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return $this->emptyStateBuilder->readonlyEmptySection($section->title());
    }

    return $this->buildReadonlyProjection(new ReadonlySectionProjection($section->title(), [
      [$this->t('Total attendees'), (string) $attendeeCount],
      [$this->t('Checked in'), (string) $checkedInCount],
      [$this->t('Check-in rate'), $checkInRate === NULL ? $this->t('Not available yet') : $this->t('@rate%', ['@rate' => number_format($checkInRate, 1)])],
    ]));
  }

  /**
   * @return array<string, mixed>
   */
  private function buildAnalyticsReadonlySection(EventStudioSectionInterface $section, NodeInterface $event): array {
    if (!$this->metricsService instanceof EventMetricsServiceInterface) {
      return $this->emptyStateBuilder->readonlyEmptySection($section->title());
    }

    try {
      $attendeeCount = $this->metricsService->getAttendeeCount($event);
      $revenue = $this->metricsService->getRevenue($event);
      $checkInRate = $this->metricsService->getCheckInRate($event);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio analytics readonly summary failed for event @event: @message', [
        '@event' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return $this->emptyStateBuilder->readonlyEmptySection($section->title());
    }

    $revenueText = $revenue === NULL
      ? $this->t('Not available yet')
      : $this->t('@amount @currency', [
        '@amount' => $revenue->getNumber(),
        '@currency' => $revenue->getCurrencyCode(),
      ]);

    return $this->buildReadonlyProjection(new ReadonlySectionProjection($section->title(), [
      [$this->t('Attendees'), (string) $attendeeCount],
      [$this->t('Ticket revenue'), $revenueText],
      [$this->t('Check-in rate'), $checkInRate === NULL ? $this->t('Not available yet') : $this->t('@rate%', ['@rate' => number_format($checkInRate, 1)])],
    ]));
  }

  /**
   * @return array<string, mixed>
   */
  private function buildReadonlyProjection(ReadonlySectionProjection $projection): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-event-studio-readonly'],
        'data-mel-section-writable' => '0',
      ],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $projection->intro ?? $this->t('@section is readonly in Studio. Reporting data is event-scoped and does not mutate operational state.', [
          '@section' => $projection->title,
        ]),
      ],
      'summary' => [
        '#type' => 'table',
        '#header' => [$this->t('Metric'), $this->t('Value')],
        '#rows' => $projection->rows,
        '#empty' => $this->t('No reporting data is available yet.'),
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildUnknownTarget(EventStudioSectionInterface $section, string $target): array {
    $this->logger->error('Event Studio section @section declares unsupported render target @target.', [
      '@section' => (string) $section->getPluginId(),
      '@target' => $target,
    ]);
    return $this->emptyStateBuilder->unavailableSection($section->title());
  }

  /**
   * @return array<string, mixed>
   */
  private function buildReadinessCard(EventReadinessResult $result): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-readiness-card', $result->ready ? 'is-ready' : 'needs-attention']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $result->ready ? $this->t('Ready to publish') : $this->t('Needs attention'),
        '#attributes' => ['class' => ['mel-readiness-card__title']],
      ],
      'summary' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $result->ready
          ? $this->t('No publish blockers detected. Review warnings before going live.')
          : $this->t('Resolve the blocking items before publishing.'),
        '#attributes' => ['class' => ['mel-readiness-card__summary']],
      ],
    ];

    if ($result->errors !== []) {
      $build['errors_title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h4',
        '#value' => $this->t('Blocking errors'),
        '#attributes' => ['class' => ['mel-readiness-card__heading']],
      ];
      $build['errors'] = [
        '#theme' => 'item_list',
        '#items' => $result->errors,
        '#attributes' => ['class' => ['mel-readiness-card__list', 'mel-readiness-card__list--errors']],
      ];
    }

    if ($result->warnings !== []) {
      $build['warnings_title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h4',
        '#value' => $this->t('Warnings'),
        '#attributes' => ['class' => ['mel-readiness-card__heading']],
      ];
      $build['warnings'] = [
        '#theme' => 'item_list',
        '#items' => $result->warnings,
        '#attributes' => ['class' => ['mel-readiness-card__list', 'mel-readiness-card__list--warnings']],
      ];
    }

    if ($result->completed !== []) {
      $build['completed_title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h4',
        '#value' => $this->t('Complete'),
        '#attributes' => ['class' => ['mel-readiness-card__heading']],
      ];
      $build['completed'] = [
        '#theme' => 'item_list',
        '#items' => $result->completed,
        '#attributes' => ['class' => ['mel-readiness-card__list', 'mel-readiness-card__list--completed']],
      ];
    }

    return $build;
  }

}
