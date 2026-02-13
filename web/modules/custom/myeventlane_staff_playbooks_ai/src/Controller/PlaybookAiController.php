<?php

declare(strict_types=1);

namespace Drupal\myeventlane_staff_playbooks_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_staff_playbooks_ai\Service\PlaybookAiJobEnqueuer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Staff actions for AI playbook summarisation.
 */
final class PlaybookAiController extends ControllerBase {

  public function __construct(
    private readonly PlaybookAiJobEnqueuer $jobEnqueuer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_staff_playbooks_ai.job_enqueuer'),
    );
  }

  /**
   * Enqueues a summary job for this playbook.
   *
   * @param int $node
   *   The node ID from the route.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the playbook.
   */
  public function generateSummary(int $node): RedirectResponse {
    $storage = $this->entityTypeManager()->getStorage('node');
    $entity = $storage->load($node);

    if (!$entity || $entity->bundle() !== 'staff_playbook') {
      $this->messenger()->addError($this->t('Playbook not found.'));
      return new RedirectResponse('/admin/myeventlane/escalations');
    }

    $this->jobEnqueuer->enqueue((int) $node, 'summary', (int) $this->currentUser()->id());
    $this->messenger()->addStatus($this->t("Summary queued. It'll appear shortly."));

    return new RedirectResponse($entity->toUrl('canonical')->toString());
  }

}
