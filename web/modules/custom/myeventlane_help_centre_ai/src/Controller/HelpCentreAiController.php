<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Legacy Help Centre AI route — redirects to the unified Help Assistant.
 */
final class HelpCentreAiController extends ControllerBase {

  public function __construct(
    private readonly LoggerInterface $helpCentreAiLogger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('logger.factory')->get('myeventlane_help_centre_ai'),
    );
  }

  /**
   * Redirects /help/ask to the canonical /help hub (Help Assistant block).
   */
  public function ask(): RedirectResponse {
    $this->helpCentreAiLogger->warning('Deprecated AI endpoint used: /help/ask');

    $options = [];
    if ($this->moduleHandler()->moduleExists('myeventlane_help_assistant')) {
      $settings = $this->config('myeventlane_help_assistant.settings');
      if ((bool) $settings->get('enabled')) {
        $options['fragment'] = 'mel-help-assistant';
      }
    }

    return $this->redirect('myeventlane_help_centre.home', [], $options, 302);
  }

}
