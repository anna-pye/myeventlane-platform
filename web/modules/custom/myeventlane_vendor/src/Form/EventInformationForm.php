<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Simplified form for editing essential event information.
 *
 * This form shows only the most essential fields in a clean, simple layout.
 * For complex fields like images and dates, users are directed to use
 * the standard Drupal edit form.
 */
final class EventInformationForm extends FormBase {

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
    return 'myeventlane_vendor_event_information_form';
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
      '#value' => $this->t('Set up the basic information for your event. For advanced fields like images and detailed dates, use the standard edit form.'),
    ];

    // Event Basics section.
    $form['basics'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Event Basics'),
      '#attributes' => ['class' => ['mel-form-section']],
    ];

    // Title.
    $form['basics']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event Title'),
      '#required' => TRUE,
      '#default_value' => $event->label(),
      '#maxlength' => 255,
    ];

    // Event image - allow upload directly here (no redirect).
    if ($event->hasField('field_event_image')) {
      if (!$event->get('field_event_image')->isEmpty()) {
        $form['basics']['field_event_image_preview'] = $event->get('field_event_image')->view('default');
      }
      $existing_fid = $event->get('field_event_image')->isEmpty() ? [] : [$event->get('field_event_image')->target_id];
      $form['basics']['field_event_image_upload'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Event image'),
        '#upload_location' => 'public://event_images',
        '#default_value' => $existing_fid,
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png jpg jpeg gif'],
        ],
        '#description' => $this->t('Upload or change the event image directly.'),
      ];
    }

    // Date and Time section - simplified.
    $form['date_time'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Date & Time'),
      '#attributes' => ['class' => ['mel-form-section']],
    ];

    // Start date/time - use date and time inputs.
    if ($event->hasField('field_event_start')) {
      $start_date = NULL;
      $start_time = '';
      if (!$event->get('field_event_start')->isEmpty() && isset($event->get('field_event_start')[0])) {
        $start_date = $event->get('field_event_start')[0]->date;
        if ($start_date) {
          $start_time = $start_date->format('H:i');
        }
      }

      $form['date_time']['field_event_start_date'] = [
        '#type' => 'date',
        '#title' => $this->t('Start Date'),
        '#default_value' => $start_date ? $start_date->format('Y-m-d') : '',
      ];

      $form['date_time']['field_event_start_time'] = [
        '#type' => 'time',
        '#title' => $this->t('Start Time'),
        '#default_value' => $start_time,
      ];
    }

    // End date/time.
    if ($event->hasField('field_event_end')) {
      $end_date = NULL;
      $end_time = '';
      if (!$event->get('field_event_end')->isEmpty() && isset($event->get('field_event_end')[0])) {
        $end_date = $event->get('field_event_end')[0]->date;
        if ($end_date) {
          $end_time = $end_date->format('H:i');
        }
      }

      $form['date_time']['field_event_end_date'] = [
        '#type' => 'date',
        '#title' => $this->t('End Date'),
        '#default_value' => $end_date ? $end_date->format('Y-m-d') : '',
      ];

      $form['date_time']['field_event_end_time'] = [
        '#type' => 'time',
        '#title' => $this->t('End Time'),
        '#default_value' => $end_time,
      ];
    }

    // Location section.
    $form['location'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Location'),
      '#attributes' => ['class' => ['mel-form-section']],
    ];

    // Venue name.
    if ($event->hasField('field_event_venue') || $event->hasField('field_venue_name')) {
      $venue_field_name = $event->hasField('field_event_venue') ? 'field_event_venue' : 'field_venue_name';
      $venue_value = '';
      if (!$event->get($venue_field_name)->isEmpty() && isset($event->get($venue_field_name)[0])) {
        $venue_value = $event->get($venue_field_name)[0]->value ?? '';
      }
      $form['location'][$venue_field_name] = [
        '#type' => 'textfield',
        '#title' => $this->t('Venue Name'),
        '#default_value' => $venue_value,
      ];
    }

    // Location note removed to keep everything on this form.
    // Event Type section.
    $form['event_type'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Event Type'),
      '#attributes' => ['class' => ['mel-form-section']],
    ];

    // Event type (paid/RSVP/both).
    if ($event->hasField('field_event_type')) {
      $type_value = 'paid';
      if (!$event->get('field_event_type')->isEmpty() && isset($event->get('field_event_type')[0])) {
        $type_value = $event->get('field_event_type')[0]->value ?? 'paid';
      }
      $form['event_type']['field_event_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Event Type'),
        '#options' => [
          'paid' => $this->t('Paid Event'),
          'rsvp' => $this->t('RSVP Event'),
          'both' => $this->t('Both (Paid + RSVP)'),
        ],
        '#default_value' => $type_value,
        '#description' => $this->t('Choose how attendees can register for this event.'),
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save event information'),
        '#attributes' => ['class' => ['button', 'button--primary']],
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
    $values = $form_state->getValues();

    // Save title.
    if (isset($values['basics']['title'])) {
      $event->setTitle($values['basics']['title']);
    }

    // Save start date/time - combine date and time.
    if (isset($values['date_time']['field_event_start_date']) && isset($values['date_time']['field_event_start_time'])) {
      $start_date = $values['date_time']['field_event_start_date'];
      $start_time = $values['date_time']['field_event_start_time'] ?? '00:00';
      if ($start_date) {
        $datetime_string = $start_date . 'T' . $start_time . ':00';
        $event->set('field_event_start', $datetime_string);
      }
    }

    // Save end date/time - combine date and time.
    if (isset($values['date_time']['field_event_end_date']) && isset($values['date_time']['field_event_end_time'])) {
      $end_date = $values['date_time']['field_event_end_date'];
      $end_time = $values['date_time']['field_event_end_time'] ?? '00:00';
      if ($end_date) {
        $datetime_string = $end_date . 'T' . $end_time . ':00';
        $event->set('field_event_end', $datetime_string);
      }
    }

    // Save venue.
    if (isset($values['location']['field_event_venue'])) {
      $event->set('field_event_venue', $values['location']['field_event_venue']);
    }
    elseif (isset($values['location']['field_venue_name'])) {
      $event->set('field_venue_name', $values['location']['field_venue_name']);
    }

    // Save event type.
    if (isset($values['event_type']['field_event_type'])) {
      $event->set('field_event_type', $values['event_type']['field_event_type']);
    }

    // Save event image upload.
    if (isset($values['basics']['field_event_image_upload']) && is_array($values['basics']['field_event_image_upload'])) {
      $fids = array_filter($values['basics']['field_event_image_upload']);
      if (!empty($fids)) {
        $fid = reset($fids);
        $file = File::load($fid);
        if ($file) {
          $file->setPermanent();
          $file->save();
          $event->set('field_event_image', ['target_id' => $file->id()]);
        }
      }
    }

    $event->save();

    $this->messenger()->addStatus($this->t('Event information saved.'));
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
