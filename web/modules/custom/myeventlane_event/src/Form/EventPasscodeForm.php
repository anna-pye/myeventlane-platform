<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event\Service\EventPasscodeAccess;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Passcode gate form for passcode-protected events.
 */
final class EventPasscodeForm extends FormBase {

  public function __construct(
    private readonly EventPasscodeAccess $passcodeAccess,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_event.passcode_access'),
    );
  }

  public function getFormId(): string {
    return 'myeventlane_event_passcode_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    if (!$this->passcodeAccess->requiresPasscode($node)) {
      throw new NotFoundHttpException();
    }

    $form_state->set('event_node', $node);

    $form['#cache'] = [
      'max-age' => 0,
    ];

    $form['event_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $node->label(),
      '#attributes' => [
        'class' => ['mel-passcode-gate__event-title'],
      ],
    ];

    $form['gate_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('This event is passcode protected'),
      '#attributes' => [
        'class' => ['mel-passcode-gate__heading'],
      ],
    ];

    $form['gate_description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Enter the passcode from the organiser to continue.'),
      '#attributes' => [
        'class' => ['mel-passcode-gate__description'],
      ],
    ];

    $form['passcode'] = [
      '#type' => 'password',
      '#title' => $this->t('Event passcode'),
      '#required' => TRUE,
      '#attributes' => [
        'autocomplete' => 'off',
        'placeholder' => $this->t('Enter passcode'),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Unlock event'),
      '#button_type' => 'primary',
    ];

    $form['back_url'] = [
      '#type' => 'value',
      '#value' => Url::fromRoute('<front>')->toString(),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $node = $form_state->get('event_node');
    if (!$node instanceof NodeInterface) {
      $form_state->setErrorByName('passcode', $this->t('An error occurred. Please try again.'));
      return;
    }

    $passcode = (string) $form_state->getValue('passcode');
    if ($passcode === '') {
      $form_state->setErrorByName('passcode', $this->t('Please enter the event passcode.'));
      return;
    }

    // @todo Add rate-limit check here if a rate-limiting service is available.

    if (!$this->passcodeAccess->verifyPasscode($node, $passcode)) {
      $form_state->setErrorByName('passcode', $this->t('The passcode is incorrect.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $form_state->get('event_node');
    if (!$node instanceof NodeInterface) {
      return;
    }

    $this->passcodeAccess->unlock($node);

    $form_state->setRedirectUrl(
      Url::fromRoute('entity.node.canonical', ['node' => $node->id()])
    );
  }

}
