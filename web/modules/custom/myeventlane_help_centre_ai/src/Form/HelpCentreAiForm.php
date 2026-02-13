<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_ai\Service\AiManager;
use Drupal\myeventlane_help_centre_ai\Service\HelpCentreAiRetriever;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Form for Help Centre AI FAQ search.
 *
 * Anonymous users allowed. Rate limited by IP via scope_id.
 */
final class HelpCentreAiForm extends FormBase {

  public function __construct(
    private readonly HelpCentreAiRetriever $retriever,
    private readonly AiManager $aiManager,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_help_centre_ai.retriever'),
      $container->get('myeventlane_ai.manager'),
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

    $result = $form_state->get('ai_result');
    if ($result) {
      $form['result'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['help-centre-ai-result']],
        '#weight' => 100,
      ];
      $form['result']['answer'] = [
        '#type' => 'markup',
        '#markup' => '<div class="help-centre-ai-result__answer">' . nl2br(htmlspecialchars($result['answer'])) . '</div>',
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
    $articles = $this->retriever->retrieve($question, 5);

    $supportUrl = '/my/support';
    try {
      $supportUrl = Url::fromRoute('myeventlane_escalations_portal.customer_add')->toString();
    }
    catch (\Exception $e) {
      // Ignore.
    }

    $request = $this->requestStack->getCurrentRequest();
    $ip = $request ? $request->getClientIp() : '0.0.0.0';
    $scopeId = 'help_centre_ai:ip:' . $ip;

    // For anonymous: no user rate limit. For authenticated: use uid.
    $uid = $this->currentUser()->isAuthenticated() ? (int) $this->currentUser()->id() : NULL;

    $prompt = $this->buildPrompt($question, $articles, $supportUrl);

    $aiResult = $this->aiManager->analyze($prompt, [], $uid, $scopeId);

    if ($aiResult->ok) {
      $form_state->set('ai_result', [
        'answer' => trim($aiResult->raw),
        'articles' => $articles,
      ]);
    }
    else {
      $form_state->set('ai_result', [
        'answer' => $this->t('I couldn\'t process your question right now. Please try again later or contact support here: @link', [
          '@link' => $supportUrl,
        ]),
        'articles' => [],
      ]);
    }

    $form_state->setRebuild();
  }

  private function buildPrompt(string $question, array $articles, string $supportUrl = ''): string {
    $excerpts = '';
    foreach ($articles as $i => $art) {
      $excerpts .= "\n--- Article " . ($i + 1) . ": " . ($art['title'] ?? '') . " ---\n";
      $excerpts .= ($art['excerpt'] ?? '') . "\n";
    }

    $fallback = "I can't confirm from our Help Centre yet. You can contact support here for personalised assistance.";
    if ($supportUrl !== '') {
      $fallback .= " " . $supportUrl;
    }

    $system = "You are the MyEventLane Help Centre assistant. Answer ONLY based on the provided Help Centre excerpts. "
      . "Use Australian English. Be calm, gender-neutral, and concise. "
      . "If the excerpts do not contain enough information to answer, respond with exactly: \""
      . $fallback
      . "\" Do not make up information. Keep answers to 2–4 short paragraphs.";

    return $system . "\n\nHelp Centre excerpts:" . ($excerpts ?: ' (none)') . "\n\nQuestion: " . $question . "\n\nAnswer:";
  }

}
