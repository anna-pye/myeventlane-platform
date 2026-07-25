<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_event_studio\EventStudioPreprocess;
use Drupal\myeventlane_event_studio\EventStudioSectionManager;
use Drupal\myeventlane_event_studio\Service\EventReadinessFacade;
use Drupal\myeventlane_event_studio\Service\EventReadinessService;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\myeventlane_event_studio\Service\EventStudioWorkspacePresentation;
use Drupal\myeventlane_event_studio\Service\EventWorkspaceOverviewBuilder;
use Drupal\myeventlane_vendor\Service\BoostStatusService;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shell-owned Event Studio publish operation.
 */
final class EventStudioPublishController {

  use StringTranslationTrait;

  public function __construct(
    private readonly EventStudioSaveService $saveService,
    private readonly EventReadinessService $eventReadiness,
    private readonly EventReadinessFacade $readinessFacade,
    private readonly EventStudioWorkspacePresentation $workspacePresentation,
    private readonly EventWorkspaceOverviewBuilder $overviewBuilder,
    private readonly BoostStatusService $boostStatusService,
    private readonly EventStudioAutosaveService $autosaveService,
    private readonly EventStudioSectionManager $sectionManager,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
    private readonly AccountProxyInterface $currentUser,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RendererInterface $renderer,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
    private readonly EventStudioPreprocess $eventStudioPreprocess,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  public function publish(NodeInterface $node, Request $request): JsonResponse {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    $account = $this->currentUser;
    if ($account->isAnonymous()) {
      $this->logger->warning('Event Studio publish denied: anonymous user for event @nid', [
        '@nid' => (string) $node->id(),
      ]);
      throw new AccessDeniedHttpException();
    }

    if (!$account->hasPermission('administer nodes')
      && !$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($node, $account)) {
      $this->logger->warning('Event Studio publish denied: no vendor parity for event @nid uid=@uid', [
        '@nid' => (string) $node->id(),
        '@uid' => (string) $account->id(),
      ]);
      throw new AccessDeniedHttpException();
    }

    $data = $this->requestPayload($request);
    $section = (string) ($data['section'] ?? '');
    $action = (string) ($data['action'] ?? 'publish');
    $publishing = $action !== 'unpublish';
    if (!empty($data['dirty'])) {
      return $this->blockedResponse(
        409,
        'unsaved_changes',
        [(string) $this->t('Save this section before changing publish state.')],
        $node,
        NULL,
        $this->blockedHeading($publishing),
        $section,
      );
    }

    $baseChanged = $this->parsePositiveInt($data['changed'] ?? $data['mel_studio_changed'] ?? NULL);
    $baseRevisionId = $this->parsePositiveInt($data['revision_id'] ?? $data['mel_studio_revision'] ?? NULL);
    if ($this->autosaveService->isStaleSubmission($node, $baseChanged, $baseRevisionId)) {
      return $this->blockedResponse(
        409,
        'stale_state',
        [(string) $this->t('This event changed after this section loaded. Refresh to continue safely.')],
        $node,
        NULL,
        $this->blockedHeading($publishing),
        $section,
      );
    }

    $draftSection = $this->firstAutosaveDraftSection($node);
    if ($draftSection !== NULL) {
      return $this->blockedResponse(
        409,
        'autosave_draft',
        [(string) $this->t('An autosaved draft is waiting in @section. Restore or save it before changing publish state.', [
          '@section' => $this->sectionManager->sectionTitle($draftSection),
        ])],
        $node,
        $draftSection,
        $this->blockedHeading($publishing),
        $section,
      );
    }

    if (!$publishing) {
      return $this->setPublishedState($node, FALSE, $section);
    }

    return $this->setPublishedState($node, TRUE, $section);
  }

  private function setPublishedState(NodeInterface $node, bool $published, string $section = ''): JsonResponse {
    $account = $this->currentUser;
    try {
      $this->saveService->setNodePublishedState(
        $node,
        $account,
        $published,
        $published ? 'Event Studio shell publish action.' : 'Event Studio shell unpublish action.',
      );
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->notice('Event Studio @action blocked for event @nid uid=@uid: @message', [
        '@action' => $published ? 'publish' : 'unpublish',
        '@nid' => (string) $node->id(),
        '@uid' => (string) $account->id(),
        '@message' => $e->getMessage(),
      ]);
      $afterBlockReadiness = $this->eventReadiness->evaluate($node, $account);
      return $this->readinessResponse(
        FALSE,
        422,
        $node,
        $afterBlockReadiness,
        $this->blockedHeading($published),
        $published ? 'cannot_publish' : 'cannot_unpublish',
        [$e->getMessage()],
        NULL,
        $section,
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio @action failed for event @nid uid=@uid: @message', [
        '@action' => $published ? 'publish' : 'unpublish',
        '@nid' => (string) $node->id(),
        '@uid' => (string) $account->id(),
        '@message' => $e->getMessage(),
      ]);
      return $this->blockedResponse(
        500,
        $published ? 'publish_failed' : 'unpublish_failed',
        [$published ? (string) $this->t('Publish failed. Try again shortly.') : (string) $this->t('Unpublish failed. Try again shortly.')],
        $node,
        NULL,
        $this->blockedHeading($published),
        $section,
      );
    }

    $readiness = $this->eventReadiness->evaluate($node, $account);
    return $this->readinessResponse(
      TRUE,
      200,
      $node,
      $readiness,
      $published ? (string) $this->t('Published successfully') : (string) $this->t('Unpublished successfully'),
      $published ? 'published' : 'draft',
      [],
      NULL,
      $section,
    );
  }

