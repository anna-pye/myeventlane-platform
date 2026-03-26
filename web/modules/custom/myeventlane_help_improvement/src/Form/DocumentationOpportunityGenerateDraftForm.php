<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_improvement\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\Core\Flood\FloodInterface;
use Drupal\myeventlane_help_improvement\Service\DocumentationOpportunityStorage;
use Drupal\myeventlane_help_improvement\Service\HelpArticleDraftGeneratorService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms AI-assisted draft generation (never auto-publishes).
 */
final class DocumentationOpportunityGenerateDraftForm extends ConfirmFormBase {

  private int $opportunityId = 0;

  public function __construct(
    private readonly DocumentationOpportunityStorage $storage,
    private readonly HelpArticleDraftGeneratorService $draftGenerator,
    private readonly FloodInterface $flood,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_help_improvement.storage'),
      $container->get('myeventlane_help_improvement.draft_generator'),
      $container->get('flood'),
      $container->get('datetime.time'),
      $container->get('logger.channel.myeventlane_help_improvement'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_doc_opportunity_generate_draft';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Generate an unpublished, AI-assisted help article draft from this opportunity? Content will not be published automatically.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('myeventlane_help_improvement.opportunity', ['opportunity' => $this->opportunityId]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Generate draft');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->opportunityId = (int) $this->getRouteMatch()->getParameter('opportunity');
    $row = $this->storage->load($this->opportunityId);
    if ($row === NULL) {
      $this->messenger()->addError($this->t('Opportunity not found.'));
      $form['#access'] = FALSE;
      return $form;
    }

    $form = parent::buildForm($form, $form_state);

    $form['mel_draft_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Drafts are created in an unpublished state with documentation status set to draft or review. You must review and publish through the normal editorial workflow.'),
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      '#weight' => -10,
    ];

    $audience = (string) ($row['audience'] ?? 'unknown');
    if ($audience === 'unknown') {
      $this->messenger()->addWarning($this->t('Set a concrete audience (public, vendor, or staff) on the opportunity before generating an AI draft. Staff audiences never receive public-facing defaults.'));
      $form['actions']['submit']['#access'] = FALSE;
    }

    if (!(bool) $this->config('myeventlane_ai.settings')->get('enabled')) {
      $this->messenger()->addWarning($this->t('Platform AI is disabled; enable it in MEL AI settings before generating drafts.'));
      $form['actions']['submit']['#access'] = FALSE;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uidKey = (string) $this->currentUser()->id();
    if (!$this->flood->isAllowed('mel_help_improvement.ai_draft', 8, 3600, $uidKey)) {
      $this->messenger()->addError($this->t('AI draft rate limit reached. Try again later.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $row = $this->storage->load($this->opportunityId);
    if ($row === NULL) {
      $this->messenger()->addError($this->t('Opportunity not found.'));
      $form_state->setRedirectUrl(Url::fromRoute('myeventlane_help_improvement.opportunities'));
      return;
    }
    if ((string) ($row['audience'] ?? 'unknown') === 'unknown') {
      $this->messenger()->addError($this->t('Cannot generate: audience is still unknown.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    try {
      $result = $this->draftGenerator->generateDraft($row, $this->currentUser());
      $node = $result['node'];
      $trace = $result['trace'];
    }
    catch (\Throwable $e) {
      $this->logger->error('Draft generation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Draft generation failed: @message', ['@message' => $e->getMessage()]));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $this->flood->register('mel_help_improvement.ai_draft', 3600, $uidKey);

    $prevMeta = $this->storage->decodeMetadata($row['metadata'] !== NULL ? (string) $row['metadata'] : NULL) ?? [];
    if (!isset($prevMeta['draft_generations']) || !is_array($prevMeta['draft_generations'])) {
      $prevMeta['draft_generations'] = [];
    }
    $prevMeta['draft_generations'][] = $trace;

    $notes = trim((string) ($row['notes'] ?? ''));
    $line = sprintf('AI-assisted draft node %d created %s (model %s).', (int) $node->id(), gmdate('c', (int) ($trace['generated_at'] ?? $this->time->getCurrentTime())), (string) ($trace['model'] ?? 'unknown'));
    $notes = $notes === '' ? $line : $notes . "\n" . $line;

    $this->storage->update($this->opportunityId, [
      'linked_help_article_nid' => (int) $node->id(),
      'status' => DocumentationOpportunityStorage::STATUS_DRAFTED,
      'metadata' => json_encode($prevMeta, JSON_THROW_ON_ERROR),
      'notes' => $notes,
      'last_seen' => $this->time->getRequestTime(),
    ]);

    $this->messenger()->addStatus($this->t('Draft created. Review the unpublished node before publishing.'));
    $form_state->setRedirect('entity.node.edit_form', ['node' => (int) $node->id()]);
  }

}
