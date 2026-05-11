<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event_studio\EventStudioSectionManager;
use Drupal\myeventlane_event_studio\Form\EventBrandingForm;
use Drupal\myeventlane_event_studio\Form\EventCheckoutQuestionsForm;
use Drupal\myeventlane_event_studio\Form\EventContentForm;
use Drupal\myeventlane_event_studio\Form\EventInformationForm;
use Drupal\myeventlane_event_studio\Form\EventStudioOperationalTicketsForm;
use Drupal\myeventlane_event_studio\Form\EventStudioTicketsForm;
use Drupal\myeventlane_event_studio\Form\EventPromotionForm;
use Drupal\myeventlane_event_studio\Form\EventSettingsForm;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\myeventlane_event_studio\Service\EventStudioEmptyStateBuilder;
use Drupal\myeventlane_event_studio\Service\EventReadinessService;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\myeventlane_vendor\Service\VendorEventStudioCreateService;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Event Studio pages. Route methods are buildCreate / buildEdit (not create — conflicts with ControllerBase::create()).
 */
final class EventStudioController extends ControllerBase {

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    private readonly VendorEventStudioCreateService $eventStudioCreate,
    private readonly LoggerInterface $logger,
    private readonly RequestStack $requestStack,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EventReadinessService $eventReadiness,
    private readonly EventStudioAutosaveService $autosaveService,
    private readonly EventStudioSectionManager $sectionManager,
    private readonly EventStudioEmptyStateBuilder $emptyStateBuilder,
  ) {
    // ControllerBase / EntityTypeManagerTrait already declare protected $entityTypeManager.
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_vendor.event_studio_create'),
      $container->get('logger.factory')->get('myeventlane_event_studio'),
      $container->get('request_stack'),
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->get('date.formatter'),
      $container->get('myeventlane_event_studio.readiness'),
      $container->get('myeventlane_event_studio.autosave'),
      $container->get('plugin.manager.myeventlane_event_studio_section'),
      $container->get('myeventlane_event_studio.empty_state_builder'),
    );
  }

  public function buildCreate(): RedirectResponse|array {
    $uid = (int) $this->currentUser()->id();
    $this->logger->notice('Vendor Event Studio entry: route=myeventlane_event_studio.create studio_selected=1 uid=@uid', [
      '@uid' => (string) $uid,
    ]);

    $request = $this->requestStack->getCurrentRequest();
    $destination = '/vendor/events/create';
    if ($request !== NULL && $request->query->count() > 0) {
      $destination .= '?' . http_build_query($request->query->all(), '', '&', PHP_QUERY_RFC3986);
    }

    $draft_nid = $this->eventStudioCreate->findLatestUnpublishedEventNidForUser($uid);
    if ($draft_nid !== NULL) {
      $route_params = ['node' => $draft_nid];
      $url_options = [];
      if ($request !== NULL && $request->query->count() > 0) {
        $url_options['query'] = $request->query->all();
      }
      return new RedirectResponse(Url::fromRoute('myeventlane_event_studio.workspace', $route_params, $url_options)->toString());
    }

    $node = $this->eventStudioCreate->createDraftEventForUser($uid);

    $new_id = (int) $node->id();
    $url_options = [];
    if ($request !== NULL && $request->query->count() > 0) {
      $url_options['query'] = $request->query->all();
    }
    $this->logger->notice('Event Studio create: new draft node @nid redirect to edit uid=@uid', [
      '@nid' => (string) $new_id,
      '@uid' => (string) $uid,
    ]);

    return new RedirectResponse(Url::fromRoute('myeventlane_event_studio.workspace', ['node' => $new_id], $url_options)->toString());
  }

  public function buildEdit(NodeInterface $node): RedirectResponse {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    $account = $this->currentUser();
    if (!$account->hasPermission('administer nodes')
      && !$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($node, $account)) {
      throw new AccessDeniedHttpException();
    }
    $this->logger->notice('Vendor Event Studio entry: route=myeventlane_event_studio.edit event_id=@eid uid=@uid', [
      '@eid' => (string) $node->id(),
      '@uid' => (string) $this->currentUser()->id(),
    ]);
    return new RedirectResponse(Url::fromRoute('myeventlane_event_studio.workspace', ['node' => $node->id()])->toString());
  }

  public function redirectEventToTicketsWorkspace(NodeInterface $event): RedirectResponse {
    if ($event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    return new RedirectResponse(Url::fromRoute('myeventlane_event_studio.workspace_tickets', ['node' => $event->id()])->toString());
  }

  /**
   * Builds the canonical operational Event Studio shell.
   *
   * @return array<string, mixed>
   */
  public function workspace(NodeInterface $node, string $section = 'overview'): array {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    $account = $this->currentUser();
    if (!$account->hasPermission('administer nodes')
      && !$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($node, $account)) {
      throw new AccessDeniedHttpException();
    }

    if (!$this->sectionManager->hasSection($section)) {
      throw new NotFoundHttpException();
    }
    if (!$this->sectionManager->sectionAccess($section, $node, $account)->isAllowed()) {
      throw new AccessDeniedHttpException();
    }

    $this->logger->notice('Vendor Event Studio workspace: event_id=@eid section=@section uid=@uid', [
      '@eid' => (string) $node->id(),
      '@section' => $section,
      '@uid' => (string) $account->id(),
    ]);

    $readiness = $this->eventReadiness->evaluate($node, $account);

    return [
      '#theme' => 'mel_event_studio_workspace',
      '#node' => $node,
      '#sections' => $this->sectionManager->buildNavigation($node, $account, $section),
      '#current_section' => $section,
      '#current_section_label' => $this->sectionManager->sectionTitle($section),
      '#section_content' => $this->buildSectionContent($node, $section, $readiness),
      '#topbar' => $this->buildTopbar($node, $readiness, $section),
      '#readiness' => $this->buildReadinessSummary($readiness),
      '#attached' => [
        'library' => ['myeventlane_event_studio/mel_event_studio_shell_only'],
        'drupalSettings' => [
          'myeventlaneEventStudio' => [
            'autosaveUrl' => Url::fromRoute('myeventlane_event_studio.autosave')->toString(),
            'autosaveDelay' => 12000,
            'currentSection' => $section,
            'draftAvailable' => $this->autosaveService->hasDraft($node, $section),
          ],
        ],
      ],
      '#cache' => [
        'tags' => $node->getCacheTags(),
        'contexts' => ['route', 'user', 'user.permissions'],
      ],
    ];
  }

  public function workspaceTitle(NodeInterface $node): string {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    return (string) $this->t('@title Studio', ['@title' => $node->label()]);
  }

  public function editTitle(NodeInterface $node): string {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    return (string) $this->t('Edit event — @title', ['@title' => $node->getTitle()]);
  }

  /**
   * @return array<string, mixed>
   */
  private function buildTopbar(NodeInterface $node, EventReadinessResult $readiness, string $section): array {
    $state = $node->isPublished()
      ? $this->t('Published')
      : $this->operationalState($readiness);

    return [
      'title' => $node->label(),
      'status' => $node->isPublished() ? $this->t('Published') : $this->t('Draft'),
      'state' => $state,
      'last_saved' => $node->getChangedTime() > 0 ? $this->t('Last saved @time', [
        '@time' => $this->dateFormatter->format($node->getChangedTime(), 'short'),
      ]) : $this->t('Not saved yet'),
      'restore_draft' => $this->autosaveService->hasDraft($node, $section),
      'restore_url' => Url::fromRoute($this->sectionManager->sectionRouteName($section), [
        'node' => $node->id(),
      ], [
        'query' => ['restore_draft' => '1'],
      ])->toString(),
      'preview_url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString(),
      'publish_url' => Url::fromRoute('myeventlane_event_studio.workspace_settings', ['node' => $node->id()])->toString(),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildSectionContent(NodeInterface $node, string $section, EventReadinessResult $readiness): array {
    return match ($section) {
      'information' => $this->formBuilder()->getForm(EventInformationForm::class, $node),
      'branding' => $this->formBuilder()->getForm(EventBrandingForm::class, $node),
      'content' => $this->formBuilder()->getForm(EventContentForm::class, $node),
      'tickets' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-studio-section__form-stack']],
        'mode' => $this->formBuilder()->getForm(EventStudioTicketsForm::class, $node),
        'operational' => $this->formBuilder()->getForm(EventStudioOperationalTicketsForm::class, $node),
      ],
      'questions' => $this->formBuilder()->getForm(EventCheckoutQuestionsForm::class, $node),
      'promotions' => $this->formBuilder()->getForm(EventPromotionForm::class, $node),
      'settings' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-studio-section__form-stack']],
        'readiness' => $this->buildReadinessCard($readiness),
        'settings' => $this->formBuilder()->getForm(EventSettingsForm::class, $node),
      ],
      'overview' => $this->buildOverviewSection($node, $readiness),
      default => $this->buildDeferredSection($section),
    };
  }

  /**
   * @return array<string, mixed>
   */
  private function buildOverviewSection(NodeInterface $node, EventReadinessResult $readiness): array {
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
  private function buildDeferredSection(string $section): array {
    $label = $this->sectionManager->sectionTitle($section);
    return $this->emptyStateBuilder->deferredSection($label);
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

  /**
   * @return array{ready: bool, errors: list<string>, warnings: list<string>, completed: list<string>, state: string}
   */
  private function buildReadinessSummary(EventReadinessResult $result): array {
    return [
      'ready' => $result->ready,
      'errors' => $result->errors,
      'warnings' => $result->warnings,
      'completed' => $result->completed,
      'state' => $this->operationalState($result),
    ];
  }

  private function operationalState(EventReadinessResult $result): string {
    if (!$result->ready) {
      return (string) $this->t('Needs Attention');
    }
    return (string) $this->t('Ready');
  }

}