  /**
   * @return array<string, mixed>
   */
  private function requestPayload(Request $request): array {
    $decoded = json_decode($request->getContent(), TRUE);
    if (is_array($decoded)) {
      return $decoded;
    }
    return $request->request->all();
  }

  private function parsePositiveInt(mixed $value): int {
    if (!is_numeric($value)) {
      return 0;
    }
    $parsed = (int) $value;
    return $parsed > 0 ? $parsed : 0;
  }

  private function firstAutosaveDraftSection(NodeInterface $node): ?string {
    foreach ($this->sectionManager->activeSections($node, $this->currentUser) as $sectionId => $section) {
      if (!$section->isWritable()) {
        continue;
      }
      if ($this->autosaveService->hasDraft($node, (string) $sectionId)) {
        return (string) $sectionId;
      }
    }
    return NULL;
  }

  /**
   * @param list<string> $messages
   */
  private function blockedResponse(int $status, string $code, array $messages, NodeInterface $node, ?string $restoreSection = NULL, ?string $message = NULL, string $section = ''): JsonResponse {
    $readiness = $this->eventReadiness->evaluate($node, $this->currentUser);
    return $this->readinessResponse(
      FALSE,
      $status,
      $node,
      $readiness,
      $message ?? (string) $this->t('Cannot publish yet'),
      $code,
      $messages,
      $restoreSection,
      $section,
    );
  }

