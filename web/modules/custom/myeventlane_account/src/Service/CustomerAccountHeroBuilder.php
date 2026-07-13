<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_core\MelStateEvaluation;
use Drupal\myeventlane_surface\MelStateRegistry;
use Drupal\myeventlane_surface\MelStateResolver;
use Drupal\myeventlane_surface\StateDefinition;

/**
 * Single dashboard hero copy + CTAs derived from participation + MEL state.
 *
 * Profile completeness reuses {@see MelStateResolver} / profile_completed only;
 * do not duplicate intelligence registry strings here.
 *
 * When a next booking exists, the hero surfaces that booking (image/title/meta
 * rendered via mel-account-event-card); otherwise falls back to discover /
 * profile CTAs.
 */
final class CustomerAccountHeroBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly MelStateResolver $stateResolver,
    private readonly MelStateRegistry $stateRegistry,
    private readonly MelReadinessHelper $readiness,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param int $upcoming_ticket_count
   *   Full list count (not the sliced preview count).
   * @param int $upcoming_rsvp_count
   *   Full list count.
   * @param string $display_name
   *   Customer display name for welcome copy.
   * @param array<string, mixed>|null $next_booking
   *   Earliest upcoming booking row from CustomerHubDataBuilder, if any.
   *
   * @return array<string, mixed>
   *   Theme variables for mel_account_hero (plain translated strings + optional booking).
   */
  public function buildDashboardHero(
    int $user_id,
    int $upcoming_ticket_count,
    int $upcoming_rsvp_count,
    string $display_name = '',
    ?array $next_booking = NULL,
  ): array {
    $upcoming_total = $upcoming_ticket_count + $upcoming_rsvp_count;
    $profile_complete = $this->isProfileComplete();

    $settings_url = Url::fromRoute('myeventlane_account.settings', ['user' => $user_id])->toString();
    $tickets_url = Url::fromRoute('myeventlane_checkout_flow.my_tickets')->toString();
    $discover_url = Url::fromRoute('<front>')->toString();

    $welcome = $display_name !== ''
      ? (string) $this->t('Welcome back, @name', ['@name' => $display_name])
      : (string) $this->t('Welcome back');

    if ($next_booking !== NULL && $upcoming_total > 0) {
      $primary = $next_booking['primary_cta'] ?? NULL;
      $secondary = $next_booking['secondary_cta'] ?? NULL;
      return [
        'welcome' => $welcome,
        'headline' => (string) $this->formatPlural(
          $upcoming_total,
          'You have one upcoming booking.',
          'You have @count upcoming bookings.',
          ['@count' => (string) $upcoming_total],
        ),
        'body' => (string) $this->t('Your next booking is highlighted below. Tickets stay in My bookings.'),
        'primary_label' => is_array($primary) ? (string) ($primary['label'] ?? '') : '',
        'primary_url' => is_array($primary) ? (string) ($primary['url'] ?? '') : '',
        'secondary_label' => is_array($secondary) ? (string) ($secondary['label'] ?? '') : '',
        'secondary_url' => is_array($secondary) ? (string) ($secondary['url'] ?? '') : '',
        'next_booking' => $next_booking,
        'mode' => 'next_booking',
      ];
    }

    $headline = '';
    $body = '';
    $primary_label = '';
    $primary_url = '';
    $secondary_label = '';
    $secondary_url = '';

    if ($upcoming_total > 0) {
      $headline = (string) $this->formatPlural(
        $upcoming_total,
        'You have one upcoming booking.',
        'You have @count upcoming bookings.',
        ['@count' => (string) $upcoming_total],
      );
      if ($upcoming_ticket_count > 0) {
        $primary_label = (string) $this->t('My bookings');
        $primary_url = $tickets_url;
      }
      else {
        $primary_label = $this->readiness->customerPrimaryBrowseEventsCta();
        $primary_url = $discover_url;
      }
      if (!$profile_complete) {
        $secondary_label = (string) $this->t('Complete profile');
        $secondary_url = $settings_url;
      }
    }
    elseif (!$profile_complete) {
      $headline = (string) $this->t('Finish your profile');
      $body = (string) $this->t('A few details help organisers recognise you and send the right updates.');
      $primary_label = (string) $this->t('Go to settings');
      $primary_url = $settings_url;
      $secondary_label = $this->readiness->customerPrimaryBrowseEventsCta();
      $secondary_url = $discover_url;
    }
    else {
      $headline = (string) $this->t('Discover your next event');
      $body = (string) $this->t('Save events and manage bookings from your dashboard.');
      $primary_label = $this->readiness->customerPrimaryBrowseEventsCta();
      $primary_url = $discover_url;
      $secondary_label = '';
      $secondary_url = '';
    }

    return [
      'welcome' => $welcome,
      'headline' => $headline,
      'body' => $body,
      'primary_label' => $primary_label,
      'primary_url' => $primary_url,
      'secondary_label' => $secondary_label,
      'secondary_url' => $secondary_url,
      'next_booking' => NULL,
      'mode' => $upcoming_total > 0 ? 'summary' : 'empty',
    ];
  }

  private function isProfileComplete(): bool {
    $definitions = $this->stateRegistry->all();
    $definition = $definitions['profile_completed'] ?? NULL;
    if (!$definition instanceof StateDefinition) {
      return TRUE;
    }
    $context = $this->stateResolver->buildWorkflowContext();
    $merged = $this->stateResolver->mergeDomainSignals($context);
    return $this->stateResolver->evaluate($definition, $context, $merged) === MelStateEvaluation::Satisfied;
  }

}
