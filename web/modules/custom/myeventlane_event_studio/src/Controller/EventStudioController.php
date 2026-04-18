<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_event_studio\Form\EventStudioForm;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Event Studio pages. Route methods are buildCreate / buildEdit (not create — conflicts with ControllerBase::create()).
 */
final class EventStudioController extends ControllerBase {

  public function buildCreate(): array {
    $this->getLogger('myeventlane_event_studio')->notice('Vendor Event Studio entry: route=myeventlane_event_studio.create studio_selected=1 uid=@uid', [
      '@uid' => (string) $this->currentUser()->id(),
    ]);
    $form = $this->formBuilder()->getForm(EventStudioForm::class);
    // Replace core's form.html.twig wrapper; mel-event-studio.html.twig emits <form>.
    $form['#theme_wrappers'] = [];
    $form['#theme'] = 'mel_event_studio';
    $form['#mel_studio_mode'] = 'create';
    $form['#attached']['library'] ??= [];
    $form['#attached']['library'][] = 'myeventlane_event_studio/mel_event_studio';
    return $form;
  }

  public function buildEdit(NodeInterface $node): RedirectResponse {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    $this->getLogger('myeventlane_event_studio')->notice('Vendor Event Studio entry: route=myeventlane_event_studio.edit redirect=edit_basic event_id=@eid uid=@uid', [
      '@eid' => (string) $node->id(),
      '@uid' => (string) $this->currentUser()->id(),
    ]);
    $url = Url::fromRoute('myeventlane_event_studio.edit_basic', ['node' => $node->id()])->setAbsolute();
    return new RedirectResponse($url->toString());
  }

}
