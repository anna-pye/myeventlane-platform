<?php

declare(strict_types=1);

namespace Drupal\myeventlane_staff_playbooks_ai\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_ai\Service\AiManager;
use Drupal\myeventlane_ai\Service\PromptRegistry;
use Drupal\myeventlane_staff_playbooks_ai\Service\PlaybookAiContextBuilder;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes AI summary jobs for staff playbooks.
 *
 * @QueueWorker(
 *   id = "myeventlane_staff_playbooks_ai.summary",
 *   title = @Translation("MyEventLane Staff Playbooks AI summary"),
 *   cron = {"time" = 20}
 * )
 */
final class PlaybookAiSummaryQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PlaybookAiContextBuilder $contextBuilder,
    private readonly AiManager $aiManager,
    private readonly PromptRegistry $promptRegistry,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_staff_playbooks_ai.context_builder'),
      $container->get('myeventlane_ai.manager'),
      $container->get('myeventlane_ai.prompt_registry'),
      $container->get('logger.channel.myeventlane_staff_playbooks_ai'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    try {
      $settings = $this->configFactory->get('myeventlane_staff_playbooks_ai.settings');
      if (!$settings->get('enabled')) {
        return;
      }

      $node_id = (int) ($data['node_id'] ?? 0);
      $requested_by_uid = isset($data['requested_by_uid']) ? (int) $data['requested_by_uid'] : NULL;

      if ($node_id <= 0) {
        $this->logger->warning('Playbook AI job missing node_id.');
        return;
      }

      $storage = $this->entityTypeManager->getStorage('node');
      $node = $storage->load($node_id);

      if (!$node instanceof NodeInterface || $node->bundle() !== 'staff_playbook') {
        $this->logger->warning('Playbook AI job: node {id} is not a staff_playbook.', ['id' => $node_id]);
        return;
      }

      $context = $this->contextBuilder->build($node_id);
      if ($context === '') {
        $this->logger->warning('Playbook AI job: empty context for node {id}.', ['id' => $node_id]);
        return;
      }

      $model = trim((string) ($settings->get('model') ?? ''));
      $ai_opts = [
        'max_tokens' => (int) ($settings->get('max_tokens') ?? 600),
        'temperature' => (float) ($settings->get('temperature') ?? 0.2),
      ];
      if ($model !== '') {
        $ai_opts['model'] = $model;
      }

      $definition = $this->promptRegistry->render('playbook.summary', ['context' => $context], 'v1');
      if ($definition === NULL) {
        $this->logger->error('Playbook AI: prompt config missing for playbook.summary');
        return;
      }

      $result = $this->aiManager->analyze(
        $definition,
        $ai_opts,
        $requested_by_uid,
        'playbook:' . $node_id,
        NULL,
      );

      if (!$result->ok) {
        $this->logger->error('Playbook AI summary failed for node {id}: {error}', [
          'id' => $node_id,
          'error' => $result->error ?? 'Unknown',
        ]);
        return;
      }

      $output = trim((string) $result->raw);
      if ($output === '') {
        $this->logger->warning('Playbook AI summary returned empty for node {id}.', ['id' => $node_id]);
        return;
      }

      if ($node->hasField('field_ai_summary')) {
        $node->set('field_ai_summary', ['value' => $output, 'format' => 'basic_html']);
        $node->save();
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Playbook AI summary job error: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
