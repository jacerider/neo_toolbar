<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Plugin\ToolbarBadge;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_toolbar\Attribute\ToolbarBadge;
use Drupal\neo_toolbar\Event\ToolbarBadgeEvent;
use Drupal\neo_toolbar\ToolbarBadgePluginBase;
use Drupal\neo_toolbar\ToolbarItemElement;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Plugin implementation of the neo_toolbar_badge.
 */
#[ToolbarBadge(
  id: 'event',
  label: new TranslatableMarkup('Event'),
  description: new TranslatableMarkup('Use an event to calculate the count for a badge.'),
)]
final class Event extends ToolbarBadgePluginBase implements ContainerFactoryPluginInterface {

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * Creates a toolbar item instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EventDispatcherInterface $event_dispatcher
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getBadge(ToolbarItemElement $element): string|int|null {
    $event = new ToolbarBadgeEvent($element);
    $this->eventDispatcher->dispatch($event, ToolbarBadgeEvent::EVENT_NAME);
    return $event->getBadge();
  }

  /**
   * Configuration form for the toolbar item plugin.
   */
  protected function badgeForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['info'] = [
      '#type' => 'item',
      '#title' => $this->t('How to:'),
      '#markup' => '<div class="description">The \Drupal\neo_toolbar\Event\ToolbarBadgeEvent event is dispatched when a badge is added to the toolbar. You can subscribe to this event and set the badge value. Within the event, ::getElement()->id() can be used to target a specific element.</div>',
    ];
    return $form;
  }

}
