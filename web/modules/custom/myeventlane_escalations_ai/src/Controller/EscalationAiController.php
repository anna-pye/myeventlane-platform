<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_escalations\Entity\EscalationInterface;
use Drupal\myeventlane_escalations_ai\Service\EscalationAiJobEnqueuer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Staff actions for AI generation.
 */
final class EscalationAiController extends ControllerBase {

  public function __construct(
    private readonly EscalationAiJobEnqueuer $jobEnqueuer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_escalations_ai.job_enqueuer'),
    );
  }

  /**
   * Enqueue a reply_suggestion job for this escalation.
   *
   * @param \Drupal\myeventlane_escalations\Entity\EscalationInterface $escalation
   *   The escalation entity from the route.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Queue result for the calling interface.
   */
  public function generateDraft(EscalationInterface $escalation): JsonResponse {
    $queued = $this->jobEnqueuer->enqueue(
      (int) $escalation->id(),
      'reply_suggestion',
      (int) $this->currentUser()->id(),
    );

    return new JsonResponse([
      'ok' => TRUE,
      'queued' => $queued,
      'message' => $queued
        ? $this->t('AI reply draft queued.')
        : $this->t('An AI reply draft is already queued.'),
    ], $queued ? 202 : 200);
  }

}
