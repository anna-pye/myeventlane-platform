<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_ai\Service\AiManager;
use Drupal\myeventlane_vendor_ai\Service\HelpArticleRetriever;
use Drupal\myeventlane_vendor_ai\Service\VendorAiContextBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for vendor AI assistant question.
 *
 * On submit: retrieves help articles, builds prompt, calls AI manager.
 */
final class VendorAiAssistantForm extends FormBase {

  public function __construct(
    private readonly AiManager $aiManager,
    private readonly VendorAiContextBuilder $contextBuilder,
    private readonly HelpArticleRetriever $helpRetriever,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_ai.manager'),
      $container->get('myeventlane_vendor_ai.context_builder'),
      $container->get('myeventlane_vendor_ai.help_retriever'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_vendor_ai_assistant_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $escalation_id = NULL): array {
    $escalation_id = $escalation_id ?? (int) $form_state->get('escalation_id');
    $form_state->set('escalation_id', $escalation_id);

    $form['escalation_id'] = [
      '#type' => 'value',
      '#value' => $escalation_id,
    ];

    $form['question'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Your question'),
      '#required' => TRUE,
      '#rows' => 3,
      '#attributes' => [
        'placeholder' => $this->t('e.g. What is the refund policy for cancelled events?'),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Ask MEL Assistant'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $result = $form_state->get('ai_result');
    if ($result) {
      $form['result'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ai-panel', 'mel-ai-panel__result']],
        '#weight' => 100,
      ];
      $form['result']['answer'] = [
        '#type' => 'markup',
        '#markup' => '<div class="mel-ai-panel__answer">' . nl2br(htmlspecialchars($result['answer'])) . '</div>',
      ];
      $items = [];
      foreach ($result['articles'] ?? [] as $art) {
        $url = str_starts_with($art['url'] ?? '', '/')
          ? Url::fromUserInput($art['url'])
          : Url::fromUri($art['url']);
        $items[] = [
          '#type' => 'link',
          '#title' => $art['title'],
          '#url' => $url,
        ];
      }
      $form['result']['articles'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Relevant articles'),
        '#items' => $items,
      ];
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
    $escalation_id = (int) $form_state->getValue('escalation_id');

    $articles = $this->helpRetriever->retrieve($question, 'vendor', 5);
    $context = $this->contextBuilder->build($escalation_id, $articles);

    if (isset($context['error'])) {
      $form_state->set('ai_result', [
        'answer' => $this->t('I could not load the escalation context. Please try again later.'),
        'articles' => [],
      ]);
      $form_state->setRebuild();
      return;
    }

    $prompt = $this->buildPrompt($question, $context);
    $aiResult = $this->aiManager->analyze(
      $prompt,
      [],
      (int) $this->currentUser()->id(),
      'vendor_ai:escalation:' . $escalation_id,
    );

    if ($aiResult->ok) {
      $form_state->set('ai_result', [
        'answer' => trim($aiResult->raw),
        'articles' => $articles,
      ]);
    }
    else {
      $form_state->set('ai_result', [
        'answer' => $this->t('I couldn\'t process your question right now. @reason Please try again later.', [
          '@reason' => $aiResult->error ?? $this->t('Service unavailable.'),
        ]),
        'articles' => [],
      ]);
    }

    $form_state->setRebuild();
  }

  private function buildPrompt(string $question, array $context): string {
    $excerpts = '';
    foreach ($context['help_excerpts'] ?? [] as $i => $art) {
      $excerpts .= "\n--- Article " . ($i + 1) . ": " . ($art['title'] ?? '') . " ---\n";
      $excerpts .= ($art['excerpt'] ?? '') . "\n";
    }

    $system = "You are the MyEventLane (MEL) Assistant. You help vendors understand policies and next steps. "
      . "Respond in Australian English. Be calm, gender-neutral, and politically correct. "
      . "Only answer based on the provided Help Centre excerpts. If the excerpts do not contain enough information, say: "
      . "\"I can't confirm from guidance. Please contact MyEventLane support for details.\" "
      . "Keep responses concise (2–4 short paragraphs). Do not make up policies.";

    $contextBlock = "Escalation context: Type={$context['type']}, Priority={$context['priority']}, "
      . "Status={$context['status']}, Waiting on={$context['waiting_on']}, SLA={$context['sla_label']}.";

    return $system . "\n\n" . $contextBlock . "\n\nHelp Centre excerpts:" . $excerpts . "\n\nVendor question: " . $question . "\n\nAnswer:";
  }

}
