<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Abstract base form for the Event Creation Wizard.
 *
 * Responsibilities:
 * - Load event entity from route
 * - Provide getEvent(), copyFormValuesToEvent(), buildStepper(),
 *   redirectToNextStep()
 * - NO field definitions here.
 */
abstract class EventWizardBaseForm extends FormBase {

  /**
   * Wizard step order.
   */
  protected const STEPS = [
    'basics' => ['label' => 'Basics', 'route' => 'myeventlane_event.wizard.basics'],
    'when_where' => ['label' => 'When & Where', 'route' => 'myeventlane_event.wizard.when_where'],
    'tickets' => ['label' => 'Tickets', 'route' => 'myeventlane_event.wizard.tickets'],
    'details' => ['label' => 'Details', 'route' => 'myeventlane_event.wizard.details'],
    'review' => ['label' => 'Review', 'route' => 'myeventlane_event.wizard.review'],
    'publish' => ['label' => 'Publish', 'route' => 'myeventlane_event.wizard.publish'],
  ];

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The domain detector.
   */
  protected DomainDetector $domainDetector;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The renderer.
   */
  protected RendererInterface $renderer;

  /**
   * Constructs the form.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    RendererInterface $renderer,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->domainDetector = $domain_detector;
    $this->currentUser = $current_user;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('renderer'),
    );
  }

  /**
   * Builds prefix markup for wizard step forms (stepper + card header).
   *
   * @param array $steps
   *   Stepper navigation items.
   * @param string $step_id
   *   Current step ID.
   * @param string $title
   *   Page title fallback.
   *
   * @return string
   *   Rendered HTML.
   */
  protected function buildWizardPrefix(array $steps, string $step_id, string $title): string {
    $build = [
      '#theme' => 'myeventlane_event_wizard_step_prefix',
      '#steps' => $steps,
      '#step' => [
        'id' => $step_id,
        'label' => self::STEPS[$step_id]['label'] ?? $step_id,
      ],
      '#title' => $title,
    ];
    return (string) $this->renderer->renderPlain($build);
  }

  /**
   * Builds suffix markup for wizard step forms (closing divs).
   *
   * @param string|null $step_id
   *   Current step id when section wrappers were opened in prefix (e.g. 'basics').
   *
   * @return string
   *   Rendered HTML.
   */
  protected function buildWizardSuffix(?string $step_id = NULL): string {
    $build = [
      '#theme' => 'myeventlane_event_wizard_step_suffix',
      '#step_id' => $step_id,
    ];
    return (string) $this->renderer->renderPlain($build);
  }

