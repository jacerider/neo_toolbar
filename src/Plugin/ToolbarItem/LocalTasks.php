<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Plugin\ToolbarItem;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\LocalTaskManagerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_toolbar\Attribute\ToolbarItem;
use Drupal\neo_toolbar\ToolbarItemPluginBase;
use Drupal\neo_toolbar\ToolbarItemLinkTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_toolbar_item.
 */
#[ToolbarItem(
  id: 'local_tasks',
  label: new TranslatableMarkup('Local Tasks'),
  description: new TranslatableMarkup('The local tasks of the current page.'),
)]
final class LocalTasks extends ToolbarItemPluginBase {
  use ToolbarItemLinkTrait;

  /**
   * The local task manager.
   *
   * @var \Drupal\Core\Menu\LocalTaskManagerInterface
   */
  protected $localTaskManager;

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Creates a toolbar item instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    TransliterationInterface $transliteration,
    LocalTaskManagerInterface $local_task_manager,
    RouteMatchInterface $route_match
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $transliteration);
    $this->localTaskManager = $local_task_manager;
    $this->routeMatch = $route_match;
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
   * {@inheritdoc}
   */
  public function itemForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::itemForm($form, $form_state, $complete_form);

    $complete_form['label'] = [
      '#type' => 'value',
      '#value' => 'Local Tasks',
    ];

    $complete_form['id'] = [
      '#type' => 'value',
      '#value' => $complete_form['id']['#default_value'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getIcon(): string|null {
    return 'tasks-alt';
  }

  /**
   * {@inheritdoc}
   */
  public function getStyle(): string {
    return 'pill';
  }

  /**
   * {@inheritdoc}
   */
  public function getElements(): array {
    $elements = [];

    $cacheableMetadata = new CacheableMetadata();
    $primary = $this->localTaskManager->getLocalTasks($this->routeMatch->getRouteName(), 0);
    $secondary = $this->localTaskManager->getLocalTasks($this->routeMatch->getRouteName(), 1);
    $cacheableMetadata->addCacheableDependency($this->localTaskManager);
    // If the current route belongs to an entity, include cache tags of that
    // entity as well.
    $route_parameters = $this->routeMatch->getParameters()->all();
    foreach ($route_parameters as $parameter) {
      if ($parameter instanceof CacheableDependencyInterface) {
        $cacheableMetadata->addCacheableDependency($parameter);
      }
    }
    $cacheableMetadata = $cacheableMetadata->merge($primary['cacheability']);
    $cacheableMetadata = $cacheableMetadata->merge($secondary['cacheability']);
    if (count(Element::getVisibleChildren($primary['tabs'])) > 1) {
      foreach ($primary['tabs'] as $primary_tab) {
        $element = $this->getElement();
        $element->setTitle($primary_tab['#link']['title']);
        $element->setDynamicIcon($primary_tab['#link']['title']);
        $element->setAccess($primary_tab['#access']);
        $element->setWeight($primary_tab['#weight']);
        $this->linkProcessElement($element, $primary_tab['#link']['url']);

        if (!empty($primary_tab['#active'])) {
          $element->addClass('is-active');
          if (count(Element::getVisibleChildren($secondary['tabs'])) > 1) {
            $element->setChildrenStyle('pill_nested');
            foreach ($secondary['tabs'] as $secondary_tab) {
              $child = $this->getElement();
              $child->setTitle($secondary_tab['#link']['title']);
              $child->showTitle(FALSE);
              $child->showTooltip(TRUE);
              $child->setIcon($this->configuration['alignment'] === 'vertical' ? 'circle' : '');
              $child->setDynamicIcon($secondary_tab['#link']['title']);
              $child->setAccess($secondary_tab['#access']);
              $child->setWeight($secondary_tab['#weight']);
              $this->linkProcessElement($child, $secondary_tab['#link']['url']);
              $element->addChild($child);
            }
          }
        }
        $elements[] = $element;
      }
    }
    $this->addCacheableDependency($cacheableMetadata);

    return $elements;
  }

}
