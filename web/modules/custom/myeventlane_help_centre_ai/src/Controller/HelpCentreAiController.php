<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_help_centre_ai\Form\HelpCentreAiForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the public Help Centre AI FAQ assistant.
 */
final class HelpCentreAiController extends ControllerBase {

  /**
   * Renders the Help Centre AI ask form (GET + POST).
   */
  public function ask(): array {
    $form = $this->formBuilder()->getForm(HelpCentreAiForm::class);

    $supportUrl = '/my/support';
    try {
      $this->routeProvider()->getRouteByName('myeventlane_escalations_portal.customer_add');
      $supportUrl = Url::fromRoute('myeventlane_escalations_portal.customer_add')->toString();
    }
    catch (\Exception $e) {
      // Route may not exist if escalations portal disabled.
    }

    return [
      '#theme' => 'help_centre_ai',
      '#form' => $form,
      '#support_url' => $supportUrl,
      '#attached' => [
        'library' => ['myeventlane_help_centre_ai/ask'],
      ],
      '#cache' => ['contexts' => ['url.query_args'], 'max-age' => 0],
    ];
  }

}
