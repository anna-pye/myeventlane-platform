<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_vendor\Service\VendorMessagesHubBuilder;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event Workspace Messages — Communication Centre for one event.
 */
final class MessagingForm extends EventStudioBaseForm {

  /**
   * Optional Messages hub builder for the event panel.
   */
  private ?VendorMessagesHubBuilder $messagesHubBuilder = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $instance */
    $instance = parent::create($container);
    if ($container->has('myeventlane_vendor.messages_hub_builder')) {
      $instance->messagesHubBuilder = $container->get('myeventlane_vendor.messages_hub_builder');
    }
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_event_studio_messaging_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $form = parent::buildForm($form, $form_state, $node);

    // Messages is an operational workspace, not a data-entry wizard step.
    $form['#attributes']['class'][] = 'mel-event-studio-wizard-form--messages';
    unset($form['actions']);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function getNextRouteName(): string {
    return 'myeventlane_event_studio.workspace_messaging';
  }

  /**
   * {@inheritdoc}
   */
  protected function getPreviousRouteName(): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function getCurrentStepId(): string {
    return 'messaging';
  }

  /**
   * {@inheritdoc}
   */
  protected function getContinueButtonLabel() {
    return $this->t('Back to overview');
  }

  /**
   * {@inheritdoc}
   */
  protected function onWizardStepSaveSuccess(NodeInterface $saved, FormStateInterface $form_state): void {
    $form_state->setRedirect('myeventlane_event_studio.workspace', ['node' => $saved->id()]);
  }

  /**
   * {@inheritdoc}
   */
  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    if ($this->messagesHubBuilder instanceof VendorMessagesHubBuilder) {
      $panel = $this->messagesHubBuilder->buildForEvent($node);
      $form['messages_panel'] = [
        '#theme' => 'myeventlane_vendor_event_messages_panel',
        '#panel' => $panel,
        '#weight' => -20,
      ];
      return;
    }

    $form['notice'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-studio-section__placeholder']],
      'copy' => [
        '#markup' => '<p>' . $this->t('Use Messages to send essential updates—such as time changes or cancellations—to people who already booked or RSVPed.') . '</p>',
      ],
    ];
  }

}
