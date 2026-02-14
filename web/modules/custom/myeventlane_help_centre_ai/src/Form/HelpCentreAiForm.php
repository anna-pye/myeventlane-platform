<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_ai\Service\AiJobEnqueueService;
use Drupal\myeventlane_help_centre_ai\Service\HelpCentreAiRetriever;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Form for Help Centre AI FAQ search.
 *
 * On submit: creates ai_job, enqueues; async worker executes.
 * Frontend polls for result.
 */
final class HelpCentreAiForm extends FormBase {

  public function __construct(
    private readonly HelpCentreAiRetriever $retriever,
    private readonly AiJobEnqueueService $jobEnqueue,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_help_centre_ai.retriever'),
      $container->get('myeventlane_ai.job_enqueue'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_help_centre_ai_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['question'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Your question'),
      '#required' => TRUE,
      '#rows' => 3,
      '#attributes' => [
        'placeholder' => $this->t('e.g. How do I get a refund for a cancelled event?'),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Ask'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $job_id = $form_state->get('ai_job_id');
    if ($job_id) {
      $form['result'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['help-centre-ai-result']],
        '#weight' => 100,
      ];
      $form['result']['disclosure'] = [
        '#type' => 'markup',
        '#markup' => '<p class="help-centre-ai-result__disclosure">' . htmlspecialchars($this->t('This is an AI-generated response based on available help articles. For formal support, contact our team.')) . '</p>',
        '#weight' => -10,
      ];
      $form['result']['generating'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['help-centre-ai-result__generating'],
          'data-job-id' => (string) $job_id,
          'data-poll-url' => Url::fromRoute('myeventlane_ai.job_poll', ['ai_job' => $job_id])->toString(),
        ],
        'text' => [
          '#markup' => '<p>' . $this->t('Generating…') . '</p>',
        ],
      ];
      $form['result']['answer'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['help-centre-ai-result__answer'], 'hidden' => 'true', 'aria-hidden' => 'true'],
      ];
      $articles = $form_state->get('ai_articles') ?? [];
      $items = [];
      foreach ($articles as $art) {
        $url = str_starts_with($art['url'] ?? '', '/')
          ? Url::fromUserInput($art['url'])
          : Url::fromUri($art['url']);
        $items[] = [
          '#type' => 'link',
          '#title' => $art['title'] ?? '',
          '#url' => $url,
        ];
      }
      $form['result']['articles'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Relevant articles'),
        '#items' => $items,
      ];
      $form['#attached']['library'][] = 'myeventlane_ai/ai_poll';
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $question = trim((string) $form_state->getValue('question'));
    if (mb_strlen($question) < 10) {
      $form_state->setErrorByName('question', $this->t('Please enter at least 10 characters.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $question = trim((string) $form_state->getValue('question'));
    $articles = $this->retriever->retrieve($question, 5);

    $supportUrl = '/my/support';
    try {
      $supportUrl = Url::fromRoute('myeventlane_escalations_portal.customer_add')->toString();
    } catch (\Exception $e) {
      // Ignore.
    }

    $fallback = "I can't confirm from our Help Centre yet. You can contact support here for personalised assistance.";
    if ($supportUrl !== '') {
      $fallback .= " " . $supportUrl;
    }

    $excerpts = '';
    foreach ($articles as $i => $art) {
      $excerpts .= "\n--- Article " . ($i + 1) . ": " . ($art['title'] ?? '') . " ---\n";
      $excerpts .= ($art['excerpt'] ?? '') . "\n";
    }

    $placeholders = [
      'fallback' => $fallback,
      'excerpts' => $excerpts ?: ' (none)',
      'question' => $question,
    ];

    $request = $this->requestStack->getCurrentRequest();
    $ip = $request ? $request->getClientIp() : '0.0.0.0';
    $scopeId = 'help_centre_ai:ip:' . $ip;

    $uid = (int) $this->currentUser()->id();
    if ($uid === 0) {
      $this->messenger()->addError($this->t('Please log in to use the AI assistant.'));
      $form_state->setRebuild();
      return;
    }

    $job = $this->jobEnqueue->enqueue(
      'help_centre.answer',
      'v1',
      $placeholders,
      [],
      $uid,
      $scopeId,
      NULL,
    );

    $form_state->set('ai_job_id', (int) $job->id());
    $form_state->set('ai_articles', $articles);
    $form_state->setRebuild();
  }

}
