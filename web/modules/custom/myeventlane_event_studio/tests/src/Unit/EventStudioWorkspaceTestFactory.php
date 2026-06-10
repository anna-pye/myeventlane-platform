<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormAjaxException;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_event\Service\TicketProductEventOwnershipService;
use Drupal\myeventlane_event\Service\TicketTypeManager;
use Drupal\myeventlane_event_studio\Service\EventReadinessService;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\myeventlane_event_studio\Service\EventStudioEmptyStateBuilder;
use Drupal\myeventlane_event_studio\Service\EventStudioQuestionAnswerExistenceRepository;
use Drupal\myeventlane_event_studio\Service\EventStudioQuestionTemplateManager;
use Drupal\myeventlane_event_studio\Service\EventStudioSectionRenderer;
use Drupal\myeventlane_event_studio\Service\QuestionFieldTypeRegistry;
use Drupal\myeventlane_event_studio\Support\MelSupportResolverInterface;
use Drupal\myeventlane_vendor\Service\PaidPublishStripeGate;
use Drupal\myeventlane_vendor\Service\VendorPublishRequirementsGate;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds real Event Studio services for workspace failure fallback unit tests.
 */
final class EventStudioWorkspaceTestFactory {

  /**
   * @param callable(class-string): object $createMock
   */
  public static function readinessService(TranslationInterface $translator, callable $createMock): EventReadinessService {
    /** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager */
    $entityTypeManager = $createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')->willReturn(FALSE);

    /** @var \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory */
    $loggerFactory = $createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn(new TestLoggerChannel());

    $ticketTypeManager = new TicketTypeManager(
      $entityTypeManager,
      $loggerFactory,
      (new ReflectionClass(TicketProductEventOwnershipService::class))->newInstanceWithoutConstructor(),
    );

    $registry = new QuestionFieldTypeRegistry();
    $registry->setStringTranslation($translator);

    $questionManager = new EventStudioQuestionTemplateManager(
      $entityTypeManager,
      new TestLoggerChannel(),
      $registry,
      $createMock(EventStudioQuestionAnswerExistenceRepository::class),
    );

    return new EventReadinessService(
      $ticketTypeManager,
      (new ReflectionClass(VendorPublishRequirementsGate::class))->newInstanceWithoutConstructor(),
      (new ReflectionClass(PaidPublishStripeGate::class))->newInstanceWithoutConstructor(),
      $questionManager,
      new TestLoggerChannel(),
      $translator,
    );
  }

  /**
   * @param callable(class-string): object $createMock
   */
  public static function throwingSectionRenderer(TranslationInterface $translator, callable $createMock): EventStudioSectionRenderer {
    /** @var \Drupal\Core\Form\FormBuilderInterface $formBuilder */
    $formBuilder = $createMock(FormBuilderInterface::class);
    $formBuilder->method('getForm')
      ->willThrowException(new RuntimeException('Simulated section render failure'));

    /** @var \Drupal\myeventlane_event_studio\Support\MelSupportResolverInterface $supportResolver */
    $supportResolver = $createMock(MelSupportResolverInterface::class);
    $supportResolver->method('buildCard')->willReturn(NULL);

    /** @var \Drupal\Core\Session\AccountProxyInterface $currentUser */
    $currentUser = $createMock(AccountProxyInterface::class);

    $renderer = new EventStudioSectionRenderer(
      $formBuilder,
      self::readinessService($translator, $createMock),
      new EventStudioEmptyStateBuilder($translator),
      $currentUser,
      new TestLoggerChannel(),
      $translator,
      $supportResolver,
    );
    $renderer->setStringTranslation($translator);
    return $renderer;
  }

  /**
   * @param callable(class-string): object $createMock
   */
  public static function formAjaxThrowingSectionRenderer(TranslationInterface $translator, callable $createMock): EventStudioSectionRenderer {
    /** @var \Drupal\Core\Form\FormBuilderInterface $formBuilder */
    $formBuilder = $createMock(FormBuilderInterface::class);
    $formBuilder->method('getForm')
      ->willThrowException(new FormAjaxException([], new FormState()));

    /** @var \Drupal\myeventlane_event_studio\Support\MelSupportResolverInterface $supportResolver */
    $supportResolver = $createMock(MelSupportResolverInterface::class);
    $supportResolver->method('buildCard')->willReturn(NULL);

    /** @var \Drupal\Core\Session\AccountProxyInterface $currentUser */
    $currentUser = $createMock(AccountProxyInterface::class);

    $renderer = new EventStudioSectionRenderer(
      $formBuilder,
      self::readinessService($translator, $createMock),
      new EventStudioEmptyStateBuilder($translator),
      $currentUser,
      new TestLoggerChannel(),
      $translator,
      $supportResolver,
    );
    $renderer->setStringTranslation($translator);
    return $renderer;
  }

  /**
   * @param callable(class-string): object $createMock
   */
  public static function autosaveService(callable $createMock): EventStudioAutosaveService {
    /** @var \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory */
    $tempStoreFactory = $createMock(PrivateTempStoreFactory::class);
    /** @var \Drupal\Core\TempStore\PrivateTempStore $tempStore */
    $tempStore = $createMock(\Drupal\Core\TempStore\PrivateTempStore::class);
    $tempStore->method('get')->willReturn(NULL);
    $tempStoreFactory->method('get')->willReturn($tempStore);

    return new EventStudioAutosaveService($tempStoreFactory, new TestLoggerChannel());
  }

  /**
   * @param callable(class-string): object $createMock
   */
  public static function domainDetector(callable $createMock): DomainDetector {
    $requestStack = new RequestStack();
    /** @var \Drupal\Core\Config\ConfigFactoryInterface $configFactory */
    $configFactory = $createMock(ConfigFactoryInterface::class);
    /** @var \Drupal\Core\Config\ImmutableConfig $config */
    $config = $createMock(\Drupal\Core\Config\ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $configFactory->method('get')->willReturn($config);

    return new DomainDetector($requestStack, $configFactory);
  }

}
