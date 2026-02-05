<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event\Service\EventRecurrenceGenerator;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form to generate recurring event instances from a series template.
 */
final class GenerateSeriesInstancesForm extends ConfirmFormBase {

  /**
   * Constructs GenerateSeriesInstancesForm.
   */
  public function __construct(
    private readonly EventRecurrenceGenerator $recurrenceGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_event.recurrence_generator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_event_generate_series_instances';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    $event = $this->getRequest()->attributes->get('event');
    return $this->t('Generate instances for "@title"?', [
      '@title' => $event instanceof NodeInterface ? $event->label() : '',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return (string) $this->t('Generate instances');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->t('This will create or update event instances based on the RRULE. Existing instances with matching dates will be updated.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    $event = $this->getRequest()->attributes->get('event');
    if ($event instanceof NodeInterface) {
      return Url::fromRoute('myeventlane_vendor.manage_event.series', ['event' => $event->id()]);
    }
    return Url::fromRoute('<front>');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->getRequest()->attributes->get('event');
    if (!$event instanceof NodeInterface) {
      $this->messenger()->addError($this->t('Invalid event.'));
      return;
    }

    $result = $this->recurrenceGenerator->generateInstances($event);

    if (!empty($result['errors'])) {
      foreach ($result['errors'] as $error) {
        $this->messenger()->addError($error);
      }
    }

    $created = $result['created'] ?? 0;
    $updated = $result['updated'] ?? 0;

    if ($created > 0 || $updated > 0) {
      $this->messenger()->addStatus($this->t(
        'Generated @created new instance(s) and updated @updated existing instance(s).',
        ['@created' => $created, '@updated' => $updated]
      ));
    }
    elseif (empty($result['errors'])) {
      $this->messenger()->addStatus($this->t('No instances to generate. Check RRULE and event start date.'));
    }

    $form_state->setRedirect('myeventlane_vendor.manage_event.series', ['event' => $event->id()]);
  }

}
