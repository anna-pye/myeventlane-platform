<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_rsvp\Service\RsvpOrganiserViewScope;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Views filter: RSVP rows only for events the current organiser manages.
 *
 * Configured on myeventlane_vendor_rsvps. Query alter also enforces the same
 * scope as defence-in-depth if this filter is removed in the UI.
 */
#[ViewsFilter('myeventlane_rsvp_organiser_owned')]
final class OrganiserOwnedRsvps extends FilterPluginBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   *
   * @var bool
   */
  // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName -- Views API.
  public $no_operator = TRUE;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly RsvpOrganiserViewScope $organiserViewScope,
    private readonly AccountProxyInterface $currentUser,
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
      $container->get('myeventlane_rsvp.organiser_view_scope'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary() {
    return $this->t('Organiser-owned events only');
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state): void {
    // No exposed UI — always applied for the current user.
  }

  /**
   * {@inheritdoc}
   */
  public function canExpose() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    if ($this->query === NULL) {
      return;
    }
    $this->organiserViewScope->applyToViewsQuery($this->query, $this->currentUser);
  }

}
