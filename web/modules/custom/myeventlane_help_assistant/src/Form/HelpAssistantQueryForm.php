<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_help_assistant\Service\HelpAssistantService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * User-facing Help Assistant question form.
 */
final class HelpAssistantQueryForm extends FormBase {

  public function __construct(
    private readonly HelpAssistantService $assistantService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_help_assistant.assistant'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_help_assistant_query_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'mel-help-assistant-form';
    $form['question'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ask the Help Assistant'),
      '#required' => TRUE,
      '#rows' => 4,
      '#attributes' => [
        'placeholder' => $this->t('For example, how do I update my booking details?'),
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Ask'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['mel-help-assistant-submit'],
      ],
    ];

    $result = $form_state->get('assistant_result');
    if (is_array($result)) {
      $form['response'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-help-assistant-response']],
      ];
      $form['response']['answer'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['mel-help-assistant-answer']],
        '#value' => (string) ($result['answer'] ?? ''),
      ];
      $form['response']['confidence'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['mel-help-assistant-confidence']],
        '#value' => $this->t('Confidence: @confidence', [
          '@confidence' => (string) ($result['confidence'] ?? 'low'),
        ]),
      ];

      $items = [];
      foreach (($result['related_articles'] ?? []) as $article) {
        $link = $this->buildLink(
          (string) ($article['title'] ?? ''),
          (string) ($article['url'] ?? ''),
        );
        if ($link !== NULL) {
          $items[] = $link;
        }
      }

      $form['response']['sources'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Related Help Centre articles'),
        '#items' => $items,
        '#attributes' => ['class' => ['mel-help-assistant-sources']],
      ];

      if (!empty($result['escalation_recommended'])) {
        $form['response']['escalation'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-help-assistant-escalation']],
          'message' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => (string) ($result['suggested_follow_up'] ?? $this->t('Please contact support for personalised help.')),
          ],
        ];
      }
    }

    $form['#attached']['library'][] = 'myeventlane_help_assistant/assistant';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $question = trim((string) $form_state->getValue('question'));
    if (mb_strlen($question) < 8) {
      $form_state->setErrorByName('question', $this->t('Please enter at least 8 characters.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $question = trim((string) $form_state->getValue('question'));
    $result = $this->assistantService->answerQuestion($question);
    $form_state->set('assistant_result', $result);
    $form_state->setRebuild();
  }

  /**
   * Builds a renderable link safely from a URL string.
   */
  private function buildLink(string $title, string $url): ?array {
    if ($title === '' || $url === '') {
      return NULL;
    }

    try {
      $linkUrl = str_starts_with($url, '/')
        ? Url::fromUserInput($url)
        : Url::fromUri($url);
      return [
        '#type' => 'link',
        '#title' => $title,
        '#url' => $linkUrl,
      ];
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
