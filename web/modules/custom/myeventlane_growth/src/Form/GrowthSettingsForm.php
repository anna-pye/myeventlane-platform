<?php

declare(strict_types=1);

namespace Drupal\myeventlane_growth\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin settings for growth thresholds and cooldowns.
 */
final class GrowthSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_growth_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_growth.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_growth.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable growth insights'),
      '#default_value' => (bool) ($config->get('enabled') ?? TRUE),
    ];

    $form['limits'] = [
      '#type' => 'details',
      '#title' => $this->t('Limits'),
      '#open' => TRUE,
    ];
    $form['limits']['max_dashboard_cards'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum dashboard growth cards'),
      '#min' => 1,
      '#max' => 5,
      '#default_value' => (int) ($config->get('limits.max_dashboard_cards') ?? 3),
    ];

    $form['thresholds'] = [
      '#type' => 'details',
      '#title' => $this->t('Thresholds'),
      '#open' => TRUE,
    ];
    $t = $config->get('thresholds') ?? [];
    $form['thresholds']['min_activity_for_pro_nudge'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum tickets plus RSVPs (published events) for Pro upgrade nudge'),
      '#min' => 0,
      '#default_value' => (int) ($t['min_activity_for_pro_nudge'] ?? 1),
    ];
    $form['thresholds']['slow_sales_percent'] = [
      '#type' => 'number',
      '#title' => $this->t('Slow Boost nudge when percent sold is below'),
      '#min' => 0,
      '#max' => 100,
      '#default_value' => (int) ($t['slow_sales_percent'] ?? 20),
    ];
    $form['thresholds']['traction_percent'] = [
      '#type' => 'number',
      '#title' => $this->t('Traction Boost nudge when percent sold is at least'),
      '#min' => 0,
      '#max' => 100,
      '#default_value' => (int) ($t['traction_percent'] ?? 70),
    ];
    $form['thresholds']['repeat_events_for_pro_nudge'] = [
      '#type' => 'number',
      '#title' => $this->t('Published events count for repeat-organiser Pro nudge'),
      '#min' => 1,
      '#default_value' => (int) ($t['repeat_events_for_pro_nudge'] ?? 2),
    ];
    $form['thresholds']['strong_event_min_filled'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum tickets plus RSVPs for “strong event” rerun nudge'),
      '#min' => 1,
      '#default_value' => (int) ($t['strong_event_min_filled'] ?? 10),
    ];
    $form['thresholds']['draft_idle_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Draft idle days before publish reminder'),
      '#min' => 1,
      '#default_value' => (int) ($t['draft_idle_days'] ?? 21),
    ];
    $form['thresholds']['post_event_followup_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days after event end for follow-up nudge'),
      '#min' => 1,
      '#default_value' => (int) ($t['post_event_followup_days'] ?? 14),
    ];

    $form['cooldowns'] = [
      '#type' => 'details',
      '#title' => $this->t('Cooldowns (days)'),
      '#open' => TRUE,
    ];
    $c = $config->get('cooldowns') ?? [];
    $form['cooldowns']['default_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Default dismiss cooldown'),
      '#min' => 1,
      '#default_value' => (int) ($c['default_days'] ?? 14),
    ];
    $form['cooldowns']['boost_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Boost insight repeat cooldown'),
      '#min' => 1,
      '#default_value' => (int) ($c['boost_days'] ?? 7),
    ];
    $form['cooldowns']['pro_upgrade_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Pro upgrade insight repeat cooldown'),
      '#min' => 1,
      '#default_value' => (int) ($c['pro_upgrade_days'] ?? 21),
    ];
    $form['cooldowns']['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Retention insight repeat cooldown'),
      '#min' => 1,
      '#default_value' => (int) ($c['retention_days'] ?? 10),
    ];

    $form['tracking'] = [
      '#type' => 'details',
      '#title' => $this->t('Tracking'),
      '#open' => TRUE,
    ];
    $tr = $config->get('tracking') ?? [];
    $form['tracking']['tracking_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Record impressions, clicks, dismissals, and conversions'),
      '#default_value' => (bool) ($tr['enabled'] ?? TRUE),
    ];
    $form['tracking']['impression_dedupe_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Impression deduplication window (hours)'),
      '#min' => 0,
      '#default_value' => (int) ($tr['impression_dedupe_hours'] ?? 24),
    ];
    $form['tracking']['attribution_window_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Attribution window for conversions (days)'),
      '#min' => 1,
      '#default_value' => (int) ($tr['attribution_window_days'] ?? 14),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('myeventlane_growth.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('limits.max_dashboard_cards', (int) $form_state->getValue('max_dashboard_cards'))
      ->set('thresholds', [
        'min_activity_for_pro_nudge' => (int) $form_state->getValue('min_activity_for_pro_nudge'),
        'slow_sales_percent' => (int) $form_state->getValue('slow_sales_percent'),
        'traction_percent' => (int) $form_state->getValue('traction_percent'),
        'repeat_events_for_pro_nudge' => (int) $form_state->getValue('repeat_events_for_pro_nudge'),
        'strong_event_min_filled' => (int) $form_state->getValue('strong_event_min_filled'),
        'draft_idle_days' => (int) $form_state->getValue('draft_idle_days'),
        'post_event_followup_days' => (int) $form_state->getValue('post_event_followup_days'),
      ])
      ->set('cooldowns', [
        'default_days' => (int) $form_state->getValue('default_days'),
        'boost_days' => (int) $form_state->getValue('boost_days'),
        'pro_upgrade_days' => (int) $form_state->getValue('pro_upgrade_days'),
        'retention_days' => (int) $form_state->getValue('retention_days'),
      ])
      ->set('tracking', [
        'enabled' => (bool) $form_state->getValue('tracking_enabled'),
        'impression_dedupe_hours' => (int) $form_state->getValue('impression_dedupe_hours'),
        'attribution_window_days' => (int) $form_state->getValue('attribution_window_days'),
      ])
      ->save();

    parent::submitForm($form, $form_state);
  }

}
