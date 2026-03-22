<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

/**
 * MEL LANGUAGE STANDARD:
 * - Australian English
 * - "Attendee" not "Customer"
 * - "Organiser" not "Vendor"
 * - Friendly, non-corporate tone
 */

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to message attendees of an event (Pro feature).
 *
 * Safe v1: structure and UI only. Submit handler logs; no actual send.
 * Future: enqueue to mail queue, send only to attendees of this event.
 */
final class MessageAttendeesForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->setStringTranslation($container->get('string_translation'));
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_pro_message_attendees';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node) {
      return $form;
    }

    $form['#event'] = $node;

    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
      '#rows' => 6,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send message'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $form['#event'] ?? NULL;
    if (!$event instanceof NodeInterface) {
      return;
    }

    $subject = trim((string) $form_state->getValue('subject', ''));
    $message = trim((string) $form_state->getValue('message', ''));

    \Drupal::logger('myeventlane_pro')->info('Message attendees (stub): event=@nid, subject=@subject. Future: queue delivery to attendees only.', [
      '@nid' => $event->id(),
      '@subject' => $subject,
    ]);

    $this->messenger()->addStatus($this->t('Message form submitted. In a future release, this will send to attendees of this event only.'));
  }

}