  /**
   * @param list<string> $messages
   */
  private function readinessResponse(
    bool $ok,
    int $status,
    NodeInterface $node,
    EventReadinessResult $readiness,
    string $message,
    string $state,
    array $messages,
    ?string $restoreSection = NULL,
    string $section = '',
  ): JsonResponse {
    $readiness_bundle = $this->readinessFacade->evaluate($node, $this->currentUser);
    $publish_result = $readiness_bundle['publish'];
    $boost = $this->buildBoostHealthPayload($node);
    $ajax_readiness = $this->workspacePresentation->buildAjaxReadinessPayloadFromBundle(
      $readiness_bundle,
      $node,
    );
    $ajax_section = $section !== '' ? $section : 'overview';
    // Always initialise so shell JS never keeps a stale Mission Control card
    // when the Home snapshot path fails after a successful publish.
    $ajax_readiness['mission_control'] = NULL;
    try {
      // Same Stripe + mission-control guide cards as full Home render.
      $home_snapshot = $this->overviewBuilder->buildHomeAjaxGuideSnapshot(
        $node,
        $this->currentUser,
        $ajax_section,
      );
      $ajax_readiness['home'] = $home_snapshot;
      $ajax_readiness['mission_control'] = $home_snapshot['mission_control'] ?? NULL;
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Workspace Home AJAX guide snapshot failed for event @nid: @message', [
        '@nid' => (string) $node->id(),
        '@message' => $e->getMessage(),
      ]);
      try {
        // Degraded path: reuse readiness bundle, skip Home VM so shell still
        // refreshes next-step / checklist after publish when snapshot fails.
        $ajax_readiness['mission_control'] = $this->overviewBuilder->buildMissionControl(
          $node,
          $this->currentUser,
          $ajax_section,
          $readiness_bundle,
          FALSE,
        );
      }
      catch (\Throwable $fallback) {
        $this->logger->error('Event Workspace Mission Control AJAX fallback failed for event @nid: @message', [
          '@nid' => (string) $node->id(),
          '@message' => $fallback->getMessage(),
        ]);
      }
    }
    // Homepage readiness card retired from chrome — Event Quality lives in Mission Control.
    $ajax_readiness['homepage_readiness_html'] = '';
    $ajax_readiness['show_homepage_readiness'] = FALSE;

    $topbar_status = $this->workspacePresentation->buildTopbarStatus($node);
    $nid = (int) $node->id();
    $share_url = Url::fromRoute('myeventlane_event_studio.workspace_marketing', ['node' => $nid])->toString();
    $primary_cta = $this->overviewBuilder->resolveAuthoritativePrimaryCta(
      $publish_result,
      $node->isPublished(),
      $nid,
      $share_url,
    );
    $payload = [
      'ok' => $ok,
      'state' => $state,
      'message' => $message,
      'messages' => $messages,
      'published' => $node->isPublished(),
      'topbar' => [
        // Same authoritative CTA contract as EventStudioController::buildTopbar.
        'status' => $topbar_status['label'],
        'status_key' => $topbar_status['key'],
        'state' => ($node->isPublished() && $publish_result->ready)
          ? ''
          : $this->workspacePresentation->operationalState($publish_result),
        'location' => $this->workspacePresentation->buildTopbarLocation($node),
        'date_label' => $this->workspacePresentation->buildTopbarDateLabel($node),
        'venue_label' => $this->workspacePresentation->buildTopbarVenueLabel($node),
        'primary_cta' => $primary_cta,
        'lastSaved' => $node->getChangedTime() > 0 ? (string) $this->t('Last saved @time', [
          '@time' => $this->dateFormatter->format($node->getChangedTime(), 'short'),
        ]) : (string) $this->t('Not saved yet'),
      ],
      'readiness' => $ajax_readiness,
      // Event Health chrome retired — Mission Control owns operational summary.
      'event_health' => NULL,
      'changed' => $node->getChangedTime(),
      'revisionId' => (int) $node->getRevisionId(),
    ];

    if ($restoreSection !== NULL) {
      $payload['restoreUrl'] = Url::fromRoute($this->sectionManager->sectionRouteName($restoreSection), [
        'node' => $node->id(),
      ], [
        'query' => ['restore_draft' => '1'],
      ])->toString();
      $payload['restoreSection'] = $restoreSection;
    }

    if ($ok && $state === 'published' && $node->isPublished()) {
      $handoff = $this->eventStudioPreprocess->buildPublishSuccessHandoff($node);
      if ($handoff !== NULL) {
        $payload['handoff'] = $handoff;
      }
    }

    return new JsonResponse($payload, $status);
  }

  /**
   * @return array<string, mixed>|null
   */
  private function buildBoostHealthPayload(NodeInterface $node): ?array {
    $visibility = $this->boostStatusService->getVisibilityPayload($node);
    if (empty($visibility['active'])) {
      return NULL;
    }
    return [
      'active' => TRUE,
      'days_remaining' => $visibility['days_remaining'],
      'expires' => $visibility['expires'],
    ];
  }

  private function blockedHeading(bool $publishing): string {
    return $publishing ? (string) $this->t('Cannot publish yet') : (string) $this->t('Cannot unpublish yet');
  }

}
