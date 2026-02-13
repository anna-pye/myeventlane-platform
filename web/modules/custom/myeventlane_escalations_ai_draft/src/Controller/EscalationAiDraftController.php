<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai_draft\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_ai\Service\AiManager;
use Drupal\myeventlane_escalations_ai_draft\Service\EscalationDraftContextBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for staff escalation AI draft generation (AJAX).
 */
final class EscalationAiDraftController extends ControllerBase {

  public function __construct(
    private readonly AiManager $aiManager,
    private readonly EscalationDraftContextBuilder $contextBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_ai.manager'),
      $container->get('myeventlane_escalations_ai_draft.context_builder'),
    );
  }

  /**
   * Generates a draft reply via AI. Returns JSON for AJAX.
   */
  public function generate(Request $request, int $escalation): JsonResponse {
    if (!$request->isMethod('POST')) {
      return new JsonResponse(['error' => 'Method not allowed'], 405);
    }

    $context = $this->contextBuilder->build($escalation);
    if (isset($context['error'])) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => $this->t('Could not build context.'),
        'draft' => '',
      ]);
    }

    $prompt = $this->buildPrompt($context);

    $aiResult = $this->aiManager->analyze(
      $prompt,
      ['max_tokens' => 800],
      (int) $this->currentUser()->id(),
      'escalation_draft:' . $escalation,
    );

    if (!$aiResult->ok) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => $aiResult->error ?? $this->t('AI service unavailable.'),
        'draft' => '',
      ]);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'draft' => trim($aiResult->raw),
      'error' => NULL,
    ]);
  }

  private function buildPrompt(array $context): string {
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

    $system = "You are a helpful staff assistant drafting reply messages for customer support escalations. "
      . "Your draft will be reviewed and edited by staff before sending. "
      . $context['governance_standards'] . " "
      . "Output ONLY the draft reply text, no preamble or meta-commentary.";

    $user = "Escalation:\nSubject: " . ($context['subject'] ?? '') . "\nDescription: " . ($context['description'] ?? '')
      . "\nStatus: " . ($context['meta']['status'] ?? '') . ", Priority: " . ($context['meta']['priority'] ?? '')
      . ", Waiting on: " . ($context['meta']['waiting_on'] ?? '') . ", SLA: " . ($context['meta']['sla_label'] ?? '')
      . "\n\nConversation thread:" . ($thread ?: ' (none yet)')
      . "\n\nRelevant playbooks and snippets:" . ($playbooks ?: ' (none)')
      . "\n\nDraft a reply the staff member can use or edit:";

    return $system . "\n\n" . $user;
  }

}
