<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormAjaxException;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_event_studio\EventStudioPreprocess;
use Drupal\myeventlane_event_studio\EventStudioSectionManager;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_event_studio\Plugin\EventStudioSection\EventStudioSectionInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\myeventlane_event_studio\Service\EventStudioEmptyStateBuilder;
use Drupal\myeventlane_event_studio\Service\EventReadinessFacade;
use Drupal\myeventlane_event_studio\Service\EventStudioWorkspacePresentation;
use Drupal\myeventlane_event_studio\Service\EventStudioSectionRenderer;
use Drupal\myeventlane_boost\Service\BoostExtensionRecommendationService;
use Drupal\myeventlane_vendor\Service\BoostStatusService;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\myeventlane_vendor\Service\VendorEventStudioCreateService;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Session\AccountInterface;
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
    private readonly EventReadinessFacade $readinessFacade,
    private readonly EventStudioAutosaveService $autosaveService,
    private readonly EventStudioSectionManager $sectionManager,
    private readonly EventStudioSectionRenderer $sectionRenderer,
    private readonly EventStudioEmptyStateBuilder $emptyStateBuilder,
    private readonly DomainDetector $domainDetector,
    private readonly EventStudioPreprocess $eventStudioPreprocess,
    private readonly BoostStatusService $boostStatusService,
    private readonly EventStudioWorkspacePresentation $workspacePresentation,
    private readonly ?BoostExtensionRecommendationService $boostExtensionRecommendation = NULL,
  ) {
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
      $container->get('myeventlane_event_studio.readiness_facade'),
      $container->get('myeventlane_event_studio.autosave'),
      $container->get('plugin.manager.myeventlane_event_studio_section'),
      $container->get('myeventlane_event_studio.section_renderer'),
      $container->get('myeventlane_event_studio.empty_state_builder'),
      $container->get('myeventlane_core.domain_detector'),
      $container->get('myeventlane_event_studio.preprocess'),
      $container->get('myeventlane_vendor.service.boost_status'),
      $container->get('myeventlane_event_studio.workspace_presentation'),
      $container->get('myeventlane_boost.extension_recommendation'),
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
    return new RedirectResponse(Url::fromRoute('myeventlane_event_studio.workspace_information', ['node' => $node->id()])->toString());
  }

  public function redirectEventToTicketsWorkspace(NodeInterface $event): RedirectResponse {
    if ($event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    return new RedirectResponse(Url::fromRoute('myeventlane_event_studio.workspace_tickets', ['node' => $event->id()])->toString());
  }

  /**
   * Preserves bookmarked merchandise / add-ons URLs while routing to unified Extras.
   */
  public function redirectToExtrasWorkspace(NodeInterface $node): RedirectResponse {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    $account = $this->currentUser();
    if (!$account->hasPermission('administer nodes')
      && !$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($node, $account)) {
      throw new AccessDeniedHttpException();
    }
    $request = $this->requestStack->getCurrentRequest();
    $options = [];
    if ($request !== NULL && $request->query->count() > 0) {
      $options['query'] = $request->query->all();
    }
    return new RedirectResponse(Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $node->id()], $options)->toString());
  }

  /**
   * Preserves bookmarked promotions URLs while routing to the renamed Messaging section.
   */
  public function redirectToMessagingWorkspace(NodeInterface $node): RedirectResponse {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    $account = $this->currentUser();
    if (!$account->hasPermission('administer nodes')
      && !$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($node, $account)) {
      throw new AccessDeniedHttpException();
    }
    $request = $this->requestStack->getCurrentRequest();
    $options = [];
    if ($request !== NULL && $request->query->count() > 0) {
      $options['query'] = $request->query->all();
    }
    return new RedirectResponse(Url::fromRoute('myeventlane_event_studio.workspace_messaging', ['node' => $node->id()], $options)->toString());
  }

  /**
   * Preserves bookmarked legacy edit/basic and edit/datetime URLs.
   */
  public function redirectLegacyEditToInformation(NodeInterface $node): RedirectResponse {
    return $this->redirectLegacyEditStep($node, 'myeventlane_event_studio.workspace_information');
  }

  /**
   * Preserves bookmarked legacy edit/tickets URLs.
   */
  public function redirectLegacyEditToTickets(NodeInterface $node): RedirectResponse {
    return $this->redirectLegacyEditStep($node, 'myeventlane_event_studio.workspace_tickets');
  }

  /**
   * Preserves bookmarked legacy edit/description and edit/preview URLs.
   */
  public function redirectLegacyEditToContent(NodeInterface $node): RedirectResponse {
    return $this->redirectLegacyEditStep($node, 'myeventlane_event_studio.workspace_content');
  }

  /**
   * Preserves bookmarked legacy edit/publish URLs.
   */
  public function redirectLegacyEditToSettings(NodeInterface $node): RedirectResponse {
    return $this->redirectLegacyEditStep($node, 'myeventlane_event_studio.workspace_settings');
  }

  /**
   * @param non-empty-string $target_route
   */
  private function redirectLegacyEditStep(NodeInterface $node, string $target_route): RedirectResponse {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    $account = $this->currentUser();
    if (!$account->hasPermission('administer nodes')
      && !$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($node, $account)) {
      throw new AccessDeniedHttpException();
    }
    $request = $this->requestStack->getCurrentRequest();
    $options = [];
    if ($request !== NULL && $request->query->count() > 0) {
      $options['query'] = $request->query->all();
    }
    return new RedirectResponse(Url::fromRoute($target_route, ['node' => $node->id()], $options)->toString(), 302);
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

    $readiness_bundle = $this->readinessFacade->evaluate($node, $account);
    $readiness = $readiness_bundle['publish'];
    $sectionMetadata = $this->sectionManager->sectionMetadata($sectionPlugin);
    $sectionContent = $this->buildWorkspaceSectionContent($sectionPlugin, $node);
    $request = $this->requestStack->getCurrentRequest();
    $routeName = $request !== NULL ? (string) ($request->attributes->get('_route') ?? '') : '';

    $publish_handoff = NULL;
    if ($request !== NULL
      && (string) $request->query->get('mel_celebrate') === '1'
      && $node->isPublished()) {
      $publish_handoff = $this->eventStudioPreprocess->buildPublishSuccessHandoff($node);
    }

    $boost = $this->buildBoostWorkspacePayload($node, $account);
    $boost_extension_recommendation = $this->boostExtensionRecommendation?->getRecommendation($node);
    $attached_libraries = ['myeventlane_event_studio/mel_event_studio_shell_only'];
    if ($boost !== NULL || $boost_extension_recommendation !== NULL) {
      $attached_libraries[] = 'myeventlane_boost/boost_visibility';
    }

    $this->logger->debug('Event Studio workspace render: route=@route event_id=@eid section=@section plugin=@plugin render_target=@target section_content_keys=@keys', [
      '@route' => $routeName,
      '@eid' => (string) $node->id(),
      '@section' => $section,
      '@plugin' => (string) $sectionPlugin->getPluginId(),
      '@target' => $sectionPlugin->renderTarget(),
      '@keys' => is_array($sectionContent) ? implode(',', array_keys($sectionContent)) : gettype($sectionContent),
    ]);

    $readiness_summary = $this->workspacePresentation->buildReadinessSummary($readiness_bundle, $node);
    $event_health = $this->workspacePresentation->buildEventHealth($readiness_bundle, $node, $boost);

    return [
      '#theme' => 'mel_event_studio_workspace',
      '#node' => $node,
      '#sections' => $this->sectionManager->buildNavigation($node, $account, $section),
      '#sidebar_guidance' => $this->sectionRenderer->buildSidebarGuidance($node),
      '#current_section' => $section,
      '#current_section_label' => $this->sectionManager->sectionTitle($section),
      '#current_section_metadata' => $sectionMetadata,
      '#section_content' => $sectionContent,
      '#topbar' => $this->buildTopbar($node, $readiness, $section),
      '#event_health' => $event_health,
      '#readiness' => $readiness_summary,
      '#homepage_readiness' => $this->workspacePresentation->buildHomepageReadinessCard($node, $readiness_bundle, $section),
      '#boost' => $boost,
      '#boost_extension_recommendation' => $boost_extension_recommendation,
      '#publish_handoff' => $publish_handoff,
      '#attached' => [
        'library' => $attached_libraries,
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
            'publishHandoff' => $publish_handoff,
          ],
        ],
      ],
      '#cache' => [
        'tags' => $node->getCacheTags(),
        'contexts' => ['route', 'user', 'user.permissions', 'url.query_args:mel_celebrate'],
      ],
    ];
  }

  /**
   * Builds section body content, degrading to a governed empty state on failure.
   *
   * @return array<string, mixed>
   */
  private function buildWorkspaceSectionContent(
    EventStudioSectionInterface $sectionPlugin,
    NodeInterface $node,
  ): array {
    try {
      return $this->sectionRenderer->build($sectionPlugin, $node);
    }
    catch (FormAjaxException $exception) {
      throw $exception;
    }
    catch (EnforcedResponseException $exception) {
      throw $exception;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Event Studio section render failed for event @eid section @section target @target class @class file @file line @line: @message', [
        '@eid' => (string) $node->id(),
        '@section' => (string) $sectionPlugin->getPluginId(),
        '@target' => $sectionPlugin->renderTarget(),
        '@class' => $exception::class,
        '@file' => $exception->getFile(),
        '@line' => (string) $exception->getLine(),
        '@message' => $exception->getMessage(),
      ]);
      return $this->emptyStateBuilder->unavailableSection($sectionPlugin->title());
    }
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
    $operational_state = $this->workspacePresentation->operationalState($readiness);
    // Published events use the status badge only; readiness text is draft-oriented.
    $state = ($node->isPublished() && $readiness->ready) ? '' : $operational_state;
    $account = $this->currentUser();
    $isStaff = $account->hasPermission('administer nodes') || (int) $account->id() === 1;

    return [
      'title' => $node->label(),
      'location' => $this->workspacePresentation->buildTopbarLocation($node),
      'status' => $node->isPublished() ? $this->t('Published') : $this->t('Draft'),
      'state' => $state,
      'show_last_saved' => FALSE,
      'restore_draft' => $this->autosaveService->hasDraft($node, $section),
      'restore_url' => Url::fromRoute($this->sectionManager->sectionRouteName($section), [
        'node' => $node->id(),
      ], [
        'query' => ['restore_draft' => '1'],
      ])->toString(),
      'preview_url' => $this->domainDetector->publicUrl(Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString()),
      'publish_url' => Url::fromRoute('myeventlane_event_studio.publish', ['node' => $node->id()])->toString(),
      'published' => $node->isPublished(),
      'can_publish' => $readiness->ready,
      'current_section' => $section,
      'show_manage_event_link' => $isStaff,
      'manage_event_url' => $isStaff
        ? Url::fromRoute('myeventlane_vendor.console.event_workspace', ['event' => $node->id()])->toString()
        : '',
      'changed' => $node->getChangedTime(),
      'revision_id' => (int) $node->getRevisionId(),
    ];
  }

  /**
   * Builds boost visibility payload for the workspace shell when active.
   *
   * @return array<string, mixed>|null
   *   Boost card data, or NULL when boost is not active.
   */
  private function buildBoostWorkspacePayload(NodeInterface $node, AccountInterface $account): ?array {
    $visibility = $this->boostStatusService->getVisibilityPayload($node);
    if (empty($visibility['active'])) {
      return NULL;
    }

    $analyticsUrl = $this->safeRouteUrl('myeventlane_event_studio.workspace_analytics', ['node' => $node->id()], $account);
    if ($analyticsUrl === '') {
      $analyticsUrl = $this->safeRouteUrl('myeventlane_vendor.console.event_analytics', ['event' => $node->id()], $account);
    }

    return [
      'active' => TRUE,
      'days_remaining' => $visibility['days_remaining'],
      'expires' => $visibility['expires'],
      'analytics_url' => $analyticsUrl,
    ];
  }

  /**
   * @param array<string, mixed> $parameters
   */
  private function safeRouteUrl(string $routeName, array $parameters, AccountInterface $account): string {
    try {
      $url = Url::fromRoute($routeName, $parameters);
      if (!$url->access($account)) {
        return '';
      }
      return $url->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

}
