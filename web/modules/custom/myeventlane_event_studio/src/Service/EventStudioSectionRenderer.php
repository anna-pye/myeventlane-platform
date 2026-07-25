<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_event_attendees\Service\EventAttendeeWorkspaceBuilder;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_event_studio\DTO\ReadonlySectionProjection;
use Drupal\myeventlane_event\Service\PublicEventVisibility;
use Drupal\myeventlane_event_studio\Form\EventLaunchVisibilityForm;
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
    private readonly ?EventAttendeeWorkspaceBuilder $attendeeWorkspaceBuilder = NULL,
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
      'attendees_stack' => $this->buildAttendeesStack($event),
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
    return $this->emptyStateBuilder->unavailableSection((string) $this->t('Home'));
  }

  /**
   * Builds the Event Workspace Marketing hub for a single event.
   *
   * @return array<string, mixed>
   *   Render array for the Marketing section.
   */
  private function buildMarketingHub(NodeInterface $event): array {
    $nid = (int) $event->id();
    $publicPath = Url::fromRoute('entity.node.canonical', ['node' => $nid])->toString();
    $publicUrl = $this->domainDetector instanceof DomainDetector
      ? $this->domainDetector->absolutePublicUrl($publicPath)
      : Url::fromUserInput('/' . ltrim($publicPath, '/'))->setAbsolute()->toString();
    $boostUrl = NULL;
    try {
      $boostUrl = Url::fromRoute('myeventlane_boost.vendor_event_boost', ['event' => $nid])->toString();
    }
    catch (\Throwable) {
      $boostUrl = NULL;
    }
    $widgetsUrl = NULL;
    try {
      $widgetsUrl = Url::fromRoute('myeventlane_tickets.event_tickets_widgets', ['event' => $nid])->toString();
    }
    catch (\Throwable) {
      $widgetsUrl = NULL;
    }
    $marketingHomeUrl = NULL;
    try {
      $marketingHomeUrl = Url::fromRoute('myeventlane_vendor.console.marketing')->toString();
    }
    catch (\Throwable) {
      $marketingHomeUrl = NULL;
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

    $title = (string) $event->label();
    $facebook = 'https://www.facebook.com/sharer/sharer.php?' . UrlHelper::buildQuery(['u' => $publicUrl]);
    $linkedin = 'https://www.linkedin.com/sharing/share-offsite/?' . UrlHelper::buildQuery(['url' => $publicUrl]);
    $mailto = 'mailto:?' . UrlHelper::buildQuery([
      'subject' => (string) $this->t('Join me at @title', ['@title' => $title]),
      'body' => (string) $this->t("I thought you might like this event on MyEventLane:\n\n@url", ['@url' => $publicUrl]),
    ]);

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-event-workspace-marketing'],
        'data-mel-analytics-event' => 'marketing_opened',
      ],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Drive ticket sales for this event. Share your public page, embed a widget, or Boost visibility across MyEventLane.'),
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
          '#attributes' => ['class' => ['mel-event-workspace-marketing__url']],
        ],
        'channels' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['mel-event-workspace-marketing__channels'],
            'role' => 'group',
            'aria-label' => (string) $this->t('Share channels'),
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
          'facebook' => [
            '#type' => 'link',
            '#title' => $this->t('Facebook'),
            '#url' => Url::fromUri($facebook),
            '#attributes' => [
              'class' => ['mel-btn', 'mel-btn--secondary'],
              'target' => '_blank',
              'rel' => 'noopener noreferrer',
              'data-mel-analytics-event' => 'share_channel_selected',
            ],
          ],
          'linkedin' => [
            '#type' => 'link',
            '#title' => $this->t('LinkedIn'),
            '#url' => Url::fromUri($linkedin),
            '#attributes' => [
              'class' => ['mel-btn', 'mel-btn--secondary'],
              'target' => '_blank',
              'rel' => 'noopener noreferrer',
              'data-mel-analytics-event' => 'share_channel_selected',
            ],
          ],
          'email' => [
            '#type' => 'link',
            '#title' => $this->t('Email'),
            '#url' => Url::fromUri($mailto),
            '#attributes' => [
              'class' => ['mel-btn', 'mel-btn--secondary'],
              'data-mel-analytics-event' => 'share_channel_selected',
            ],
          ],
          'instagram' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->t('Instagram: copy the public link above, then paste it into your post or story.'),
            '#attributes' => ['class' => ['mel-event-workspace-marketing__hint']],
          ],
        ],
      ],
      'widgets' => $widgetsUrl ? [
        '#type' => 'container',
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Widgets & embeds'),
        ],
        'copy' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Add a ticket widget to your own website.'),
        ],
        'cta' => [
          '#type' => 'link',
          '#title' => $this->t('Open widgets'),
          '#url' => Url::fromRoute('myeventlane_tickets.event_tickets_widgets', ['event' => $nid]),
          '#attributes' => [
            'class' => ['mel-btn', 'mel-btn--secondary'],
            'data-mel-analytics-event' => 'widget_copied',
          ],
        ],
      ] : [],
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
          '#value' => $this->t('Feature your event in discovery so more people find you. Boost never guarantees sales.'),
        ],
        'cta' => [
          '#type' => 'link',
          '#title' => $this->t('Start Boost'),
          '#url' => Url::fromRoute('myeventlane_boost.vendor_event_boost', ['event' => $nid]),
          '#attributes' => [
            'class' => ['mel-btn', 'mel-btn--primary'],
            'data-mel-analytics-event' => 'boost_started',
          ],
        ],
      ] : [],
      'home' => $marketingHomeUrl ? [
        '#type' => 'link',
        '#title' => $this->t('Open Marketing home'),
        '#url' => Url::fromRoute('myeventlane_vendor.console.marketing'),
        '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary']],
      ] : [],
    ];
  }

  /**
   * Launch Centre composition for the Publishing section (Sprint 3C.1).
   *
   * Presentation only — reuses EventReadinessService. Hero owns publish/unpublish.
   * Does not embed EventSettingsForm or a second Publish control.
   *
   * @return array<string, mixed>
   */
  private function buildPublishingHub(NodeInterface $event): array {
    $account = $this->currentUser;
    $readiness = $this->eventReadiness->evaluate($event, $account);
    $published = $event->isPublished();
    $ready = $readiness->ready;
    $remaining = count($readiness->errors);
    $nid = (int) $event->id();

    $checklist_open = !$ready;
    $checklist_items = $this->buildLaunchChecklistItems($readiness, $nid);
    // Progress fraction is required items only (complete + blockers).
    // Warnings/ideas stay visible but must not contradict "All required items complete".
    $required_items = array_values(array_filter(
      $checklist_items,
      static fn(array $item): bool => in_array($item['tone'] ?? '', ['success', 'attention'], TRUE),
    ));
    $complete_count = count(array_filter($required_items, static fn(array $item): bool => !empty($item['complete'])));
    $total_count = count($required_items);

    $settings_url = NULL;
    try {
      $settings_url = Url::fromRoute('myeventlane_event_studio.workspace_settings', ['node' => $nid])->toString();
    }
    catch (\Throwable) {
      $settings_url = NULL;
    }

    return [
      '#theme' => 'mel_event_studio_launch_centre',
      '#launch' => [
        'state' => $published ? 'live' : ($ready ? 'ready' : 'needs_attention'),
        'published' => $published,
        'ready' => $ready,
        'headline' => $this->launchHeadline($ready, $published, $remaining),
        'explanation' => $this->launchExplanation($readiness, $published),
        'hero_hint' => $this->launchHeroHint($ready, $published),
        'checklist' => [
          'title' => (string) $this->t('Launch checklist'),
          'open' => $checklist_open,
          'summary' => $ready
            ? (string) $this->t('All required items complete')
            : (string) $this->formatPlural(
              $remaining,
              '1 thing left before you can launch',
              '@count things left before you can launch',
            ),
          'complete_count' => $complete_count,
          'total_count' => $total_count,
          'items' => $checklist_items,
        ],
        'visibility' => [
          'summary_label' => (string) $this->t('Who can find this?'),
          'current_label' => $this->currentVisibilityLabel($event),
          'open' => FALSE,
          'settings_url' => $settings_url,
          'settings_label' => (string) $this->t('More settings'),
        ],
        'after' => $this->launchAfterGuidance($event, $published),
      ],
      '#visibility_form' => $this->formBuilder->getForm(EventLaunchVisibilityForm::class, $event),
      '#cache' => [
        'contexts' => ['user', 'user.permissions'],
        'tags' => $event->getCacheTags(),
        'max-age' => 0,
      ],
    ];
  }

  /**
   * @return list<array{label: string, complete: bool, tone: string, fix_url: ?string, fix_label: ?string}>
   */
  private function buildLaunchChecklistItems(EventReadinessResult $readiness, int $nid): array {
    $items = [];
    foreach ($readiness->errors as $label) {
      $text = rtrim((string) $label, '.');
      $fix = $this->resolveLaunchFixLink($text, $nid);
      $items[] = [
        'label' => $text,
        'complete' => FALSE,
        'tone' => 'attention',
        'fix_url' => $fix['url'] ?? NULL,
        'fix_label' => $fix['label'] ?? NULL,
      ];
    }
    foreach ($readiness->warnings as $label) {
      $text = rtrim((string) $label, '.');
      $fix = $this->resolveLaunchFixLink($text, $nid);
      $items[] = [
        'label' => $text,
        'complete' => FALSE,
        'tone' => 'warning',
        'fix_url' => $fix['url'] ?? NULL,
        'fix_label' => $fix['label'] ?? NULL,
      ];
    }
    foreach ($readiness->completed as $label) {
      $items[] = [
        'label' => $this->humanChecklistLabel((string) $label),
        'complete' => TRUE,
        'tone' => 'success',
        'fix_url' => NULL,
        'fix_label' => NULL,
      ];
    }
    foreach ($readiness->recommendations as $label) {
      $text = rtrim((string) $label, '.');
      $fix = $this->resolveLaunchFixLink($text, $nid);
      $items[] = [
        'label' => $text,
        'complete' => FALSE,
        'tone' => 'idea',
        'fix_url' => $fix['url'] ?? NULL,
        'fix_label' => $fix['label'] ?? NULL,
      ];
    }
    return $items;
  }

  /**
   * Presentation-only deep links for checklist blockers (no new eligibility rules).
   *
   * @return array{url: ?string, label: ?string}
   */
  private function resolveLaunchFixLink(string $label, int $nid): array {
    $lower = mb_strtolower($label);
    $route = 'myeventlane_event_studio.workspace_details';
    $params = ['node' => $nid];
    $fix_label = (string) $this->t('Fix → Details');

    if (str_contains($lower, 'stripe') || str_contains($lower, 'payment') || str_contains($lower, 'get paid')) {
      $route = 'myeventlane_vendor.console.payments';
      $params = [];
      $fix_label = (string) $this->t('Connect Stripe');
    }
    elseif (str_contains($lower, 'organiser') || str_contains($lower, 'terms') || str_contains($lower, 'signed in') || str_contains($lower, 'profile')) {
      $route = 'myeventlane_vendor.console.settings';
      $params = [];
      $fix_label = (string) $this->t('Open account');
    }
    elseif (str_contains($lower, 'ticket') || str_contains($lower, 'capacity')) {
      $route = 'myeventlane_event_studio.workspace_tickets';
      $fix_label = (string) $this->t('Fix → Tickets');
    }
    elseif (str_contains($lower, 'cover') || str_contains($lower, 'image') || str_contains($lower, 'branding')) {
      $route = 'myeventlane_event_studio.workspace_images';
      $fix_label = (string) $this->t('Fix → Images');
    }
    elseif ($this->isLaunchScheduleFixLabel($lower)) {
      $route = 'myeventlane_event_studio.workspace_schedule';
      $fix_label = (string) $this->t('Fix → Schedule');
    }
    elseif (str_contains($lower, 'question')) {
      $route = 'myeventlane_event_studio.workspace_questions';
      $fix_label = (string) $this->t('Fix → Questions');
    }

    try {
      return [
        'url' => Url::fromRoute($route, $params)->toString(),
        'label' => $fix_label,
      ];
    }
    catch (\Throwable) {
      // Organiser/terms blockers must not fall through to Stripe Connect.
      if ($route === 'myeventlane_vendor.console.settings') {
        foreach (['myeventlane_vendor.console.settings_profile'] as $fallback) {
          try {
            return [
              'url' => Url::fromRoute($fallback)->toString(),
              'label' => $fix_label,
            ];
          }
          catch (\Throwable) {
            // Try next fallback.
          }
        }
      }
      return ['url' => NULL, 'label' => NULL];
    }
  }

  /**
   * True when a readiness label is about event schedule dates (not "attendee").
   */
  private function isLaunchScheduleFixLabel(string $lower): bool {
    if (str_contains($lower, 'schedule')) {
      return TRUE;
    }
    // Prefer explicit phrases used by EventReadinessService.
    if (str_contains($lower, 'start date') || str_contains($lower, 'end date')) {
      return TRUE;
    }
    // Word-boundary "date"/"dates" — avoids "update", keeps "Event dates are invalid".
    return preg_match('/\bdates?\b/', $lower) === 1;
  }

  private function launchHeadline(bool $ready, bool $published, int $remaining): string {
    if ($published) {
      return (string) $this->t('Your event is live');
    }
    if ($ready) {
      return (string) $this->t('Ready to launch');
    }
    if ($remaining === 1) {
      return (string) $this->t("You're almost there");
    }
    return (string) $this->t('A few things left before launching');
  }

  private function launchExplanation(EventReadinessResult $readiness, bool $published): string {
    if ($published) {
      return (string) $this->t('People can discover your event and RSVP or buy tickets according to your setup. Share from the header when you are ready.');
    }
    if ($readiness->ready) {
      return (string) $this->t("You're ready to go live. Guests will be able to discover this event and RSVP or buy tickets according to your setup.");
    }
    if (count($readiness->errors) === 1) {
      return (string) $this->t('One more thing before you can launch: @reason', [
        '@reason' => rtrim($readiness->errors[0], '.'),
      ]);
    }
    return (string) $this->t('Finish the checklist below. We never block without explaining why.');
  }

  private function launchHeroHint(bool $ready, bool $published): string {
    if ($published) {
      return (string) $this->t('Use Share event in the header to spread the word.');
    }
    if ($ready) {
      return (string) $this->t('Use Publish event in the header when you are ready.');
    }
    return (string) $this->t('Publish is unavailable until the checklist is clear. Continue setup from the header.');
  }

  /**
   * @return array{title: string, items: list<string>}
   */
  private function launchAfterGuidance(NodeInterface $event, bool $published): array {
    $event_type = 'rsvp';
    if ($event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
      $event_type = (string) $event->get('field_event_type')->value;
    }

    $join = match ($event_type) {
      'paid' => (string) $this->t('People can buy tickets'),
      'both' => (string) $this->t('People can RSVP or buy tickets'),
      'external' => (string) $this->t('People can follow your external booking link'),
      default => (string) $this->t('People can RSVP'),
    };

    $items = [
      (string) $this->t('Guests can discover your event'),
      $join,
      (string) $this->t("You'll be able to share your event"),
    ];

    return [
      'title' => $published
        ? (string) $this->t('After publishing')
        : (string) $this->t('After you publish'),
      'items' => $items,
    ];
  }

  private function currentVisibilityLabel(NodeInterface $event): string {
    $value = PublicEventVisibility::VISIBILITY_PUBLIC;
    if ($event->hasField('field_event_visibility') && !$event->get('field_event_visibility')->isEmpty()) {
      $value = (string) $event->get('field_event_visibility')->value;
    }
    return match ($value) {
      PublicEventVisibility::VISIBILITY_UNLISTED => (string) $this->t('Unlisted'),
      PublicEventVisibility::VISIBILITY_PRIVATE => (string) $this->t('Private'),
      PublicEventVisibility::VISIBILITY_PASSCODE => (string) $this->t('Passcode protected'),
      default => (string) $this->t('Public'),
    };
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
   * VX2-05 One Attendee Workspace (paid + RSVP + waitlist + door entry).
   *
   * @return array<string, mixed>
   *   Attendees workspace render stack.
   */
  private function buildAttendeesStack(NodeInterface $event): array {
    if (!$this->attendeeWorkspaceBuilder instanceof EventAttendeeWorkspaceBuilder) {
      $this->logger->error('Attendee workspace builder unavailable for event @event.', [
        '@event' => (string) $event->id(),
      ]);
      return $this->emptyStateBuilder->unavailableSection((string) $this->t('Attendees'));
    }

    try {
      $workspace = $this->attendeeWorkspaceBuilder->build($event);
    }
    catch (\Throwable $e) {
      $this->logger->error('Attendee workspace failed for event @event: @message', [
        '@event' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return $this->emptyStateBuilder->unavailableSection((string) $this->t('Attendees'));
    }

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'mel-event-studio-section__form-stack',
          'mel-event-studio-attendees-app',
        ],
        'data-mel-attendees-app' => '1',
      ],
      'workspace' => $workspace,
    ];

    $support = $this->supportResolver->buildCard($event, 'attendees');
    if ($support !== NULL) {
      $build['support'] = $support;
      $build['support']['#weight'] = 30;
    }

    return $build;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildTicketsStack(NodeInterface $event): array {
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'mel-event-studio-section__form-stack',
          'mel-event-studio-tickets-app',
        ],
        'data-mel-tickets-app' => '1',
      ],
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

    $build['advanced'] = $this->buildAdvancedTicketTools($event);
    $build['advanced']['#weight'] = 20;

    $support = $this->supportResolver->buildCard($event, 'tickets');
    if ($support !== NULL) {
      $build['support'] = $support;
      $build['support']['#weight'] = 30;
    }

    return $build;
  }

  /**
   * Progressive disclosure for groups, codes, widgets, and inventory tools.
   *
   * @return array<string, mixed>
   *   Render array for the Advanced Ticket Tools disclosure.
   */
  private function buildAdvancedTicketTools(NodeInterface $event): array {
    $event_id = (int) $event->id();
    $links = [];
    $route_map = [
      'myeventlane_tickets.event_tickets_access_codes' => $this->t('Access codes'),
      'myeventlane_tickets.event_tickets_groups' => $this->t('Ticket groups'),
      'myeventlane_tickets.event_tickets_widgets' => $this->t('Ticket widgets'),
      'myeventlane_tickets.event_tickets_settings' => $this->t('Ticket settings'),
      'myeventlane_vendor.console.event_tickets' => $this->t('Inventory & sync tools'),
    ];
    foreach ($route_map as $route_name => $label) {
      try {
        $links[] = [
          '#type' => 'link',
          '#title' => $label,
          '#url' => Url::fromRoute($route_name, ['event' => $event_id]),
          '#attributes' => [
            'class' => ['mel-event-studio-advanced-tools__link'],
          ],
        ];
      }
      catch (\Throwable) {
        // Route may be unavailable in partial installs; skip quietly.
      }
    }

    $advanced = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Ticket Tools'),
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['mel-es-card', 'mel-event-studio-advanced-tools'],
        'data-mel-analytics-event' => 'advanced_tools_opened',
        'data-mel-event-id' => (string) $event_id,
      ],
      '#attached' => [
        'library' => [
          'myeventlane_event_studio/mel_event_studio_tickets_app',
        ],
      ],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Most organisers only need ticket types, pricing, capacity, and availability. Open these tools when you need access codes, groups, embeds, or deeper inventory controls.'),
        '#attributes' => ['class' => ['mel-event-studio-advanced-tools__intro']],
      ],
      'links' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-advanced-tools__links'],
          'role' => 'list',
        ],
      ],
    ];

    foreach ($links as $index => $link) {
      $advanced['links']['item_' . $index] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-advanced-tools__item'],
          'role' => 'listitem',
        ],
        'link' => $link,
      ];
    }

    $event_type = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';
    if (in_array($event_type, ['paid', 'both'], TRUE)
      && $this->accessCodeManagementBuilder instanceof AccessCodeManagementBuilder) {
      $accessCodePanel = $this->accessCodeManagementBuilder->build($event);
      if ($accessCodePanel !== []) {
        $advanced['access_codes'] = $accessCodePanel;
        $advanced['access_codes']['#weight'] = 20;
      }
    }

    return $advanced;
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
