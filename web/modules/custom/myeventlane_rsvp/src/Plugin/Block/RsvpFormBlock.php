<?php

namespace Drupal\myeventlane_rsvp\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * RSVP form block for event pages.
 *
 * @Block(
 *   id = "mel_rsvp_form_block",
 *   admin_label = @Translation("MyEventLane RSVP Form")
 * )
 */
final class RsvpFormBlock extends BlockBase {

  /**
   * Constructs RsvpFormBlock.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The form builder.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly RouteMatchInterface $routeMatch,
    private readonly FormBuilderInterface $formBuilder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('form_builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // The unified Commerce book page already embeds the RSVP flow (RsvpPublicForm
    // or RsvpBookingForm). Rendering this block there duplicates field names such as
    // legal_consent[customer_terms_agreed]. PHP merges duplicate keys in $_POST; the
    // browser may submit the block's unchecked control last, wiping the main form's
    // checked value and causing false "must agree" errors and an unchecked box on rebuild.
    if ($this->routeMatch->getRouteName() === 'myeventlane_commerce.event_book') {
      return [
        '#markup' => '',
        '#cache' => [
          'contexts' => ['route'],
        ],
      ];
    }

    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface && $node->bundle() === 'event') {
      return $this->formBuilder->getForm(
        '\Drupal\myeventlane_rsvp\Form\RsvpPublicForm',
        $node
      );
    }

    return ['#markup' => ''];
  }

}
