<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_help_centre\Unit;

use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_help_centre\Service\HelpJourneyLinks;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[Group('myeventlane_help_centre')]
final class HelpJourneyLinksTest extends UnitTestCase {

  public function testUnavailableArticleFallsBackToSearch(): void {
    $generator = $this->createMock(UrlGeneratorInterface::class);
    $generator->method('generateFromRoute')->willReturnCallback(function ($route, $parameters, $options) {
      self::assertSame('myeventlane_help_centre.search', $route);
      self::assertSame([], $parameters);
      return '/help/search?q=' . rawurlencode($options['query']['q']);
    });
    $container = new ContainerBuilder();
    $container->set('url_generator', $generator);
    \Drupal::setContainer($container);
    $validator = $this->createMock(PathValidatorInterface::class);
    $validator->method('getUrlIfValid')->willReturn(FALSE);
    $service = new HelpJourneyLinks($validator);
    $service->setStringTranslation($this->getStringTranslationStub());
    $topics = $service->topics();
    self::assertCount(6, $topics);
    self::assertSame('/help/search?q=missing%20ticket', $topics['tickets']['url']);
  }

}
