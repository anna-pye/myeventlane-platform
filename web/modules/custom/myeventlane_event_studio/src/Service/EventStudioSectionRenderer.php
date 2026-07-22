<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_event_studio\DTO\ReadonlySectionProjection;
use Drupal\myeventlane_event_studio\Form\EventSettingsForm;
use Drupal\myeventlane_event_studio\Form\EventStudioOperationalTicketsForm;
use Drupal\myeventlane_event_studio\Form\EventStudioTicketsForm;
use Drupal\myeventlane_event_studio\Plugin\EventStudioSection\EventStudioSectionInterface;
use Drupal\myeventlane_event_studio\Support\MelSupportResolverInterface;
use Drupal\myeventlane_metrics\Service\EventMetricsServiceInterface;
use Drupal\myeventlane_tickets\Service\AccessCodeManagementBuilder;
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
    private readonly MelSupportResolverInterface $supportResolver,
    private readonly ?EventCapacityServiceInterface $capacityService = NULL,
    private readonly ?EventMetricsServiceInterface $metricsService = NULL,
    private readonly ?EventTicketPreviewBuilder $eventTicketPreviewBuilder = NULL,
    private readonly ?AccessCodeManagementBuilder $accessCodeManagementBuilder = NULL,
    private readonly ?EventWorkspaceOverviewBuilder $overviewBuilder = NULL,
    private readonly ?DomainDetector $domainDetector = NULL,
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
      'marketing_hub' => $this->buildMarketingHub($event),
      'publishing_hub' => $this->buildPublishingHub($event),
      default => $this->buildUnknownTarget($section, $target),
    };
  }

  /**
   * Builds sidebar guidance shown beside all Studio sections.
   *
   * @return array<string, mixed>
   */
  public function buildSidebarGuidance(NodeInterface $event): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-studio-sidebar-guidance']],
      'next_steps' => $this->buildNextStepsCard(),
    ];

    $support = $this->supportResolver->buildCard($event, 'sidebar');
    if ($support !== NULL) {
      $build['support'] = $support;
    }

    return $build;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildOverviewSection(NodeInterface $event): array {
    if ($this->overviewBuilder instanceof EventWorkspaceOverviewBuilder) {
      return $this->overviewBuilder->build($event, $this->currentUser);
    }

    $this->logger->error('Event Workspace overview builder unavailable for event @event.', [
      '@event' => (string) $event->id(),
    ]);
    return $this->emptyStateBuilder->unavailableSection((string) $this->t('Overview'));
  }

  /**
   * @return array<string, mixed>
   */
  private function buildMarketingHub(NodeInterface $event): array {
    $nid = (int) $event->id();
    $publicPath = Url::fromRoute('entity.node.canonical', ['node' => $nid])->toString();
    $publicUrl = $this->domainDetector instanceof DomainDetector
      ? $this->domainDetector->publicUrl($publicPath)
      : $publicPath;
    $boostUrl = NULL;
    try {
      $boostUrl = Url::fromRoute('myeventlane_boost.vendor_event_boost', ['event' => $nid])->toString();
    }
    catch (\Throwable) {
      $boostUrl = NULL;
    }

    if (!$event->isPublished()) {
      return $this->emptyStateBuilder->build(
        (string) $this->t('Marketing'),
        (string) $this->t('Publish your event to start sharing and Boosting it.'),
        (string) $this->t('Once live, you can copy your public link and run Boost from here.'),
        [
          (string) $this->t('Finish publishing first.'),
          (string) $this->t('Then share your page with your community.'),
        ],
        'spark',
        'default',
        [
          '#type' => 'link',
          '#title' => $this->t('Go to publishing'),
          '#url' => Url::fromRoute('myeventlane_event_studio.workspace_publishing', ['node' => $nid]),
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
        ],
      );
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-workspace-marketing']],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Drive ticket sales for this event. Share your public page or Boost visibility across MyEventLane.'),
      ],
      'share' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-workspace-marketing__share']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Share your event'),
        ],
        'url' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $publicUrl,
        ],
        'view' => [
          '#type' => 'link',
          '#title' => $this->t('View page'),
          '#url' => Url::fromRoute('entity.node.canonical', ['node' => $nid]),
          '#attributes' => [
            'class' => ['mel-btn', 'mel-btn--secondary'],
            'target' => '_blank',
            'rel' => 'noopener noreferrer',
          ],
        ],
      ],
      'boost' => $boostUrl ? [
        '#type' => 'container',
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Boost'),
        ],
        'copy' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Feature your event in discovery so more people find you.'),
        ],
        'cta' => [
          '#type' => 'link',
          '#title' => $this->t('Start Boost'),
          '#url' => Url::fromRoute('myeventlane_boost.vendor_event_boost', ['event' => $nid]),
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
        ],
      ] : [],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildPublishingHub(NodeInterface $event): array {
    $account = $this->currentUser;
    $readiness = $this->eventReadiness->evaluate($event, $account);
    $remaining = count($readiness->errors);
    $headline = $readiness->ready
      ? ($event->isPublished()
        ? (string) $this->t('Your event is live')
        : (string) $this->t('Ready to publish'))
      : ($remaining === 1
        ? (string) $this->t("You're almost there…")
        : (string) $this->t('A few things left before publishing'));
    $explanation = $readiness->ready
      ? (string) $this->t('Everything needed to go live looks good.')
      : ($remaining === 1 && $readiness->errors !== []
        ? (string) $this->t('One more thing before publishing: @reason', ['@reason' => $readiness->errors[0]])
        : (string) $this->t('Finish the checklist below. We never block without explaining why.'));

    $checklist = [];
    foreach ($readiness->completed as $item) {
      $checklist[] = ['#markup' => '✔ ' . $this->humanChecklistLabel((string) $item)];
    }
    foreach ($readiness->errors as $item) {
      $checklist[] = ['#markup' => '○ ' . (string) $item];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-workspace-publishing']],
      'headline' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $headline,
      ],
      'explain' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $explanation,
      ],
      'checklist' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Publishing checklist'),
        '#items' => $checklist,
      ],
      'settings' => $this->formBuilder->getForm(EventSettingsForm::class, $event),
    ];
  }

  private function humanChecklistLabel(string $label): string {
    return match ($label) {
      'Event title added.', 'Event title' => (string) $this->t('Event title'),
      'Event dates complete.', 'Schedule' => (string) $this->t('Schedule'),
      'Booking mode selected.', 'Booking mode selected' => (string) $this->t('Booking mode'),
      'Ticketing configured.', 'Tickets ready' => (string) $this->t('Tickets ready'),
      'Payment onboarding complete.', 'Payments connected' => (string) $this->t('Payments connected'),
      'Vendor publish requirements complete.', 'Organiser profile ready' => (string) $this->t('Organiser profile ready'),
      'Branding image added.', 'Cover image' => (string) $this->t('Cover image'),
      'Capacity settings valid.', 'Capacity' => (string) $this->t('Capacity'),
      default => rtrim($label, '.'),
    };
  }

  /**
   * @return array<string, mixed>
   */
  private function buildNextStepsCard(): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-studio-next-steps']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Suggested next steps'),
        '#attributes' => ['class' => ['mel-event-studio-next-steps__title']],
      ],
      'items' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Start with Details so guests know what the event is about.'),
          $this->t('Confirm tickets or RSVP before sharing the page.'),
          $this->t('Use Publishing when you are ready to go live.'),
        ],
        '#attributes' => ['class' => ['mel-event-studio-next-steps__list']],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildTicketsStack(NodeInterface $event): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-studio-section__form-stack']],
      'mode' => $this->formBuilder->getForm(EventStudioTicketsForm::class, $event),
    ];

    $build['operational'] = $this->formBuilder->getForm(EventStudioOperationalTicketsForm::class, $event);
    $build['operational']['#weight'] = 5;

    if ($this->eventTicketPreviewBuilder instanceof EventTicketPreviewBuilder) {
      $preview = $this->eventTicketPreviewBuilder->build($event);
      if ($preview !== []) {
        $build['ticket_preview'] = $preview;
        $build['ticket_preview']['#weight'] = 10;
      }
    }

    $event_type = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';
    if (in_array($event_type, ['paid', 'both'], TRUE)) {
      if ($this->accessCodeManagementBuilder instanceof AccessCodeManagementBuilder) {
        $accessCodePanel = $this->accessCodeManagementBuilder->build($event);
        if ($accessCodePanel !== []) {
          $build['access_codes'] = $accessCodePanel;
          $build['access_codes']['#weight'] = 15;
        }
      }

      $support = $this->supportResolver->buildCard($event, 'tickets');
      if ($support !== NULL) {
        $build['support'] = $support;
      }
    }

    return $build;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildSettingsSection(NodeInterface $event): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-studio-section__form-stack']],
      'settings' => $this->formBuilder->getForm(EventSettingsForm::class, $event),
    ];

    $support = $this->supportResolver->buildCard($event, 'settings');
    if ($support !== NULL) {
      $build['support'] = $support;
    }

    return $build;
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
        '#attributes' => ['class' => ['mel-event-studio-readonly__intro']],
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
        '#attributes' => ['class' => ['mel-event-studio-readonly__intro']],
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
  private function buildReadinessCard(EventReadinessResult $result, bool $compact = FALSE): array {
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => array_filter([
          'mel-readiness-card',
          $compact ? 'mel-readiness-card--compact' : NULL,
          $result->ready ? 'is-ready' : 'needs-attention',
        ]),
      ],
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

    if ($result->recommendations !== []) {
      $build['recommendations_title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h4',
        '#value' => $this->t('Recommendations'),
        '#attributes' => ['class' => ['mel-readiness-card__heading']],
      ];
      $build['recommendations'] = [
        '#theme' => 'item_list',
        '#items' => $result->recommendations,
        '#attributes' => ['class' => ['mel-readiness-card__list', 'mel-readiness-card__list--recommendations']],
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
