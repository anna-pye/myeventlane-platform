<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Confirmation form for unpublishing an event.
 */
final class EventUnpublishForm extends ConfirmFormBase {

  /**
   * The event node.
   */
  protected ?NodeInterface $event = NULL;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_vendor_event_unpublish';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return $this->t('Unpublish this event?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->t('The event will be hidden from the public. You can publish it again later from the event edit page.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return $this->t('Unpublish');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('myeventlane_vendor.console.event_settings', [
      'event' => $this->event?->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->event = $this->getRouteMatch()->getParameter('event');
    if (!$this->event || $this->event->bundle() !== 'event' || !$this->event->isPublished()) {
      $form['error'] = [
        '#markup' => '<p>' . $this->t('This event cannot be unpublished.') . '</p>',
      ];
      return $form;
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->event) {
      return;
    }

    $this->event->setUnpublished();
    $this->event->save();

    $this->messenger()->addStatus($this->t('Event has been unpublished.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
