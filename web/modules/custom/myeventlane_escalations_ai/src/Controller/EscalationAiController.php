<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_escalations_ai\Service\EscalationAiJobEnqueuer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
    $instance = new self(
      $container->get('myeventlane_escalations_ai.job_enqueuer'),
    );
    $instance->redirectDestination = $container->get('redirect.destination');
    return $instance;
  }

  /**
   * Enqueue a reply_suggestion job for this escalation.
   *
   * @param int $escalation
   *   The escalation entity ID from the route.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the escalation view.
   */
  public function generateDraft(int $escalation): RedirectResponse {
    $storage = $this->entityTypeManager()->getStorage('escalation');
    $entity = $storage->load($escalation);

    if (!$entity) {
      $this->messenger()->addError($this->t('Escalation not found.'));
      return new RedirectResponse('/admin/myeventlane/escalations');
    }

    $this->jobEnqueuer->enqueue((int) $escalation, 'reply_suggestion', (int) $this->currentUser()->id());
    $this->messenger()->addStatus($this->t('AI reply draft queued. It will appear in the AI Insights panel after cron/queue runs.'));

    $destination = $this->getRedirectDestination()->get();
    $target = '/admin/myeventlane/escalations/' . $escalation;
    if ($destination) {
      $target .= '?' . $destination;
    }
    return new RedirectResponse($target);
  }

}
