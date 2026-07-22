<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for Access Code add/edit forms.
 */
final class AccessCodeForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $instance */
    $instance = parent::create($container);
    $instance->routeMatch = $container->get('current_route_match');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\myeventlane_tickets\Entity\AccessCode $entity */
    $entity = $this->entity;

    if ($entity->isNew()) {
      // Pre-populate event from route parameter.
      if ($entity->get('event')->isEmpty()) {
        $event = $this->routeMatch->getParameter('event');
        if ($event && $event->id()) {
          $entity->set('event', $event->id());
        }
      }

      if (isset($form['code'])) {
        $form['code']['widget'][0]['value']['#description'] = $this->t('The access code string. This will be shown only once after creation.');
      }
    }
    else {
      // Editing: show masked code, don't require re-entry.
      if (isset($form['code'])) {
        $current_code = $entity->getCode();
        $masked = strlen($current_code) > 4
          ? '****' . substr($current_code, -4)
          : '****';
        $form['code']['widget'][0]['value']['#default_value'] = '';
        $form['code']['widget'][0]['value']['#required'] = FALSE;
        $form['code']['widget'][0]['value']['#placeholder'] = $masked;
        $form['code']['widget'][0]['value']['#description'] = $this->t('Current code: %masked. Leave blank to keep unchanged, or enter a new code to replace it.', [
          '%masked' => $masked,
        ]);
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\myeventlane_tickets\Entity\AccessCode $entity */
    $entity = $this->entity;

    // On edit, if code field is left blank, restore the existing value.
    if (!$entity->isNew()) {
      $code_value = $form_state->getValue(['code', 0, 'value']);
      if ($code_value === '' || $code_value === NULL) {
        $entity->set('code', $entity->getCode());
        $form_state->setValue(['code', 0, 'value'], $entity->getCode());
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): void {
    /** @var \Drupal\myeventlane_tickets\Entity\AccessCode $entity */
    $entity = $this->entity;
    $is_new = $entity->isNew();
    $plaintext_code = $entity->getCode();
    $status = $entity->save();

    $event_id = $entity->getEventId();

    if ($status === SAVED_NEW) {
      $this->logger('myeventlane_tickets')->info('MEL ticket analytics hook access_code_created for event @nid.', [
        '@nid' => (string) $event_id,
        'mel_analytics_event' => 'access_code_created',
        'event_id' => (int) $event_id,
      ]);
      $this->messenger()->addStatus($this->t('Access code created. The code is: %code — copy it now, it will not be shown again in full.', [
        '%code' => $plaintext_code,
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Access code saved.'));
    }

    $form_state->setRedirect('myeventlane_tickets.event_tickets_access_codes', ['event' => $event_id]);
  }

}
