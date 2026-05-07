<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Template\Attribute;

/**
 * Central preprocess normalisation for MEL governed components.
 */
final class MelComponentPreprocess {

  public function __construct(
    private readonly SurfaceResolver $surfaceResolver,
    private readonly MelComponentManager $componentManager,
    private readonly MelComponentAccessibilityHelper $accessibilityHelper,
  ) {}

  /**
   * @param array<string, mixed> $variables
   */
  public function preprocessForHook(string $hook, array &$variables): void {
    match ($hook) {
      'mel_card' => $this->preprocessMelCard($variables),
      'mel_card_section',
      'mel_card_header',
      'mel_card_meta',
      'mel_card_actions',
      'mel_card_status',
      'mel_card_empty' => $this->preprocessMelCardFragment($hook, $variables),
      'mel_status_panel',
      'mel_success_panel',
      'mel_warning_panel',
      'mel_error_panel' => $this->preprocessMelStatusPanelFamily($hook, $variables),
      'mel_alert' => $this->preprocessMelAlert($variables),
      'mel_empty_state' => $this->preprocessMelEmptyState($variables),
      'mel_loading_state' => $this->preprocessMelLoadingState($variables),
      'mel_cta_band',
      'mel_inline_cta',
      'mel_sticky_cta',
      'mel_next_step_panel' => $this->preprocessMelCtaFamily($hook, $variables),
      'mel_shell_nav' => $this->preprocessMelShellNav($variables),
      'mel_shell_sidebar',
      'mel_shell_tabs',
      'mel_mobile_shell_nav' => $this->preprocessMelShellChrome($hook, $variables),
      'mel_onboarding_step',
      'mel_progress_panel',
      'mel_checklist_panel',
      'mel_guided_next_step' => $this->preprocessMelOnboardingFamily($hook, $variables),
      default => NULL,
    };
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelCard(array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables['mel_surface'] = $surface->value;
    $variables['mel_density'] = $this->componentManager->getDensity($surface);

    $variables += [
      'attributes' => new Attribute(),
      'title_element' => 'h2',
      'title' => NULL,
      'content' => '',
      'footer' => '',
      'status' => '',
      'meta' => '',
      'actions' => '',
      'empty' => '',
      'modifiers' => [],
    ];

    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }

    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-card');
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', 'mel_card');
    $attributes->setAttribute('data-mel-surface', $surface->value);
    $attributes->setAttribute('data-mel-density', $variables['mel_density']);

    foreach ($this->componentManager->getCardModifierClasses($surface) as $class) {
      $attributes->addClass($class);
    }
    foreach ((array) $variables['modifiers'] as $modifier) {
      if (is_string($modifier) && $modifier !== '') {
        $attributes->addClass($modifier);
      }
    }
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelCardFragment(string $hook, array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += ['attributes' => new Attribute()];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', $hook);
    $attributes->setAttribute('data-mel-surface', $surface->value);

    if ($hook === 'mel_card_section') {
      $variables += [
        'title' => NULL,
        'title_element' => 'h3',
      ];
    }

    match ($hook) {
      'mel_card_section' => $attributes->addClass('mel-card-section'),
      'mel_card_header' => $attributes->addClass(['mel-card-header', 'mel-card__header']),
      'mel_card_meta' => $attributes->addClass(['mel-card-meta', 'mel-card__meta']),
      'mel_card_actions' => $attributes->addClass(['mel-card-actions', 'mel-card__actions']),
      'mel_card_status' => $attributes->addClass(['mel-card-status', 'mel-card__status']),
      'mel_card_empty' => $attributes->addClass(['mel-card-empty', 'mel-card__empty']),
      default => NULL,
    };
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelStatusPanelFamily(string $hook, array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += [
      'attributes' => new Attribute(),
      'heading' => '',
      'heading_id' => '',
      'content' => '',
      'variant' => 'info',
    ];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-status-panel');
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', $hook);
    $attributes->setAttribute('data-mel-surface', $surface->value);

    $variant = match ($hook) {
      'mel_success_panel' => 'success',
      'mel_warning_panel' => 'warning',
      'mel_error_panel' => 'error',
      default => (string) $variables['variant'],
    };
    $variables['variant'] = $variant;
    $attributes->addClass('mel-status-panel--' . $variant);

    $heading_id = (string) $variables['heading_id'];
    if ($heading_id !== '') {
      $this->accessibilityHelper->relatePanelToHeading($variables, $heading_id);
    }
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelAlert(array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += [
      'attributes' => new Attribute(),
      'variant' => 'info',
      'content' => '',
    ];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $variant = (string) $variables['variant'];
    $attributes->addClass('mel-alert');
    $attributes->addClass('mel-alert--' . $variant);
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', 'mel_alert');
    $attributes->setAttribute('data-mel-surface', $surface->value);
    $this->accessibilityHelper->applyAlertSemantics($variables, $variant);
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelEmptyState(array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += [
      'attributes' => new Attribute(),
      'heading' => '',
      'heading_element' => 'h2',
      'what_happened' => '',
      'why_empty' => '',
      'next_action' => '',
      'cta' => NULL,
      'illustration' => NULL,
    ];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-empty-state');
    $attributes->addClass('mel-empty-state--governed');
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', 'mel_empty_state');
    $attributes->setAttribute('data-mel-surface', $surface->value);
    $this->accessibilityHelper->applyEmptyStateSemantics($variables);
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelLoadingState(array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += ['attributes' => new Attribute(), 'message' => ''];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-loading-state');
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', 'mel_loading_state');
    $attributes->setAttribute('data-mel-surface', $surface->value);
    $this->accessibilityHelper->applyLoadingSemantics($variables);
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelCtaFamily(string $hook, array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += ['attributes' => new Attribute(), 'content' => ''];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-cta');
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', $hook);
    $attributes->setAttribute('data-mel-surface', $surface->value);

    match ($hook) {
      'mel_cta_band' => $attributes->addClass('mel-cta-band'),
      'mel_inline_cta' => $attributes->addClass('mel-cta-inline'),
      'mel_sticky_cta' => $attributes->addClass('mel-cta-sticky'),
      'mel_next_step_panel' => $attributes->addClass('mel-cta-next-step'),
      default => NULL,
    };
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelShellNav(array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += [
      'attributes' => new Attribute(),
      'links' => [],
      'aria_label' => '',
      'contract' => NULL,
      'nav_inner_classes' => [],
      'list_classes' => [],
      'item_classes' => [],
      'link_classes' => [],
      'active_link_class' => 'mel-shell-nav__link--active',
    ];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-shell-nav');
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', 'mel_shell_nav');
    $attributes->setAttribute('data-mel-surface', $surface->value);

    $contract = is_array($variables['contract']) ? $variables['contract'] : [];
    foreach ($contract['nav_root_classes'] ?? [] as $class) {
      if (is_string($class) && $class !== '') {
        $attributes->addClass($class);
      }
    }

    $variables['nav_inner_classes'] = array_values(array_filter(
      $contract['nav_card_classes'] ?? ['mel-shell-nav__card'],
      static fn ($c): bool => is_string($c) && $c !== ''
    ));
    $variables['list_classes'] = array_values(array_filter(
      $contract['list_classes'] ?? ['mel-shell-nav__list'],
      static fn ($c): bool => is_string($c) && $c !== ''
    ));
    $variables['item_classes'] = array_values(array_filter(
      $contract['item_classes'] ?? ['mel-shell-nav__item'],
      static fn ($c): bool => is_string($c) && $c !== ''
    ));
    $variables['link_classes'] = array_values(array_filter(
      $contract['link_classes'] ?? ['mel-shell-nav__link'],
      static fn ($c): bool => is_string($c) && $c !== ''
    ));
    $variables['active_link_class'] = isset($contract['active_link_class']) && is_string($contract['active_link_class'])
      ? $contract['active_link_class']
      : 'mel-shell-nav__link--active';

    if (($variables['aria_label'] ?? '') === '' && array_key_exists('nav_landmark_label', $contract)) {
      $variables['aria_label'] = (string) $contract['nav_landmark_label'];
    }

    $this->accessibilityHelper->applyNavigationLandmark($variables);
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelShellChrome(string $hook, array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += ['attributes' => new Attribute(), 'content' => ''];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', $hook);
    $attributes->setAttribute('data-mel-surface', $surface->value);

    match ($hook) {
      'mel_shell_sidebar' => $attributes->addClass('mel-shell-sidebar'),
      'mel_shell_tabs' => $attributes->addClass('mel-shell-tabs'),
      'mel_mobile_shell_nav' => $attributes->addClass('mel-mobile-shell-nav'),
      default => NULL,
    };
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelOnboardingFamily(string $hook, array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += ['attributes' => new Attribute(), 'content' => ''];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-onboarding');
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', $hook);
    $attributes->setAttribute('data-mel-surface', $surface->value);

    match ($hook) {
      'mel_onboarding_step' => $attributes->addClass('mel-onboarding-step'),
      'mel_progress_panel' => $attributes->addClass('mel-onboarding-progress'),
      'mel_checklist_panel' => $attributes->addClass('mel-onboarding-checklist'),
      'mel_guided_next_step' => $attributes->addClass('mel-onboarding-guided-next'),
      default => NULL,
    };
  }

}