  /**
   * Gets the event entity from the route.
   *
   * Public so form alter hooks (e.g. myeventlane_location) can access the
   * event entity, matching Drupal's EntityForm::getEntity() pattern.
   */
  public function getEvent(): NodeInterface {
    $event = $this->getRouteMatch()->getParameter('event');
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      throw new \InvalidArgumentException('Event parameter is required and must be an event node.');
    }
    $this->assertEventOwnership($event);
    return $event;
  }

  /**
   * Asserts the current user can manage the event.
   */
  protected function assertEventOwnership(NodeInterface $event): void {
    if ($this->currentUser->hasPermission('administer nodes')) {
      return;
    }
    if ((int) $event->getOwnerId() !== (int) $this->currentUser->id()) {
      throw new AccessDeniedHttpException('You do not have permission to edit this event.');
    }
  }

  /**
   * Copies form values to the event entity using widget extraction.
   *
   * Uses EntityFormDisplay::extractFormValues() so that widget value
   * transformations (e.g. entity reference autocomplete "Label (id)" → ID)
   * are applied correctly. Avoids InvalidArgumentException when setting
   * entity reference fields with raw form state values.
   *
   * Loads the same form display used to build the form, so only fields
   * present on that display are extracted. No hardcoded field lists.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event entity.
   * @param array $form
   *   The form structure (as built by the form display).
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_mode
   *   The form mode used to build the form (e.g. wizard_step_1).
   */
  protected function copyFormValuesToEvent(
    NodeInterface $event,
    array $form,
    FormStateInterface $form_state,
    string $form_mode,
  ): void {
    $form_display = EntityFormDisplay::collectRenderDisplay($event, $form_mode);
    // Basics step: we use a custom image widget. Remove from display so
    // extractFormValues does not overwrite our manually saved image with
    // wrong/empty values from the widget's expected structure.
    if ($form_mode === 'wizard_step_1') {
      $form_display->removeComponent('field_event_image');
    }
    // Widgets build with #parents = [field_name_wrapper] but extract from
    // [field_name]. Copy wrapper values into the path widgets expect;
    // pass inner 'widget' so widgets get [0 => [...]].
    $this->normalizeFormStateForExtraction($form_display, $form_state);
    $extracted = $form_display->extractFormValues($event, $form, $form_state);

    // Fallback: copy fields from form_state that weren't extracted by widgets.
    // Matches ContentEntityForm::copyFormValuesToEntity for base fields and
    // widgets that fail to extract (e.g. title, link, address).
    // Skip field_event_image: it uses fids format; applyImageFromFormState handles it.
    $skip_fallback = ['field_event_image'];
    foreach ($form_state->getValues() as $name => $values) {
      if ($event->hasField($name) && !isset($extracted[$name]) && !in_array($name, $skip_fallback, TRUE)) {
        $event->set($name, $values);
      }
    }

    // When & Where step: ensure all step fields are on the entity from form
    // state when widget extraction did not persist them.
    if ($form_mode === 'wizard_step_2') {
      $this->applyWhenWhereFromFormState($event, $form_state);
    }

    // Basics step: ensure image field is saved when widget extraction fails.
    // The image widget submits fids; entity expects target_id. This fallback
    // converts and applies so uploads persist even when extraction is wrong.
    if ($form_mode === 'wizard_step_1') {
      $this->applyImageFromFormState($event, $form_state);
    }

    $event->save();
  }

  /**
   * Applies When & Where step values from form state to the event.
   *
   * Reads from normalized paths (field_*) and wrapper paths so values
   * persist even when widget extraction fails.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event entity.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function applyWhenWhereFromFormState(NodeInterface $event, FormStateInterface $form_state): void {
    $user_input = $form_state->getUserInput();
    $values = $form_state->getValues();

    // Try direct form_state paths first (after normalization), then wrapper paths.
    $paths_start = [
      ['field_event_start', 0, 'value'],
      ['field_event_start_wrapper', 'widget', 0, 'value'],
    ];
    $paths_end = [
      ['field_event_end', 0, 'value'],
      ['field_event_end_wrapper', 'widget', 0, 'value'],
    ];
    $paths_venue = [
      ['field_venue_name', 0, 'value'],
      ['field_venue_name_wrapper', 'widget', 0, 'value'],
    ];

    $v = NULL;
    $exists = FALSE;

    // field_event_start: prefer direct path, then user_input wrapper.
    if ($event->hasField('field_event_start')) {
      foreach ($paths_start as $path) {
        $v = NestedArray::getValue($values, $path, $exists);
        if ($exists && $v !== '' && $v !== NULL) {
          break;
        }
      }
      if ($v === NULL || $v === '' || !$exists) {
        $v = NestedArray::getValue($user_input, ['field_event_start_wrapper', 'widget', 0, 'value'], $exists);
      }
      $str = $this->normalizeDatetimeFormValue($v);
      if ($str !== NULL && $str !== '') {
        $event->set('field_event_start', [['value' => $str]]);
      }
    }

    // field_event_end.
    $v = NULL;
    $exists = FALSE;
    if ($event->hasField('field_event_end')) {
      foreach ($paths_end as $path) {
        $v = NestedArray::getValue($values, $path, $exists);
        if ($exists && $v !== '' && $v !== NULL) {
          break;
        }
      }
      if ($v === NULL || $v === '' || !$exists) {
        $v = NestedArray::getValue($user_input, ['field_event_end_wrapper', 'widget', 0, 'value'], $exists);
      }
      $str = $this->normalizeDatetimeFormValue($v);
      if ($str !== NULL && $str !== '') {
        $event->set('field_event_end', [['value' => $str]]);
      }
    }

    // field_venue_name.
    $v = NULL;
    $exists = FALSE;
    if ($event->hasField('field_venue_name')) {
      foreach ($paths_venue as $path) {
        $v = NestedArray::getValue($values, $path, $exists);
        if ($exists && $v !== '' && $v !== NULL) {
          break;
        }
      }
      if ($v === NULL || $v === '' || !$exists) {
        $v = NestedArray::getValue($user_input, ['field_venue_name_wrapper', 'widget', 0, 'value'], $exists);
      }
      if (is_scalar($v) && trim((string) $v) !== '') {
        $event->set('field_venue_name', trim((string) $v));
      }
    }

    // field_location (address).
    if ($event->hasField('field_location') && $event->get('field_location')->isEmpty()) {
      $this->applyLocationFromFormState($event, $form_state);
    }
  }

  /**
   * Applies image field value from form state when extraction failed.
   *
   * The image widget submits [fids => [fid], alt, title]. Entity expects
   * [target_id => fid, alt, title]. When widget extraction fails, this ensures
   * the upload is saved.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event entity.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function applyImageFromFormState(NodeInterface $event, FormStateInterface $form_state): void {
    if (!$event->hasField('field_event_image')) {
      return;
    }

    $values = $form_state->getValues();
    $user_input = $form_state->getUserInput();

    // Try direct path, wrapper path, and our custom structure (upload + alt).
    $raw = NestedArray::getValue($values, ['field_event_image'], $exists);
    if (!$exists || !is_array($raw)) {
      $raw = NestedArray::getValue($values, ['field_event_image_wrapper', 'widget'], $exists);
    }
    if (!$exists || !is_array($raw)) {
      $raw = NestedArray::getValue($user_input, ['field_event_image'], $exists);
      $raw = $exists && is_array($raw) ? $raw : NULL;
    }
    if (!$exists || !is_array($raw)) {
      $raw = NestedArray::getValue($user_input, ['field_event_image_wrapper', 'widget'], $exists);
      $raw = $exists && is_array($raw) ? $raw : NULL;
    }

    if (!is_array($raw)) {
      return;
    }

    $fid = NULL;
    $alt = '';

    // Custom Basics widget structure: ['upload' => ['fids' => [...]], 'alt' => '...'].
    $upload = $raw['upload'] ?? NULL;
    if (is_array($upload) && !empty($upload['fids'])) {
      $fids = is_array($upload['fids']) ? $upload['fids'] : (array) $upload['fids'];
      $fid = (int) reset($fids);
      $alt = trim((string) ($raw['alt'] ?? ''));
    }

    // Standard image widget structure: [0 => ['fids' => [...], 'alt' => '...']].
    if ($fid <= 0) {
      $item = $raw[0] ?? $raw;
      if (is_array($item)) {
        if (!empty($item['fids'])) {
          $fids = is_array($item['fids']) ? $item['fids'] : explode(' ', (string) $item['fids']);
          $fid = (int) reset($fids);
          $alt = trim((string) ($item['alt'] ?? ''));
        }
        elseif (!empty($item['target_id'])) {
          $fid = (int) $item['target_id'];
          $alt = trim((string) ($item['alt'] ?? ''));
        }
      }
    }

    if ($fid <= 0) {
      return;
    }

    $event->set('field_event_image', [
      [
        'target_id' => $fid,
        'alt' => $alt,
        'title' => '',
      ],
    ]);
  }

  /**
   * Normalizes a datetime form value to a string for entity set().
   *
   * Handles DrupalDateTime objects and string/array values from the widget.
   *
   * @param mixed $value
   *   Raw value from form state (DrupalDateTime, string, or array).
   *
   * @return string|null
   *   ISO-style datetime string or NULL if not usable.
   */
  protected function normalizeDatetimeFormValue(mixed $value): ?string {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (is_object($value) && method_exists($value, 'format')) {
      return $value->format('Y-m-d\TH:i:s');
    }
    if (is_scalar($value)) {
      $s = trim((string) $value);
      return $s === '' ? NULL : $s;
    }
    if (is_array($value) && isset($value['value'])) {
      $v = $value['value'];
      return $this->normalizeDatetimeFormValue($v);
    }
    return NULL;
  }

  /**
   * Normalizes form state so widget extraction finds values.
   *
   * Field widgets build with #parents = [field_name_wrapper] so submitted
   * values are at field_name_wrapper. extractFormValues() looks at
   * field_name. Copy wrapper value into field_name, using inner 'widget'
   * when present so widgets receive [0 => [...]].
   *
   * @param \Drupal\Core\Entity\Display\EntityFormDisplayInterface $form_display
   *   The form display used to build the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function normalizeFormStateForExtraction(EntityFormDisplayInterface $form_display, FormStateInterface $form_state): void {
    foreach (array_keys($form_display->getComponents()) as $name) {
      $wrapper_value = $form_state->getValue([$name . '_wrapper']);
      if (!is_array($wrapper_value)) {
        continue;
      }
      $value = $wrapper_value['widget'] ?? $wrapper_value;
      if ($value !== [] && $value !== NULL) {
        $form_state->setValue([$name], $value);
      }
    }

    // Image field: ensure value is in form_state for extraction.
    // The managed_file widget value may end up only in user_input in some flows;
    // copy to values so WidgetBase::extractFormValues finds it.
    if ($form_display->getComponent('field_event_image')) {
      $image_value = $form_state->getValue('field_event_image');
      if (empty($image_value) || !is_array($image_value)) {
        $image_value = NestedArray::getValue($form_state->getUserInput(), ['field_event_image'], $exists);
        if ($exists && is_array($image_value) && !empty($image_value)) {
          $form_state->setValue('field_event_image', $image_value);
        }
      }
    }
  }

  /**
   * Applies location value from form state when entity field is still empty.
   *
   * Handles both top-level (field_location[0][address]) and widget-wrapped
   * (field_location[widget][0][address]) form state structures.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event entity.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function applyLocationFromFormState(NodeInterface $event, FormStateInterface $form_state): void {
    $raw = $form_state->getValue('field_location');
    if (!is_array($raw)) {
      $raw = $form_state->getValue(['field_location_wrapper', 'widget']);
    }
    if (!is_array($raw)) {
      $raw = NestedArray::getValue($form_state->getUserInput(), ['field_location_wrapper', 'widget'], $exists);
      $raw = $exists && is_array($raw) ? $raw : NULL;
    }
    if (!is_array($raw)) {
      $raw = NestedArray::getValue($form_state->getUserInput(), ['field_location'], $exists);
      $raw = $exists && is_array($raw) ? $raw : NULL;
    }
    if (!is_array($raw)) {
      return;
    }

    if (isset($raw['widget']) && is_array($raw['widget'])) {
      $raw = $raw['widget'];
    }

    $address_values = [];
    foreach ($raw as $delta => $item) {
      if (is_numeric($delta) && isset($item['address']) && is_array($item['address'])) {
        $address_values[] = $item['address'];
      }
    }

    if ($address_values !== []) {
      $event->set('field_location', $address_values);
    }
  }

  /**
   * Builds the stepper navigation for the wizard.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event entity.
   * @param string $current_step_id
   *   The current step ID.
   *
   * @return array
   *   Stepper navigation items.
   */
  protected function buildStepper(NodeInterface $event, string $current_step_id): array {
    $step_ids = array_keys(self::STEPS);
    $navigation = [];
    foreach ($step_ids as $index => $step_id) {
      $step = self::STEPS[$step_id];
      $is_current = ($step_id === $current_step_id);
      $is_complete = $this->isStepComplete($event, $step_id);
      $is_accessible = $this->isStepAccessible($event, $step_id);

      $url = NULL;
      if ($is_accessible || $is_current) {
        $url = Url::fromRoute($step['route'], ['event' => $event->id()])->toString();
      }

      $navigation[] = [
        'id' => $step_id,
        'label' => $step['label'],
        'url' => $url,
        'is_current' => $is_current,
        'is_complete' => $is_complete,
        'is_accessible' => $is_accessible,
        'step_number' => $index + 1,
      ];
    }
    return $navigation;
  }

  /**
   * Checks if a step is complete (required fields filled).
   */
  protected function isStepComplete(NodeInterface $event, string $step_id): bool {
    $required = $this->getRequiredFieldsForStep($step_id);
    foreach ($required as $field_name) {
      if (!$event->hasField($field_name) || $event->get($field_name)->isEmpty()) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Checks if a step is accessible (previous steps complete).
   */
  protected function isStepAccessible(NodeInterface $event, string $step_id): bool {
    $step_ids = array_keys(self::STEPS);
    $current_index = array_search($step_id, $step_ids, TRUE);
    if ($current_index === FALSE || $current_index === 0) {
      return TRUE;
    }
    for ($i = 0; $i < $current_index; $i++) {
      if (!$this->isStepComplete($event, $step_ids[$i])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Gets required fields for a step.
   *
   * @return array<int, string>
   *   Required field names.
   */
  protected function getRequiredFieldsForStep(string $step_id): array {
    return match ($step_id) {
      'basics' => ['title', 'field_category'],
      'when_where' => ['field_event_start', 'field_location'],
      'tickets' => ['field_event_type'],
      'details', 'review', 'publish' => [],
      default => [],
    };
  }

  /**
   * Redirects to the next step after the given step.
   */
  protected function redirectToNextStep(FormStateInterface $form_state, string $current_step_id): void {
    $step_ids = array_keys(self::STEPS);
    $current_index = array_search($current_step_id, $step_ids, TRUE);
    if ($current_index !== FALSE && $current_index < count($step_ids) - 1) {
      $next_step_id = $step_ids[$current_index + 1];
      $next_step = self::STEPS[$next_step_id];
      $url = Url::fromRoute($next_step['route'], ['event' => $this->getEvent()->id()]);
      $form_state->setRedirectUrl($url);
    }
  }

  /**
   * After-build callback: ensures field_multiple_value_form has #attributes.
   *
   * Prevents FieldPreprocess warnings when EntityFormDisplay builds fields.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The element.
   */
  public static function ensureFieldAttributes(array $element, FormStateInterface $form_state): array {
    if (isset($element['#theme']) && $element['#theme'] === 'field_multiple_value_form') {
      if (!isset($element['#attributes']) || !is_array($element['#attributes'])) {
        $element['#attributes'] = [];
      }
      if (!empty($element['#description']) && empty($element['#attributes']['aria-describedby'])) {
        $element['#attributes']['aria-describedby'] = Html::getUniqueId(
          $element['#field_name'] . '-description'
        );
      }
      foreach (Element::children($element) as $key) {
        if ($key !== 'add_more' && is_array($element[$key] ?? NULL)) {
          $child = &$element[$key];
          if (isset($child['_weight']) && is_array($child['_weight'])) {
            if (!isset($child['_weight']['#attributes']) || !is_array($child['_weight']['#attributes'])) {
              $child['_weight']['#attributes'] = [];
            }
          }
        }
      }
    }
    foreach (Element::children($element) as $key) {
      if (is_array($element[$key] ?? NULL)) {
        $element[$key] = static::ensureFieldAttributes($element[$key], $form_state);
      }
    }
    return $element;
  }

  /**
   * Gets the next step after the given step.
   *
   * @return array{label: string, route: string}|null
   *   Next step definition or NULL if at last step.
   */
  protected function getNextStep(string $current_step_id): ?array {
    $step_ids = array_keys(self::STEPS);
    $current_index = array_search($current_step_id, $step_ids, TRUE);
    if ($current_index === FALSE || $current_index >= count($step_ids) - 1) {
      return NULL;
    }
    return self::STEPS[$step_ids[$current_index + 1]] ?? NULL;
  }

}
