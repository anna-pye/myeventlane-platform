<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\MelStateEvaluation;
use Drupal\myeventlane_surface\MelStateRegistry;
use Drupal\myeventlane_surface\MelStateResolver;
use Drupal\myeventlane_surface\StateDefinition;

/**
 * Single dashboard hero copy + CTAs derived from participation + MEL state.
 *
 * Profile completeness reuses {@see MelStateResolver} / profile_completed only;
 * do not duplicate intelligence registry strings here.
 */
final class CustomerAccountHeroBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly MelStateResolver $stateResolver,
    private readonly MelStateRegistry $stateRegistry,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param int $upcoming_ticket_count
   *   Full list count (not the sliced preview count).
   * @param int $upcoming_rsvp_count
   *   Full list count.
   *
   * @return array<string, mixed>
   *   Theme variables for mel_account_hero (plain translated strings).
   */
  public function buildDashboardHero(int $user_id, int $upcoming_ticket_count, int $upcoming_rsvp_count): array {
    $upcoming_total = $upcoming_ticket_count + $upcoming_rsvp_count;
    $profile_complete = $this->isProfileComplete();

    $settings_url = Url::fromRoute('myeventlane_account.settings', ['user' => $user_id])->toString();
    $tickets_url = Url::fromRoute('myeventlane_checkout_flow.my_tickets')->toString();
    $events_url = Url::fromRoute('myeventlane_dashboard.customer')->toString();
    $discover_url = Url::fromRoute('<front>')->toString();

    $headline = '';
    $body = '';
    $primary_label = '';
    $primary_url = '';
    $secondary_label = '';
    $secondary_url = '';

    if ($upcoming_total > 0) {
      $headline = (string) $this->formatPlural(
        $upcoming_total,
        'You have one upcoming event.',
        'You have @count upcoming events.',
        ['@count' => (string) $upcoming_total],
      );
      if ($upcoming_ticket_count > 0) {
        $primary_label = (string) $this->t('View tickets');
        $primary_url = $tickets_url;
      }
      else {
        $primary_label = (string) $this->t('View your events');
        $primary_url = $events_url;
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
      $secondary_label = (string) $this->t('Browse events');
      $secondary_url = $discover_url;
    }
    else {
      $headline = (string) $this->t('Discover your next event');
      $body = (string) $this->t('Save events and manage bookings from your dashboard.');
      $primary_label = (string) $this->t('Explore events');
      $primary_url = $discover_url;
      $secondary_label = (string) $this->t('View your events');
      $secondary_url = $events_url;
    }

    return [
      'headline' => $headline,
      'body' => $body,
      'primary_label' => $primary_label,
      'primary_url' => $primary_url,
      'secondary_label' => $secondary_label,
      'secondary_url' => $secondary_url,
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
