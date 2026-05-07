<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Template\Attribute;

/**
 * Central preprocess normalisation for MELInteractionSystem theme hooks.
 */
final class MelInteractionPreprocess {

  public function __construct(
    private readonly SurfaceResolver $surfaceResolver,
    private readonly MelInteractionManager $interactionManager,
    private readonly MelModalHelper $modalHelper,
    private readonly MelNotificationHelper $notificationHelper,
    private readonly MelAsyncStateHelper $asyncStateHelper,
    private readonly MelDisclosureHelper $disclosureHelper,
    private readonly MelInteractionAccessibilityHelper $interactionAccessibility,
  ) {}

  /**
   * @param array<string, mixed> $variables
   */
  public function preprocessForHook(string $hook, array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables['mel_surface'] = $surface->value;
    $variables['mel_interaction_profile'] = $this->interactionManager->getPageContext()['profile'] ?? '';

    match ($hook) {
      'mel_modal' => $this->preprocessMelModal($variables),
      'mel_modal_header',
      'mel_modal_body',
      'mel_modal_actions' => $this->preprocessMelModalFragment($hook, $variables),
      'mel_modal_confirmation' => $this->preprocessMelModalConfirmation($variables),
      'mel_drawer' => $this->preprocessMelDrawer($variables),
      'mel_toast' => $this->notificationHelper->applyToastChrome($variables),
      'mel_inline_notice' => $this->preprocessMelInlineNotice($variables),
      'mel_confirmation_banner',
      'mel_warning_banner',
      'mel_error_banner',
      'mel_processing_banner' => $this->preprocessMelBannerFamily($hook, $variables),
      'mel_saving_state',
      'mel_processing_state',
      'mel_retry_state',
      'mel_empty_loading' => $this->preprocessMelAsyncFamily($hook, $variables),
      'mel_disclosure',
      'mel_expandable_section',
      'mel_advanced_settings',
      'mel_collapsible_panel' => $this->preprocessMelDisclosureFamily($hook, $variables),
      default => NULL,
    };

    $variables += ['#cache' => ['contexts' => [], 'tags' => []]];
    $bubble = ['#cache' => ['contexts' => ['route', 'user.permissions']]];
    $meta = BubbleableMetadata::createFromRenderArray($variables);
    $meta->merge(BubbleableMetadata::createFromRenderArray($bubble));
    $meta->applyTo($variables);
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelModal(array &$variables): void {
    $variables += [
      'attributes' => new Attribute(),
      'title' => '',
      'header' => '',
      'body' => '',
      'actions' => '',
      'footer' => '',
      'dismiss_overlay' => TRUE,
      'fullscreen_mobile' => TRUE,
    ];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-modal');
    $attributes->setAttribute('data-mel-modal', 'true');
    $attributes->setAttribute('data-mel-surface', (string) $variables['mel_surface']);
    $attributes->setAttribute('data-mel-interaction-profile', (string) $variables['mel_interaction_profile']);
    $attributes->setAttribute(
      'data-mel-modal-dismiss-overlay',
      !empty($variables['dismiss_overlay']) ? 'true' : 'false'
    );
    if (!$attributes->offsetExists('id')) {
      $attributes->setAttribute('id', Html::getUniqueId('mel-modal'));
    }
    if (!empty($variables['fullscreen_mobile'])) {
      $attributes->addClass('mel-modal--fullscreen-mobile');
    }

    $this->modalHelper->applyModalShell($variables);
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelModalFragment(string $hook, array &$variables): void {
    $variables += ['attributes' => new Attribute(), 'content' => ''];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-modal__fragment');
    $attributes->setAttribute('data-mel-modal-part', str_replace('mel_', '', $hook));

    match ($hook) {
      'mel_modal_header' => $attributes->addClass('mel-modal__header'),
      'mel_modal_body' => $attributes->addClass('mel-modal__body'),
      'mel_modal_actions' => $attributes->addClass('mel-modal__actions'),
      default => NULL,
    };
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelModalConfirmation(array &$variables): void {
    $variables += [
      'attributes' => new Attribute(),
      'heading' => '',
      'body' => '',
      'confirm' => '',
      'cancel' => '',
    ];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-modal-confirmation');
    $attributes->setAttribute('data-mel-modal-confirmation', 'true');
    $attributes->setAttribute('data-mel-interaction-contract', 'modal_confirmation');
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelDrawer(array &$variables): void {
    $variables += [
      'attributes' => new Attribute(),
      'overlay_attributes' => new Attribute(),
      'panel_attributes' => new Attribute(),
      'contract_id' => '',
      'label_id' => '',
      'position' => 'end',
      'content' => '',
    ];
    foreach (['attributes', 'overlay_attributes', 'panel_attributes'] as $key) {
      if (!$variables[$key] instanceof Attribute) {
        $variables[$key] = new Attribute($variables[$key] ?? []);
      }
    }
    /** @var \Drupal\Core\Template\Attribute $root */
    $root = $variables['attributes'];
    $root->addClass('mel-drawer');
    $root->setAttribute('data-mel-drawer', 'true');
    $root->setAttribute('data-mel-drawer-position', (string) $variables['position']);
    if (!$root->offsetExists('id')) {
      $root->setAttribute('id', Html::getUniqueId('mel-drawer'));
    }
    $contract_id = (string) $variables['contract_id'];
    if ($contract_id !== '') {
      $root->setAttribute('data-mel-interaction-contract', $contract_id);
    }

    /** @var \Drupal\Core\Template\Attribute $overlay */
    $overlay = $variables['overlay_attributes'];
    $overlay->addClass('mel-drawer__overlay');
    $overlay->setAttribute('data-mel-drawer-overlay', 'true');

    /** @var \Drupal\Core\Template\Attribute $panel */
    $panel = $variables['panel_attributes'];
    $panel->addClass('mel-drawer__panel');

    $this->interactionAccessibility->applyDrawerSemantics($variables);
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelInlineNotice(array &$variables): void {
    $variables += ['attributes' => new Attribute(), 'variant' => 'info', 'content' => ''];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-inline-notice');
    $attributes->setAttribute('data-mel-notification', 'inline');
    $variant = (string) $variables['variant'];
    $attributes->addClass('mel-inline-notice--' . $variant);
    $this->interactionAccessibility->applyBannerSemantics($variables, $variant === 'error' ? 'error' : 'confirmation');
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelBannerFamily(string $hook, array &$variables): void {
    $variables += ['content' => ''];
    $kind = match ($hook) {
      'mel_confirmation_banner' => 'confirmation',
      'mel_warning_banner' => 'warning',
      'mel_error_banner' => 'error',
      'mel_processing_banner' => 'processing',
      default => 'confirmation',
    };
    $this->notificationHelper->applyBannerChrome($variables, $kind);
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelAsyncFamily(string $hook, array &$variables): void {
    $kind = match ($hook) {
      'mel_saving_state' => 'saving',
      'mel_processing_state' => 'processing',
      'mel_retry_state' => 'retry',
      'mel_empty_loading' => 'empty_loading',
      default => 'loading',
    };
    $this->asyncStateHelper->applyAsyncChrome($variables, $kind);
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMelDisclosureFamily(string $hook, array &$variables): void {
    $this->disclosureHelper->applyDisclosureChrome($variables, $hook);
  }

}
