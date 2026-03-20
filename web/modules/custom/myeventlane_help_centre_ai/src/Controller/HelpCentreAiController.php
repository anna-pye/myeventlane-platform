<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
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
   * Redirects /help/ask to /help/assistant (single AI entry point).
   */
  public function ask(): RedirectResponse {
    $this->helpCentreAiLogger->warning('Deprecated AI endpoint used: /help/ask');

    if ($this->moduleHandler()->moduleExists('myeventlane_help_assistant')) {
      try {
        $target = Url::fromRoute('myeventlane_help_assistant.page')->toString();
      }
      catch (\Throwable) {
        $target = '/help';
      }
    }
    else {
      $target = '/help';
    }

    return new RedirectResponse($target, 302);
  }

}
