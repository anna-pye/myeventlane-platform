<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event_studio\EventStudioSectionManager;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
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
    $sectionPlugin = $this->sectionManager->section($section);
    if ($sectionPlugin === NULL) {
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
    $sectionMetadata = $this->sectionManager->sectionMetadata($sectionPlugin);

    return [
      '#theme' => 'mel_event_studio_workspace',
      '#node' => $node,
      '#sections' => $this->sectionManager->buildNavigation($node, $account, $section),
      '#current_section' => $section,
      '#current_section_label' => $this->sectionManager->sectionTitle($section),
      '#current_section_metadata' => $sectionMetadata,
      '#section_content' => $sectionPlugin->build($node),
      '#topbar' => $this->buildTopbar($node, $readiness, $section),
      '#readiness' => $this->buildReadinessSummary($readiness),
      '#attached' => [
        'library' => ['myeventlane_event_studio/mel_event_studio_shell_only'],
        'drupalSettings' => [
          'myeventlaneEventStudio' => [
            'autosaveUrl' => Url::fromRoute('myeventlane_event_studio.autosave')->toString(),
            'autosaveDelay' => 12000,
            'currentSection' => $section,
            'currentSectionWritable' => $sectionPlugin->isWritable(),
            'currentSectionState' => $sectionPlugin->sectionState(),
            'currentSectionCapabilities' => [
              'writable' => $sectionPlugin->isWritable(),
              'readonly' => $sectionPlugin->sectionState() === 'readonly',
              'deferred' => $sectionPlugin->isDeferred(),
              'supports_autosave' => $sectionPlugin->supportsAutosave(),
              'supports_publish' => $sectionPlugin->supportsPublish(),
              'supports_readiness' => $sectionPlugin->supportsReadiness(),
              'supports_mobile_priority' => $sectionPlugin->supportsMobilePriority(),
            ],
            'draftAvailable' => $this->autosaveService->hasDraft($node, $section),
            'publishUrl' => Url::fromRoute('myeventlane_event_studio.publish', ['node' => $node->id()])->toString(),
            'nodeChanged' => $node->getChangedTime(),
            'nodeRevisionId' => (int) $node->getRevisionId(),
            'published' => $node->isPublished(),
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
      'publish_url' => Url::fromRoute('myeventlane_event_studio.publish', ['node' => $node->id()])->toString(),
      'published' => $node->isPublished(),
      'can_publish' => $readiness->ready,
      'current_section' => $section,
      'changed' => $node->getChangedTime(),
      'revision_id' => (int) $node->getRevisionId(),
    ];
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
