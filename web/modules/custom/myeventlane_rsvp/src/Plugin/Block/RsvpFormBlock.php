<?php

namespace Drupal\myeventlane_rsvp\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Path\CurrentPathStack;
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
   *   The block configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The form builder.
   * @param \Drupal\Core\Path\CurrentPathStack $currentPath
   *   The current request path (internal path, language-neutral).
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly RouteMatchInterface $routeMatch,
    private readonly FormBuilderInterface $formBuilder,
    private readonly CurrentPathStack $currentPath,
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
      $container->get('path.current'),
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
    if ($this->shouldSuppressForUnifiedBookPage()) {
      return $this->emptyBuild();
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

  /**
   * Whether this block must not render (book page already has the form).
   */
  private function shouldSuppressForUnifiedBookPage(): bool {
    if ($this->routeMatch->getRouteName() === 'myeventlane_commerce.event_book') {
      return TRUE;
    }
    // Fallback when route name differs (cache edge cases, contrib overrides): internal path.
    $path = $this->currentPath->getPath();
    return (bool) preg_match('#^/event/\d+/book$#', $path);
  }

  /**
   * Cacheable empty placeholder so the block does not duplicate the book form.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function emptyBuild(): array {
    return [
      '#markup' => '',
      '#cache' => [
        'contexts' => ['route', 'url.path'],
      ],
    ];
  }

}
