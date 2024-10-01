<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Plugin\ToolbarItem;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\LocalActionManagerInterface;
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
  id: 'local_actions',
  label: new TranslatableMarkup('Local Actions'),
  description: new TranslatableMarkup('The local actions of the current page.'),
)]
final class LocalActions extends ToolbarItemPluginBase {
  use ToolbarItemLinkTrait;

  /**
   * The local task manager.
   *
   * @var \Drupal\Core\Menu\LocalActionManagerInterface
   */
  protected $localActionManager;

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
    LocalActionManagerInterface $local_task_manager,
    RouteMatchInterface $route_match
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $transliteration);
    $this->localActionManager = $local_task_manager;
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
      $container->get('plugin.manager.menu.local_action'),
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
      '#value' => 'Local Actions',
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
    return 'bahai';
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

    $route_name = $this->routeMatch->getRouteName();
    $local_actions = $this->localActionManager->getActionsForRoute($route_name);
    $cacheableMetadata = CacheableMetadata::createFromRenderArray($local_actions);

    foreach (Element::children($local_actions) as $key) {
      $action = $local_actions[$key];
      $element = $this->getElement();
      $element->setTitle($action['#link']['title']);
      $element->setAccess($action['#access']);
      $element->setWeight($action['#weight'] ?? 0);
      $element->setDynamicIcon($action['#link']['title']);
      $url = $action['#link']['url'];
      if (!empty($action['#link']['localized_options']['query'])) {
        $url->setOption('query', $action['#link']['localized_options']['query']);
      }
      $this->linkProcessElement($element, $url);
      $elements[] = $element;
    }

    $this->addCacheableDependency($cacheableMetadata);
    return $elements;
  }

}
