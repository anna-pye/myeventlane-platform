<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\RendererInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders the shared MEL HTML email shell and applies it in hook_mail_alter.
 *
 * All transactional copy should stay in hook_mail or templates; this service
 * only provides layout, typography, and brand chrome without duplicating
 * per-feature mail logic.
 */
final class MelEmailLayoutService {

  /**
   * Attribute placed on the outer table so we do not double-wrap.
   */
  public const LAYOUT_ROOT_MARKER = 'data-mel-email-layout';

  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Wraps a mail message body in the mel_email_base theme, when appropriate.
   *
   * @param array $message
   *   The message array from hook_mail_alter.
   */
  public function applyLayoutToMessage(array &$message): void {
    if (!empty($message['params']['mel_email_no_layout'])) {
      return;
    }
    if (!is_array($message)) {
      return;
    }
    $b = $message['body'] ?? NULL;
    if ($b === NULL) {
      return;
    }
    if (is_string($b) && trim($b) === '') {
      return;
    }
    if (is_array($b) && $b === []) {
      return;
    }

    $raw = $this->bodyToString($message['body'] ?? '');
    if ($this->shouldSkipLayout($raw)) {
      return;
    }

    $content = $this->htmlOrPlainToInnerHtml($raw);
    if ($content === '') {
      return;
    }

    $preheader = '';
    if (!empty($message['params']['preheader']) && is_string($message['params']['preheader'])) {
      $preheader = $message['params']['preheader'];
    }
    if ($preheader === '' && !empty($message['params']['site_name']) && is_string($message['params']['site_name'])) {
      $preheader = $message['params']['site_name'];
    }

    $context = $this->mergeContext($message);
    if ($preheader !== '' && !isset($context['preheader'])) {
      $context['preheader'] = $preheader;
    }

    $build = [
      '#theme' => 'mel_email_base',
      '#content' => $content,
      '#preheader' => $context['preheader'] ?? $preheader,
      '#context' => $context,
    ];

    try {
      $wrapped = (string) $this->renderer->renderInIsolation($build);
    }
    catch (\Throwable $e) {
      $this->logger->error('MEL email layout render failed: @m', ['@m' => $e->getMessage()]);
      return;
    }

    $message['body'] = [$wrapped];
    if (!isset($message['headers']) || !is_array($message['headers'])) {
      $message['headers'] = [];
    }
    $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
    if (!is_array($message['params'] ?? NULL)) {
      $message['params'] = [];
    }
    $message['params']['mel_email_layout_applied'] = TRUE;
  }

  /**
   * Merges mel_brand and loose params into a single context for the base theme.
   *
   * @param array $message
   *   The mail message.
   *
   * @return array
   *   Context for mel_email_base (from_name, footer, accent, logo, etc.).
   */
  public function mergeContextFromMessage(array $message): array {
    return $this->mergeContext($message);
  }

  /**
   * @return array<string, mixed>
   */
  private function mergeContext(array $message): array {
    $params = $message['params'] ?? [];
    if (!is_array($params)) {
      $params = [];
    }
    $out = is_array($params['context'] ?? NULL) ? $params['context'] : [];
    $brand = is_array($params['mel_brand'] ?? NULL) ? $params['mel_brand'] : [];

    foreach ($brand as $k => $v) {
      if (!array_key_exists($k, $out) || (string) ($out[$k] ?? '') === '') {
        $out[$k] = $v;
      }
    }
    if (!isset($out['from_name']) || (string) $out['from_name'] === '') {
      $out['from_name'] = (string) ($params['from_name'] ?? 'MyEventLane');
    }
    if (!isset($out['footer_text']) || (string) $out['footer_text'] === '') {
      $out['footer_text'] = (string) ($params['site_name'] ?? '') !== ''
        ? sprintf("You're receiving this because you have an account at %s.", (string) $params['site_name'])
        : "You're receiving this because you interacted with MyEventLane.";
    }
    if (!isset($out['accent_color']) || (string) $out['accent_color'] === '') {
      $out['accent_color'] = (string) ($brand['accent_color'] ?? '#f26d5b');
    }
    if (!empty($out['unsubscribe_url'])) {
      $out['unsubscribe_url'] = (string) $out['unsubscribe_url'];
    }
    if (!empty($out['preheader']) && is_string($out['preheader'])) {
      $out['preheader'] = $out['preheader'];
    }
    if (!empty($out['logo_url']) && is_string($out['logo_url'])) {
      $out['logo_url'] = $out['logo_url'];
    }
    if (!empty($out['marketing']) && is_array($out['marketing'])) {
      $out['marketing'] = $out['marketing'];
    }

    return $out;
  }

  private function shouldSkipLayout(string $raw): bool {
    $s = trim($raw);
    if ($s === '') {
      return TRUE;
    }
    if (str_contains($s, self::LAYOUT_ROOT_MARKER)) {
      return TRUE;
    }
    if (str_contains($s, 'data-mel-wrapper="1"') || str_contains($s, "data-mel-wrapper='1'")) {
      // Legacy myeventlane_email / messaging wrapper (superseded by LAYOUT_ROOT_MARKER).
      return TRUE;
    }
    if (preg_match('/<html[^>]*\sxmlns/i', $s) === 1) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * @param string|list<string|int|float|\Stringable> $body
   */
  private function bodyToString(mixed $body): string {
    if (is_string($body)) {
      return $body;
    }
    if (!is_array($body)) {
      return (string) $body;
    }
    $out = [];
    foreach ($body as $part) {
      if ($part instanceof MarkupInterface) {
        $out[] = (string) $part;
      }
      else {
        $out[] = (string) $part;
      }
    }
    return implode("\n", $out);
  }

  /**
   * If input looks like an HTML fragment, return as-is (sanitized for layout safety).
   */
  private function htmlOrPlainToInnerHtml(string $raw): string {
    $trim = trim($raw);
    if ($trim === '') {
      return '';
    }
    if ($this->isLikelyHtml($trim)) {
      return $trim;
    }
    $escaped = Html::escape($trim);
    $paragraphs = preg_split("/\r\n\r\n|\n\n/", $escaped, -1, PREG_SPLIT_NO_EMPTY);
    if ($paragraphs === FALSE) {
      return '<p class="mel-body" style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#293241;">' . nl2br($escaped) . '</p>';
    }
    $blocks = [];
    foreach ($paragraphs as $p) {
      $blocks[] = '<p class="mel-body" style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#293241;">' . nl2br(trim((string) $p)) . '</p>';
    }
    return implode("\n", $blocks);
  }

  private function isLikelyHtml(string $s): bool {
    if (preg_match('/<[a-z][\s\S]*>/i', $s) === 1) {
      return TRUE;
    }
    return FALSE;
  }

}
