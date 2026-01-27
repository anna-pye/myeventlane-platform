<?php

declare(strict_types=1);

namespace Drupal\myeventlane_questions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the vendor question library page.
 */
class VendorQuestionLibraryController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * Displays the question library page.
   *
   * @return array
   *   A render array.
   */
  public function library(): array {
    $build = [];

    // Get current user's vendor and store.
    $vendor_storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $vendor_ids = $vendor_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $this->currentUser->id())
      ->range(0, 1)
      ->execute();

    $store = NULL;
    if (!empty($vendor_ids)) {
      $vendor = $vendor_storage->load(reset($vendor_ids));
      if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
        $store = $vendor->get('field_vendor_store')->entity;
      }
    }

    if (!$store) {
      $build['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('You must have a vendor account with a store to manage questions.') . '</p>',
      ];
      return $build;
    }

    // Build header with add button.
    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-question-library-header']],
    ];

    $build['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Question Library'),
    ];

    $build['header']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Manage reusable questions for your events. Questions saved here can be quickly added to any event.'),
      '#attributes' => ['class' => ['description']],
    ];

    if ($this->currentUser->hasPermission('manage vendor question library')) {
      $build['header']['add_link'] = Link::createFromRoute(
        $this->t('Add Question'),
        'myeventlane_questions.add',
        [],
        [
          'attributes' => [
            'class' => ['button', 'button--primary'],
          ],
        ]
      )->toRenderable();
    }

    // Load questions for this store.
    $question_storage = $this->entityTypeManager->getStorage('vendor_question');
    $question_ids = $question_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_store', $store->id())
      ->sort('created', 'DESC')
      ->execute();

    if (empty($question_ids)) {
      $build['empty'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No questions yet. @link to add your first question.', [
          '@link' => Link::createFromRoute($this->t('Click here'), 'myeventlane_questions.add')->toString(),
        ]) . '</p>',
      ];
      return $build;
    }

    // Build list of questions.
    $questions = $question_storage->loadMultiple($question_ids);
    $rows = [];

    foreach ($questions as $question) {
      /** @var \Drupal\myeventlane_questions\Entity\VendorQuestionInterface $question */
      $type_labels = [
        'textfield' => $this->t('Text field'),
        'textarea' => $this->t('Textarea'),
        'select' => $this->t('Select'),
        'checkbox' => $this->t('Checkbox'),
      ];

      $operations = [];
      if ($this->currentUser->hasPermission('manage vendor question library')) {
        $operations['edit'] = [
          'title' => $this->t('Edit'),
          'url' => Url::fromRoute('myeventlane_questions.edit', ['vendor_question' => $question->id()]),
        ];
        $operations['delete'] = [
          'title' => $this->t('Delete'),
          'url' => Url::fromRoute('myeventlane_questions.delete', ['vendor_question' => $question->id()]),
        ];
      }

      $rows[] = [
        'label' => Link::createFromRoute(
          $question->label(),
          'myeventlane_questions.edit',
          ['vendor_question' => $question->id()]
        ),
        'type' => $type_labels[$question->getQuestionType()] ?? $question->getQuestionType(),
        'required' => $question->isRequired() ? $this->t('Yes') : $this->t('No'),
        'status' => $question->isEnabled() ? $this->t('Enabled') : $this->t('Disabled'),
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => $operations,
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        'label' => $this->t('Question'),
        'type' => $this->t('Type'),
        'required' => $this->t('Required'),
        'status' => $this->t('Status'),
        'operations' => $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No questions found.'),
    ];

    return $build;
  }

}
