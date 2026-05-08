<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Entity\OnboardingStateInterface;
use Drupal\myeventlane_core\Service\OnboardingManager;

/**
 * Canonical primary CTA selection (“one action per moment”).
 */
final class MelWorkflowCTAHelper {

  use StringTranslationTrait;

  public function __construct(
    private readonly OnboardingManager $onboardingManager,
    private readonly LoggerChannelInterface $logger,
    TranslationInterface $string_translation,
    private readonly MelStateManager $stateManager,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds a single primary workflow region or returns an empty array.
   *
   * @param list<\Drupal\myeventlane_surface\WorkflowDefinition> $active
   * @return array<string, mixed>
   */
  public function buildPrimaryRegion(array $active, MelWorkflowContext $context): array {
    if ($active === []) {
      return [];
    }

    if ($context->checkoutSensitive && !empty($context->signals['route.commerce_checkout'])) {
      return [];
    }

    if ($this->stateManager->shouldSuppressPrimaryWorkflowCta($context)) {
      return [];
    }

    $delegate_vendor = FALSE;
    foreach ($active as $definition) {
      if ($definition->delegateVendorNextStepToOnboardingManager && $definition->domain === 'vendor') {
        $delegate_vendor = TRUE;
        break;
      }
    }

    if ($context->surface === MelSurfaceId::Vendor
      && !empty($context->signals['vendor.guidance.needs_resume'])
      && $delegate_vendor) {
      /** @var \Drupal\myeventlane_core\Entity\OnboardingStateInterface|null $vendor_state */
      $vendor_state = $context->vendorOnboardingState instanceof OnboardingStateInterface
        ? $context->vendorOnboardingState
        : NULL;
      $next = $this->onboardingManager->getNextActionForAuthenticatedVendor($vendor_state);
      $route_name = $next['route_name'] ?? NULL;
      $title = (string) ($next['title'] ?? '');
      if (is_string($route_name) && $route_name !== '') {
        return $this->buildNextStepPanelFromRoute($route_name, $title !== '' ? $title : $this->t('Continue setup'));
      }
    }

    $candidates = [];
    foreach ($active as $definition) {
      if ($definition->primaryCtaRouteName === NULL || $definition->primaryCtaRouteName === '') {
        continue;
      }
      $candidates[] = $definition;
    }

    if ($candidates === []) {
      return [];
    }

    usort($candidates, static function (WorkflowDefinition $a, WorkflowDefinition $b): int {
      return $b->ctaPriority <=> $a->ctaPriority;
    });

    $winner = $candidates[0];
    $title = $winner->primaryCtaTitle ?? '';
    return $this->buildNextStepPanelFromRoute(
      $winner->primaryCtaRouteName,
      $title !== '' ? $title : $this->t('Next step'),
    );
  }

  /**
   * @return array<string, mixed>
   */
  private function buildNextStepPanelFromRoute(string $route_name, string $title): array {
    try {
      $url = Url::fromRoute($route_name);
    }
    catch (\Throwable $e) {
      $this->logger->warning('MEL workflow CTA skipped — invalid route @route: @message', [
        '@route' => $route_name,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    return [
      '#theme' => 'mel_next_step_panel',
      '#content' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-workflow-next-step__inner']],
        'link' => [
          '#type' => 'link',
          '#title' => $title,
          '#url' => $url,
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
        ],
      ],
      '#attributes' => [],
    ];
  }

}
