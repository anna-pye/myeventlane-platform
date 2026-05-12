<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_event_studio\EventStudioSectionManager;
use Drupal\myeventlane_event_studio\Service\EventReadinessService;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
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
    private readonly EventStudioAutosaveService $autosaveService,
    private readonly EventStudioSectionManager $sectionManager,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
    private readonly AccountProxyInterface $currentUser,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
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
      );
    }

    if (!$publishing) {
      return $this->setPublishedState($node, FALSE);
    }

    $readiness = $this->eventReadiness->evaluate($node, $account);
    if (!$readiness->ready) {
      return $this->readinessResponse(
        FALSE,
        422,
        $node,
        $readiness,
        (string) $this->t('Cannot publish yet'),
        'cannot_publish',
        $readiness->errors,
      );
    }

    return $this->setPublishedState($node, TRUE);
  }

  private function setPublishedState(NodeInterface $node, bool $published): JsonResponse {
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
  private function blockedResponse(int $status, string $code, array $messages, NodeInterface $node, ?string $restoreSection = NULL, ?string $message = NULL): JsonResponse {
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
  ): JsonResponse {
    $payload = [
      'ok' => $ok,
      'state' => $state,
      'message' => $message,
      'messages' => $messages,
      'published' => $node->isPublished(),
      'topbar' => [
        'status' => $node->isPublished() ? (string) $this->t('Published') : (string) $this->t('Draft'),
        'state' => $node->isPublished() ? (string) $this->t('Published') : $this->operationalState($readiness),
        'lastSaved' => $node->getChangedTime() > 0 ? (string) $this->t('Last saved @time', [
          '@time' => $this->dateFormatter->format($node->getChangedTime(), 'short'),
        ]) : (string) $this->t('Not saved yet'),
      ],
      'readiness' => [
        'ready' => $readiness->ready,
        'errors' => $readiness->errors,
        'warnings' => $readiness->warnings,
        'completed' => $readiness->completed,
        'recommendations' => $readiness->recommendations,
        'state' => $this->operationalState($readiness),
      ],
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

    return new JsonResponse($payload, $status);
  }

  private function operationalState(EventReadinessResult $result): string {
    if (!$result->ready) {
      return (string) $this->t('Needs Attention');
    }
    return (string) $this->t('Ready');
  }

  private function blockedHeading(bool $publishing): string {
    return $publishing ? (string) $this->t('Cannot publish yet') : (string) $this->t('Cannot unpublish yet');
  }

}
