<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_help_assistant\Service\EventSuggestionService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * JSON endpoint for live event wizard suggestions and quality score.
 */
final class EventSuggestionController extends ControllerBase {

  private const CSRF_ID = 'mel_event_suggestions';

  private const FLOOD_EVENT = 'mel_event_suggestions';

  public function __construct(
    private readonly EventSuggestionService $eventSuggestionService,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly FloodInterface $flood,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_help_assistant.event_suggestions'),
      $container->get('csrf_token'),
      $container->get('flood'),
    );
  }

  /**
   * POST JSON body: normalized wizard fields plus optional event_id.
   */
  public function suggest(Request $request): JsonResponse {
    $token = (string) ($request->headers->get('X-CSRF-Token') ?? '');
    if ($token === '' || !$this->csrfToken->validate($token, self::CSRF_ID)) {
      throw new AccessDeniedHttpException('Invalid CSRF token.');
    }

    $account = $this->currentUser();
    $floodId = 'mel_es.' . ($account->isAuthenticated() ? 'u.' . (string) $account->id() : 'anon');
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, 120, 3600, $floodId)) {
      return new JsonResponse([
        'error' => 'rate_limited',
        'suggestions' => [],
        'groups' => ['critical' => [], 'revenue' => [], 'tips' => []],
        'score' => 0,
        'score_label' => '',
        'score_summary' => '',
        'score_band' => '',
      ], 429);
    }
    $this->flood->register(self::FLOOD_EVENT, 3600, $floodId);

    $raw = $request->getContent();
    if ($raw === '') {
      return new JsonResponse([
        'suggestions' => [],
        'groups' => ['critical' => [], 'revenue' => [], 'tips' => []],
        'score' => 100,
        'score_label' => (string) $this->t('Ready to shine'),
        'score_summary' => (string) $this->t('Keep going — your event setup is looking good so far.'),
        'score_band' => 'ready_to_shine',
      ]);
    }

    $decoded = Json::decode($raw);
    if (!is_array($decoded)) {
      $this->getLogger('myeventlane_help_assistant')->warning('Event suggestions: invalid JSON payload.');
      return new JsonResponse([
        'error' => 'invalid_json',
        'suggestions' => [],
        'groups' => ['critical' => [], 'revenue' => [], 'tips' => []],
        'score' => 0,
        'score_label' => '',
        'score_summary' => '',
        'score_band' => '',
      ], 400);
    }

    $values = $this->normalizePayload($decoded);
    $event = $this->loadEventForSuggestions((int) $values['event_id'], $account);

    $insights = $this->eventSuggestionService->buildWizardInsights($values, $event);

    $suggestions = $insights['suggestions'];
    $ids = array_map(static fn (array $r): string => (string) ($r['id'] ?? ''), $suggestions);
    $topKeys = array_slice(array_values(array_filter($ids)), 0, 3);
    $wizardStep = (string) ($values['wizard_step'] ?? '');

    $this->getLogger('myeventlane_help_assistant')->info('Event wizard suggestions: event=@nid user=@uid score=@score band=@band top=@top step=@step rules=@ids', [
      '@nid' => $event instanceof NodeInterface ? (string) $event->id() : '0',
      '@uid' => (string) $account->id(),
      '@score' => (string) ($insights['score'] ?? 0),
      '@band' => (string) ($insights['score_band'] ?? ''),
      '@top' => implode(',', $topKeys),
      '@step' => $wizardStep,
      '@ids' => implode(',', array_filter($ids)),
    ]);

    $outSuggestions = [];
    foreach ($suggestions as $row) {
      $item = [
        'id' => (string) ($row['id'] ?? ''),
        'type' => (string) ($row['type'] ?? 'info'),
        'group' => (string) ($row['group'] ?? 'tips'),
        'title' => (string) ($row['title'] ?? ''),
        'message' => (string) ($row['message'] ?? ''),
      ];
      if (!empty($row['cta']) && is_array($row['cta'])) {
        $label = trim((string) ($row['cta']['label'] ?? ''));
        $url = trim((string) ($row['cta']['url'] ?? ''));
        if ($label !== '' && $url !== '') {
          $item['cta'] = ['label' => $label, 'url' => $url];
        }
      }
      if ($item['message'] !== '') {
        $outSuggestions[] = $item;
      }
    }

    $groupsOut = ['critical' => [], 'revenue' => [], 'tips' => []];
    if (!empty($insights['groups']) && is_array($insights['groups'])) {
      foreach ($groupsOut as $gkey => $_) {
        if (empty($insights['groups'][$gkey]) || !is_array($insights['groups'][$gkey])) {
          continue;
        }
        foreach ($insights['groups'][$gkey] as $row) {
          if (!is_array($row)) {
            continue;
          }
          $item = [
            'id' => (string) ($row['id'] ?? ''),
            'type' => (string) ($row['type'] ?? 'info'),
            'group' => (string) ($row['group'] ?? $gkey),
            'title' => (string) ($row['title'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
          ];
          if (!empty($row['cta']) && is_array($row['cta'])) {
            $label = trim((string) ($row['cta']['label'] ?? ''));
            $url = trim((string) ($row['cta']['url'] ?? ''));
            if ($label !== '' && $url !== '') {
              $item['cta'] = ['label' => $label, 'url' => $url];
            }
          }
          if ($item['message'] !== '') {
            $groupsOut[$gkey][] = $item;
          }
        }
      }
    }

    return new JsonResponse([
      'score' => (int) ($insights['score'] ?? 0),
      'score_label' => (string) ($insights['score_label'] ?? ''),
      'score_summary' => (string) ($insights['score_summary'] ?? ''),
      'score_band' => (string) ($insights['score_band'] ?? ''),
      'groups' => $groupsOut,
      'suggestions' => $outSuggestions,
    ]);
  }

  /**
   * @param array<string, mixed> $decoded
   *
   * @return array<string, mixed>
   */
  private function normalizePayload(array $decoded): array {
    $ticketPrices = $decoded['ticket_prices'] ?? [];
    if (!is_array($ticketPrices)) {
      $ticketPrices = [];
    }
    $prices = [];
    foreach ($ticketPrices as $p) {
      if (is_numeric($p)) {
        $prices[] = (float) $p;
      }
    }

    return [
      'event_id' => isset($decoded['event_id']) ? (int) $decoded['event_id'] : 0,
      'title' => (string) ($decoded['title'] ?? ''),
      'body' => (string) ($decoded['body'] ?? ''),
      'field_event_intro' => (string) ($decoded['field_event_intro'] ?? ''),
      'field_event_type' => (string) ($decoded['field_event_type'] ?? ''),
      'field_capacity' => $decoded['field_capacity'] ?? NULL,
      'ticket_prices' => $prices,
      'field_event_start' => (string) ($decoded['field_event_start'] ?? ''),
      'field_event_start_has_value' => !empty($decoded['field_event_start_has_value']),
      'field_location_has_value' => !empty($decoded['field_location_has_value']),
      'wizard_step' => (string) ($decoded['wizard_step'] ?? ''),
    ];
  }

  /**
   * Loads the event when an ID is provided; enforces the same edit rights as
   * the vendor event wizard (owner or privileged), not raw node update grants.
   */
  private function loadEventForSuggestions(int $nid, AccountInterface $account): ?NodeInterface {
    if ($nid < 1) {
      return NULL;
    }
    $node = $this->entityTypeManager()->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return NULL;
    }
    $this->assertUserMayEditEventInVendorWizard($node, $account);
    return $node;
  }

  /**
   * Mirrors \Drupal\myeventlane_event\Form\EventWizardBaseForm::assertEventOwnership.
   */
  private function assertUserMayEditEventInVendorWizard(NodeInterface $event, AccountInterface $account): void {
    if ($account->hasPermission('administer nodes') || $account->hasPermission('bypass node access')) {
      return;
    }
    if ((int) $event->getOwnerId() !== (int) $account->id()) {
      $this->getLogger('myeventlane_help_assistant')->warning('Event suggestions denied: user @uid cannot edit event @nid in vendor wizard context.', [
        '@uid' => (string) $account->id(),
        '@nid' => (string) $event->id(),
      ]);
      throw new AccessDeniedHttpException();
    }
  }

}
