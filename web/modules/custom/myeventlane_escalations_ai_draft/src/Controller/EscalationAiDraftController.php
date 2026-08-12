<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai_draft\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_ai\Service\AiJobEnqueueService;
use Drupal\myeventlane_escalations\Entity\EscalationInterface;
use Drupal\myeventlane_escalations_ai_draft\Service\EscalationDraftContextBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for staff escalation AI draft generation (async via job).
 *
 * POST: creates ai_job, enqueues; returns job_id for polling.
 */
final class EscalationAiDraftController extends ControllerBase {

  public function __construct(
    private readonly AiJobEnqueueService $jobEnqueue,
    private readonly EscalationDraftContextBuilder $contextBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_ai.job_enqueue'),
      $container->get('myeventlane_escalations_ai_draft.context_builder'),
    );
  }

  /**
   * Starts draft generation. Returns job_id for polling.
   */
  public function generate(Request $request, EscalationInterface $escalation): JsonResponse {
    if (!$request->isMethod('POST')) {
      return new JsonResponse(['error' => 'Method not allowed'], 405);
    }

    $escalation_id = (int) $escalation->id();
    $context = $this->contextBuilder->build($escalation_id);
    if (isset($context['error'])) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => $this->t('Could not build context.'),
        'draft' => '',
        'job_id' => NULL,
      ]);
    }

    $thread = '';
    foreach ($context['thread'] ?? [] as $msg) {
      $thread .= "\n[" . ($msg['author'] ?? '') . "]: " . ($msg['body'] ?? '');
    }

    $playbooks = '';
    foreach ($context['playbooks'] ?? [] as $pb) {
      $playbooks .= "\n--- Playbook: " . ($pb['title'] ?? '') . " ---";
      if (!empty($pb['ai_summary'])) {
        $playbooks .= "\nAI summary: " . $pb['ai_summary'];
      }
      foreach ($pb['snippets'] ?? [] as $s) {
        $playbooks .= "\nSnippet: " . ($s['title'] ?? '') . "\n" . ($s['body'] ?? '');
      }
    }

    $meta = $context['meta'] ?? [];

    $placeholders = [
      'governance_standards' => $context['governance_standards'] ?? '',
      'subject' => $context['subject'] ?? '',
      'description' => $context['description'] ?? '',
      'status' => $meta['status'] ?? '',
      'priority' => $meta['priority'] ?? '',
      'waiting_on' => $meta['waiting_on'] ?? '',
      'sla_label' => $meta['sla_label'] ?? '',
      'thread' => $thread ?: ' (none yet)',
      'playbooks' => $playbooks ?: ' (none)',
    ];

    $job = $this->jobEnqueue->enqueueUnique(
      'escalation.draft',
      'v1',
      $placeholders,
      ['max_tokens' => 800],
      (int) $this->currentUser()->id(),
      'escalation_draft:' . $escalation_id,
      NULL,
    );

    return new JsonResponse([
      'ok' => TRUE,
      'job_id' => (int) $job->id(),
      'draft' => '',
      'error' => NULL,
    ]);
  }

}
