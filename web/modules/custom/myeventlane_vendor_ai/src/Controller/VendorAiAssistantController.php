<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\myeventlane_vendor_ai\Form\VendorAiAssistantForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the vendor MEL Assistant panel.
 */
final class VendorAiAssistantController extends ControllerBase {

  public function __construct(
    private readonly FormBuilderInterface $formBuilderService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('form_builder'),
    );
  }

  /**
   * Renders the MEL Assistant panel (GET + POST).
   */
  public function panel(int $escalation): array {
    $entity = $this->entityTypeManager()->getStorage('escalation')->load($escalation);
    if (!$entity) {
      return ['#markup' => '<p>' . $this->t('Escalation not found.') . '</p>'];
    }

    $form = $this->formBuilderService->getForm(VendorAiAssistantForm::class, $escalation);

    return [
      '#theme' => 'mel_support_layout',
      '#title' => $this->t('MEL Assistant'),
      '#intro' => $this->t('Ask about policies or next steps. This won\'t send a message.'),
      '#content' => [
        '#theme' => 'vendor_ai_assistant',
        '#form' => $form,
        '#attached' => [
          'library' => ['myeventlane_vendor_ai/assistant'],
        ],
      ],
      '#cache' => ['contexts' => ['user.permissions'], 'max-age' => 0],
    ];
  }

}
