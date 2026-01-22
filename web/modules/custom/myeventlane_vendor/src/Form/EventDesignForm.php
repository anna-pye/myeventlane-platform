<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Form for editing event page design settings.
 */
final class EventDesignForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_vendor_event_design_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    if (!$event) {
      return $form;
    }

    $form['#event'] = $event;
    $form['#tree'] = TRUE;

    $form['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['description']],
      '#value' => $this->t('Customize the visual appearance of your event page.'),
    ];

    // Hero/banner image - show preview and direct to main form for editing.
    if ($event->hasField('field_event_hero')) {
      $form['hero'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Hero/Banner Image'),
      ];

      // Show current image as read-only preview.
      if (!$event->get('field_event_hero')->isEmpty()) {
        $form['hero']['preview'] = $event->get('field_event_hero')->view('default');
      }
      else {
        $form['hero']['preview'] = [
          '#markup' => '<p>' . $this->t('No hero image set.') . '</p>',
        ];
      }

      // Direct users to Event information step for image editing.
      $edit_url = Url::fromRoute('myeventlane_vendor.manage_event.edit', ['event' => $event->id()]);
      $form['hero']['edit_link'] = [
        '#type' => 'link',
        '#title' => $this->t('Edit hero image in Event information'),
        '#url' => $edit_url,
        '#attributes' => ['class' => ['button', 'button--small']],
      ];
    }

    // Event logo - show preview and direct to main form for editing.
    if ($event->hasField('field_event_organizer_logo')) {
      $form['logo'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Event Logo'),
      ];

      // Show current image as read-only preview.
      if (!$event->get('field_event_organizer_logo')->isEmpty()) {
        $form['logo']['preview'] = $event->get('field_event_organizer_logo')->view('default');
      }
      else {
        $form['logo']['preview'] = [
          '#markup' => '<p>' . $this->t('No logo set.') . '</p>',
        ];
      }

      // Direct users to Event information step for image editing.
      $edit_url = Url::fromRoute('myeventlane_vendor.manage_event.edit', ['event' => $event->id()]);
      $form['logo']['edit_link'] = [
        '#type' => 'link',
        '#title' => $this->t('Edit logo in Event information'),
        '#url' => $edit_url,
        '#attributes' => ['class' => ['button', 'button--small']],
      ];
    }

    // Primary color (if field exists).
    if ($event->hasField('field_event_primary_color')) {
      $form['color'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Primary Color'),
        'field_event_primary_color' => [
          '#type' => 'color',
          '#title' => $this->t('Primary color'),
          '#default_value' => $event->get('field_event_primary_color')->value ?? '#0073e6',
        ],
      ];
    }

    // CTA button label (if field exists).
    if ($event->hasField('field_event_cta_label')) {
      $form['cta'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Call-to-Action Button'),
        'field_event_cta_label' => [
          '#type' => 'textfield',
          '#title' => $this->t('Button label'),
          '#default_value' => $event->get('field_event_cta_label')->value ?? 'Get Tickets',
          '#description' => $this->t('Label for the primary action button on the event page.'),
        ],
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save design'),
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

    // For image fields, we need to use the form display to properly handle uploads.
    // For now, we'll redirect to the standard edit form for image fields.
    // Save primary color.
    if (isset($form['color']['field_event_primary_color']) && $event->hasField('field_event_primary_color')) {
      $color_value = $form_state->getValue(['color', 'field_event_primary_color']);
      if ($color_value) {
        $event->set('field_event_primary_color', $color_value);
      }
    }

    // Save CTA label.
    if (isset($form['cta']['field_event_cta_label']) && $event->hasField('field_event_cta_label')) {
      $cta_value = $form_state->getValue(['cta', 'field_event_cta_label']);
      if ($cta_value) {
        $event->set('field_event_cta_label', $cta_value);
      }
    }

    $event->save();

    $this->messenger()->addStatus($this->t('Design settings saved. Note: Image fields should be edited in the Event information step.'));
  }

}
