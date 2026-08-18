<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Plugin\ToolbarItem;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Menu\LocalTaskManagerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\neo_toolbar\ToolbarItemElement;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\neo_toolbar\Attribute\ToolbarItem;
use Drupal\neo_toolbar\ToolbarItemCollection;

/**
 * Plugin implementation of the neo_toolbar_item.
 */
#[ToolbarItem(
  id: 'link_local_tasks',
  label: new TranslatableMarkup('Link: Local Tasks'),
  description: new TranslatableMarkup('Render the local tasks of the linked page in a modal.'),
)]
final class LinkLocalTasks extends Link {

  /**
   * The local task manager.
   *
   * @var \Drupal\Core\Menu\LocalTaskManagerInterface
   */
  protected $localTaskManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected CurrentRouteMatch $currentRouteMatch;

  /**
   * Creates a toolbar item instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    TransliterationInterface $transliteration,
    ?LocalTaskManagerInterface $local_task_manager = NULL,
    ?CurrentRouteMatch $current_route_match = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $transliteration);
    $this->localTaskManager = $local_task_manager;
    $this->currentRouteMatch = $current_route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('transliteration'),
      $container->get('plugin.manager.menu.local_task'),
      $container->get('current_route_match'),
    );
  }

  /**
   * Get the element that makes up the toolbar item.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemElement
   *   The toolbar item element.
   */
  protected function getElement(): ToolbarItemElement {
    $element = parent::getElement();
    $uri = $this->configuration['url'] ?? NULL;
    $currentRouteName = $this->currentRouteMatch->getRouteName();
    try {
      $url = Url::fromUri($uri);
      $route_name = $url->getRouteName();
      $primary = $this->localTaskManager->getLocalTasks($route_name);
      $collection = new ToolbarItemCollection($element->getAlignment(), 'modal');
      if (count(Element::getVisibleChildren($primary['tabs'])) > 1) {
        foreach ($primary['tabs'] as $key => $primary_tab) {
          /** @var \Drupal\Core\Url $tabUrl */
          $tabUrl = $primary_tab['#link']['url'];
          $itemElement = new ToolbarItemElement('link_local_tasks_' . $key, $primary_tab['#link']['title'], 'horizontal');
          $this->linkProcessElement($itemElement, $tabUrl);
          if (!empty($primary_tab['#link']['localized_options']['icon'])) {
            $itemElement->setIcon($primary_tab['#link']['localized_options']['icon']);
          }
          else {
            $itemElement->setDynamicIcon($primary_tab['#link']['title']);
          }
          $itemElement->setWeight($primary_tab['#weight']);
          if ($currentRouteName === $tabUrl->getRouteName()) {
            $this->linkProcessElement($element, $tabUrl);
          }
          $collection->add($itemElement);
        }
      }
      if (!$collection->isEmpty()) {
        $build = $collection->toRenderable();
        $element->setModal($build, $this->configuration['title'], ['class' => ['px-3']]);
      }
    }
    catch (\Exception $e) {
      // Ignore invalid URLs.
    }
    return $element;
  }

}
