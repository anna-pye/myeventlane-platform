<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_domain_events\Service\ProjectionReplayService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms and executes projection replay from admin UI.
 */
final class ProjectionReplayConfirmForm extends ConfirmFormBase {

  public function __construct(
    private readonly ProjectionReplayService $projectionReplay,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_domain_events.projection_replay'),
      $container->get('logger.channel.myeventlane_domain_events'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_domain_events_projection_replay_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return (string) $this->t('Replay all domain events and rebuild projection tables?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->t('This will truncate all projection tables and replay the full event store.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('myeventlane_domain_events.admin.events');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return (string) $this->t('Replay projections');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $processed = $this->projectionReplay->replayAll();
      $this->messenger()->addStatus($this->t('Projection replay completed. Events processed: @count.', [
        '@count' => number_format($processed),
      ]));
    }
    catch (\Throwable $e) {
      $this->logger->error('Projection replay failed from admin UI: {message}', [
        'message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Projection replay failed: @message', [
        '@message' => $e->getMessage(),
      ]));
    }

    $form_state->setRedirect('myeventlane_domain_events.admin.events');
  }

}
