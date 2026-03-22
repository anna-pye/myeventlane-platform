<?php

declare(strict_types=1);

namespace Drupal\myeventlane_automation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin form for automation layer settings.
 *
 * Uses #config_target for Drupal 11 ConfigFormBase integration.
 */
final class AutomationSettingsForm extends ConfigFormBase {

  private const CONFIG = 'myeventlane_automation.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_automation_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG);

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General'),
      '#open' => TRUE,
    ];

    $form['general']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automation layer'),
      '#description' => $this->t('When disabled, no automations are evaluated or scheduled.'),
      '#config_target' => self::CONFIG . ':enabled',
      '#default_value' => $config->get('enabled') ?? TRUE,
    ];

    $form['general']['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry run mode'),
      '#description' => $this->t('When enabled, automation jobs are logged and queued but no emails are sent. Dashboard nudges still appear.'),
      '#config_target' => self::CONFIG . ':dry_run',
      '#default_value' => $config->get('dry_run') ?? TRUE,
    ];

    $form['rules'] = [
      '#type' => 'details',
      '#title' => $this->t('Rules'),
      '#open' => TRUE,
      '#description' => $this->t('Enable or disable individual automation rules.'),
    ];

    $rules = [
      'low_sales_boost' => $this->t('Low sales boost nudge'),
      'no_sales_yet' => $this->t('No tickets sold yet'),
      'nearly_sold_out' => $this->t('Nearly sold out'),
      'event_ended_rerun' => $this->t('Event ended – run again prompt'),
      'draft_reminder' => $this->t('Draft event reminder'),
      'attendee_reminder' => $this->t('Attendee reminder candidate (MEL Pro)'),
      'post_event_followup' => $this->t('Post-event follow-up candidate (MEL Pro)'),
      'organiser_reminder' => $this->t('Organiser reminder before event'),
      'auto_boost_candidate' => $this->t('Auto boost trigger candidate (MEL Pro)'),
    ];

    $rulesConfig = $config->get('rules') ?? [];
    foreach ($rules as $key => $label) {
      $form['rules']['rules_' . $key] = [
        '#type' => 'checkbox',
        '#title' => $label,
        '#config_target' => self::CONFIG . ':rules.' . $key,
        '#default_value' => $rulesConfig[$key] ?? in_array($key, ['low_sales_boost', 'no_sales_yet', 'nearly_sold_out', 'event_ended_rerun', 'draft_reminder', 'organiser_reminder'], TRUE),
      ];
    }

    $form['thresholds'] = [
      '#type' => 'details',
      '#title' => $this->t('Thresholds'),
      '#open' => FALSE,
    ];

    $thresholds = $config->get('thresholds') ?? [];
    $form['thresholds']['low_sales_percent'] = [
      '#type' => 'number',
      '#title' => $this->t('Low sales percentage'),
      '#config_target' => self::CONFIG . ':thresholds.low_sales_percent',
      '#default_value' => $thresholds['low_sales_percent'] ?? 15,
      '#min' => 1,
      '#max' => 100,
    ];

    $form['thresholds']['nearly_sold_out_percent'] = [
      '#type' => 'number',
      '#title' => $this->t('Nearly sold out percentage'),
      '#config_target' => self::CONFIG . ':thresholds.nearly_sold_out_percent',
      '#default_value' => $thresholds['nearly_sold_out_percent'] ?? 85,
      '#min' => 50,
      '#max' => 100,
    ];

    $form['thresholds']['reminder_hours_before_event'] = [
      '#type' => 'number',
      '#title' => $this->t('Reminder hours before event'),
      '#config_target' => self::CONFIG . ':thresholds.reminder_hours_before_event',
      '#default_value' => $thresholds['reminder_hours_before_event'] ?? 24,
      '#min' => 1,
      '#max' => 168,
    ];

    $form['thresholds']['post_event_followup_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Post-event follow-up window (hours)'),
      '#config_target' => self::CONFIG . ':thresholds.post_event_followup_hours',
      '#default_value' => $thresholds['post_event_followup_hours'] ?? 48,
      '#min' => 1,
      '#max' => 168,
    ];

    $form['thresholds']['draft_reminder_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Draft reminder after (days)'),
      '#config_target' => self::CONFIG . ':thresholds.draft_reminder_days',
      '#default_value' => $thresholds['draft_reminder_days'] ?? 3,
      '#min' => 1,
      '#max' => 30,
    ];

    $form['thresholds']['no_sales_min_hours_live'] = [
      '#type' => 'number',
      '#title' => $this->t('No sales nudge – min hours event live'),
      '#config_target' => self::CONFIG . ':thresholds.no_sales_min_hours_live',
      '#default_value' => $thresholds['no_sales_min_hours_live'] ?? 24,
      '#min' => 0,
      '#max' => 720,
    ];

    $form['thresholds']['low_sales_min_hours_live'] = [
      '#type' => 'number',
      '#title' => $this->t('Low sales nudge – min hours event live'),
      '#config_target' => self::CONFIG . ':thresholds.low_sales_min_hours_live',
      '#default_value' => $thresholds['low_sales_min_hours_live'] ?? 48,
      '#min' => 0,
      '#max' => 720,
    ];

    $form['delivery'] = [
      '#type' => 'details',
      '#title' => $this->t('Delivery'),
      '#open' => FALSE,
      '#description' => $this->t('Enable actual email delivery. Requires dry run to be disabled.'),
    ];

    $delivery = $config->get('delivery') ?? [];
    $form['delivery']['attendee_reminder'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send attendee reminders'),
      '#config_target' => self::CONFIG . ':delivery.attendee_reminder',
      '#default_value' => $delivery['attendee_reminder'] ?? FALSE,
    ];

    $form['delivery']['organiser_reminder'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send organiser reminders'),
      '#config_target' => self::CONFIG . ':delivery.organiser_reminder',
      '#default_value' => $delivery['organiser_reminder'] ?? FALSE,
    ];

    $form['delivery']['post_event_followup'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send post-event follow-up'),
      '#config_target' => self::CONFIG . ':delivery.post_event_followup',
      '#default_value' => $delivery['post_event_followup'] ?? FALSE,
    ];

    $form['delivery']['draft_reminder'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send draft reminders'),
      '#config_target' => self::CONFIG . ':delivery.draft_reminder',
      '#default_value' => $delivery['draft_reminder'] ?? FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

}
