<?php

declare(strict_types=1);

namespace Drupal\myeventlane_support\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_help_assistant\Service\HelpContextResolver;
use Drupal\node\NodeInterface;
use Drupal\views\Views;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unified support page and JSON search for the Help Centre view.
 */
final class SupportController extends ControllerBase {

  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
    private readonly HelpContextResolver $helpContextResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('renderer'),
      $container->get('request_stack'),
      $container->get('logger.factory')->get('myeventlane_support'),
      $container->get('myeventlane_help_assistant.context_resolver'),
    );
  }

  /**
   * Renders the unified support hub.
   */
  public function page(): array {
    $request = $this->requestStack->getCurrentRequest();
    $event_param = $request?->query->get('event');
    $event_id = is_numeric($event_param) ? (int) $event_param : NULL;

    $full_context = $this->helpContextResolver->resolveSupportHubContext($event_id);
    $page_context = $this->helpContextResolver->exportClientEchoPayload($full_context);

    $build = [
      '#theme' => 'myeventlane_support_page',
      '#attached' => [
        'library' => [
          'myeventlane_support/support',
        ],
        'drupalSettings' => [
          'myeventlaneSupport' => [
            'searchEndpoint' => Url::fromRoute('myeventlane_support.search_api')->toString(),
            'assistantEndpoint' => Url::fromRoute('myeventlane_help_assistant.ask')->toString(),
            'helpHomeUrl' => Url::fromRoute('myeventlane_help_centre.home')->toString(),
            'pageContext' => $page_context,
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['user', 'url.query_args:event'],
        'tags' => ['node_list:help_article'],
      ],
    ];
    $build['contact_form'] = $this->buildSiteContactForm();

    return $build;
  }

  /**
   * Returns help article search hits for the unified support UI.
   */
  public function searchApi(Request $request): JsonResponse {
    $raw = (string) ($request->query->get('q') ?? '');
    $query = trim($raw);
    if ($query === '') {
      return new JsonResponse(['results' => []]);
    }
    if (mb_strlen($query) > 500) {
      $query = mb_substr($query, 0, 500);
    }

    $view = Views::getView('mel_help_search');
    if ($view === NULL) {
      $this->logger->error('Support search API: mel_help_search view is not available.');
      return new JsonResponse(['results' => [], 'error' => 'search_unavailable'], 503);
    }

    $view->setDisplay('block_search');
    $view->setExposedInput(['q' => $query]);
    $view->execute();

    $results = [];
    foreach ($view->result as $row) {
      $node = $row->_entity ?? NULL;
      if (!$node instanceof NodeInterface) {
        continue;
      }
      if (!$node->access('view')) {
        continue;
      }
      $results[] = [
        'title' => $node->label(),
        'url' => $node->toUrl()->setAbsolute(FALSE)->toString(),
      ];
      if (count($results) >= 12) {
        break;
      }
    }

    $response = new JsonResponse(['results' => $results]);
    $response->setMaxAge(0);
    $response->headers->addCacheControlDirective('no-store');
    return $response;
  }

  /**
   * Builds the configured site-wide contact message form, if available.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function buildSiteContactForm(): array {
    if (!$this->moduleHandler()->moduleExists('contact')) {
      return [
        '#markup' => '<p>' . $this->t('The contact form is not available.') . '</p>',
      ];
    }

    $config = $this->config('contact.settings');
    $default_form = $config->get('default_form');
    if ($default_form === NULL || $default_form === '') {
      $this->logger->notice('Support page: no default contact form configured.');
      return [
        '#markup' => '<p>' . $this->t('Contact is not configured. Please try again later.') . '</p>',
      ];
    }

    $contact_form = $this->entityTypeManager()->getStorage('contact_form')->load($default_form);
    if ($contact_form === NULL) {
      $this->logger->error('Support page: default contact form @id is missing.', ['@id' => (string) $default_form]);
      return [
        '#markup' => '<p>' . $this->t('Contact is not configured. Please try again later.') . '</p>',
      ];
    }

    $message = $this->entityTypeManager()->getStorage('contact_message')->create([
      'contact_form' => $contact_form->id(),
    ]);

    $form = $this->entityFormBuilder()->getForm($message);
    $form['#title'] = $contact_form->label();
    $form['#cache']['contexts'][] = 'user.permissions';
    $this->renderer->addCacheableDependency($form, $config);
    $form['#cache']['max-age'] = 0;

    return $form;
  }

}
