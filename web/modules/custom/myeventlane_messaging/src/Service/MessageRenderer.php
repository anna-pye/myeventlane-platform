<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\Config;
use Drupal\Core\Render\RendererInterface;
use Twig\Environment;

/**
 * Renders messaging templates.
 *
 * - Subjects are rendered as Twig strings (no theme layer, no debug wrappers).
 * - Bodies are rendered as Twig strings and then wrapped with a theme template.
 */
final class MessageRenderer {

  /**
   * Constructs a MessageRenderer.
   *
   * @param \Twig\Environment $twig
   *   The Twig environment.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(
    private readonly Environment $twig,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * Renders a Twig string with the given context.
   *
   * @param string $template
   *   The Twig template string.
   * @param array $context
   *   Context variables.
   *
   * @return string
   *   The rendered string.
   */
  public function renderString(string $template, array $context = []): string {
    try {
      $tpl = $this->twig->createTemplate($template);
      return trim((string) $tpl->render($context));
    }
    catch (\Throwable $e) {
      \Drupal::logger('myeventlane_messaging')->error('Twig string render failed: @msg', ['@msg' => $e->getMessage()]);
      return $template;
    }
  }

  /**
   * Renders the HTML email body inside the themed wrapper.
   *
   * @param \Drupal\Core\Config\Config $conf
   *   Template config containing a `body_html` Twig string.
   * @param array $context
   *   Context variables.
   * @param string|null $bodyHtmlOverride
   *   Optional override template string (e.g. from A/B variant).
   *
   * @return string
   *   The rendered HTML.
   */
  public function renderHtmlBody(Config $conf, array $context = [], ?string $bodyHtmlOverride = NULL): string {
    $inner = '';
    $body_tpl = $bodyHtmlOverride !== NULL && $bodyHtmlOverride !== ''
      ? $bodyHtmlOverride
      : (string) ($conf->get('body_html') ?? $conf->get('body') ?? '');

    if ($body_tpl !== '') {
      try {
        $inner = $this->twig->createTemplate($body_tpl)->render($context);
      }
      catch (\Throwable $e) {
        \Drupal::logger('myeventlane_messaging')->error('Twig body render failed: @msg', ['@msg' => $e->getMessage()]);
        $inner = $body_tpl;
      }
    }

    $build = [
      '#theme' => 'myeventlane_email',
      '#body' => $inner,
      '#context' => $context,
    ];

    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * Renders SMS text from template config (no delivery).
   *
   * @param \Drupal\Core\Config\Config $conf
   *   Template config containing optional sms_text Twig string.
   * @param array $context
   *   Context variables.
   * @param string|null $override
   *   Optional override template string (e.g. from A/B variant).
   *
   * @return string
   *   Plain text, stripped of HTML and trimmed.
   */
  public function renderSmsText(Config $conf, array $context, ?string $override = NULL): string {
    $tpl = $override ?? (string) ($conf->get('sms_text') ?? '');
    if ($tpl === '') {
      return '';
    }
    return trim(strip_tags(Html::decodeEntities(
      $this->renderString($tpl, $context)
    )));
  }

}
