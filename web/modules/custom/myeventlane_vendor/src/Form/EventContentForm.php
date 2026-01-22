<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for editing event page content.
 */
final class EventContentForm extends FormBase {

  public function __construct(
    private readonly AccountProxyInterface $currentUserProxy,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_vendor_event_content_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    if (!$event || $event->bundle() !== 'event') {
      return $form;
    }

    if (!$this->canManageEvent($event)) {
      $this->messenger()->addError($this->t('You do not have access to manage this event.'));
      return $form;
    }

    $form['#event'] = $event;
    $form['#tree'] = TRUE;

    $form['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['description']],
      '#value' => $this->t('Edit the main content of your event page.'),
    ];

    // Body/description field - render as editable form widget.
    if ($event->hasField('body')) {
      $body_field = $event->get('body');
      $body_value = '';
      $body_format = 'basic_html';

      // Access first item of FieldItemList.
      if (!$body_field->isEmpty() && isset($body_field[0])) {
        $body_value = $body_field[0]->value ?? '';
        $body_format = $body_field[0]->format ?? 'basic_html';
      }

      $form['body'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Event Description'),
      ];
      $form['body']['body'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Description'),
        '#default_value' => $body_value,
        '#format' => $body_format,
        '#required' => FALSE,
      ];
    }

    // Additional content fields can be added here.
    // For example, if there are custom fields for FAQ, additional sections, etc.
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save content'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\node\NodeInterface $event */
    $event = $form['#event'];

    if (!$this->canManageEvent($event)) {
      return;
    }

    // Save body field - handle text_format properly.
    if (isset($form['body']['body']) && $event->hasField('body')) {
      $body_value = $form_state->getValue(['body', 'body']);
      if (is_array($body_value) && isset($body_value['value'])) {
        $event->set('body', [
          'value' => $body_value['value'],
          'format' => $body_value['format'] ?? 'basic_html',
        ]);
      }
      elseif (is_string($body_value)) {
        $event->set('body', [
          'value' => $body_value,
          'format' => 'basic_html',
        ]);
      }
    }

    $event->save();

    $this->messenger()->addStatus($this->t('Content saved.'));
  }

  /**
   * Access helper: admin or owner.
   */
  private function canManageEvent(NodeInterface $event): bool {
    if ($this->currentUserProxy->hasPermission('administer nodes')) {
      return TRUE;
    }
    return (int) $event->getOwnerId() === (int) $this->currentUserProxy->id();
  }

}
